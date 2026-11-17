<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\EventSubscriber;

use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\TimeTrackBundle\Client\ClientResponseFactory;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

use function is_string;
use function max;
use function str_starts_with;

/**
 * 1.27.0 (Beacon spec 239): a storage failure of a TimeTrack route answers 409 or 503 instead of 500.
 *
 * - transient ({@see TransientStorageFailure} of the bundle's runner, or a DBAL {@see RetryableException}: deadlock,
 *   lock wait timeout): the status of the shared {@see ConflictResponsePolicy} (since 1.30.0 `409 Conflict`, was 503;
 *   `nowo_transaction_doctrine.conflict_response.status: 503` keeps 503) with `Retry-After` (the runner's hint, else
 *   the policy's). A {@see ConcurrencyConflict} that already committed (`committed: true`) gets the same status but
 *   **no** `Retry-After` and a "reload" message: a blind retry could repeat the part that was saved;
 * - conflict (a unique key refused the write of a concurrent request): `409 Conflict`.
 *
 * The client API (desktop agent, browser extension, calendar, meetings, monitoring, working time) answers its usual
 * error body, `{"error": "unavailable" | "conflict", "message": …}`, with its CORS headers and `Cache-Control: no-store`;
 * the HTML pages (manage UI, device verification) get an {@see HttpException} of the policy /
 * {@see ConflictHttpException}, rendered by the application's error page. The storage error stays the previous
 * exception (for the logs): no SQL or parameter reaches the client.
 *
 * Only the bundle's routes: a controller of the bundle (the host may rename the routes) or a `nowo_time_track*` route.
 * Priority -64: after the application's own exception listeners, before the framework's error listener (-128).
 *
 * Stateless.
 */
final readonly class StorageFailureSubscriber implements EventSubscriberInterface
{
    /** Controller namespace of the bundle. */
    public const CONTROLLER_PREFIX = 'Nowo\\TimeTrackBundle\\Controller\\';

    /** Route name prefix of the bundle. */
    public const ROUTE_PREFIX = 'nowo_time_track';

    /** Controllers that render HTML pages; every other bundle controller is a JSON API. */
    private const HTML_CONTROLLERS = [self::CONTROLLER_PREFIX . 'TimeTrackManageController', self::CONTROLLER_PREFIX . 'TimeTrackDeviceVerificationController'];

    /** Answer text of a transient failure that did not commit. */
    public const UNAVAILABLE_MESSAGE = 'The service is temporarily unavailable. Retry later.';

    /** Answer text of a conflict that already committed part of the write: no blind retry. */
    public const PARTIAL_MESSAGE = 'Part of your change may have been saved. Reload before trying again.';

    /**
     * @param ClientResponseFactory|null $responses The client API's JSON answers (CORS headers); a plain JSON body without it
     * @param ConflictResponsePolicy|null $policy The shared lock-conflict policy (status, Retry-After); 409 + 1 s when the host did not register nowo-tech/transaction-doctrine's bundle
     */
    public function __construct(private ?ClientResponseFactory $responses = null, private ?ConflictResponsePolicy $policy = null)
    {
    }

    /**
     * Listens on kernel.exception at -64.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', -64],
        ];
    }

    /**
     * Maps a transient storage failure to 503 and a unique-key conflict to 409 on the bundle's routes.
     */
    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!self::isBundleRequest($request)) {
            return;
        }

        $failure = $event->getThrowable();
        $hint    = $this->retryAfter($failure);
        if ($hint !== null) {
            $policy    = $this->policy ?? new ConflictResponsePolicy();
            $committed = self::committed($failure);
            $message   = $committed ? self::PARTIAL_MESSAGE : self::UNAVAILABLE_MESSAGE;
            if (self::wantsJson($request)) {
                $event->setResponse($this->json($request, 'unavailable', $message, $policy->status, $committed ? [] : $policy->headers($hint)));

                return;
            }
            $event->setThrowable($committed
                ? new HttpException($policy->status, $message, $failure)
                : $policy->httpException($message, $failure, $hint));

            return;
        }

        if ($this->isConflict($failure)) {
            if (self::wantsJson($request)) {
                $event->setResponse($this->json($request, 'conflict', 'A concurrent request changed the same data. Reload and retry.', Response::HTTP_CONFLICT));

                return;
            }
            $event->setThrowable(new ConflictHttpException('A concurrent request changed the same data. Reload and retry.', $failure));
        }
    }

    /**
     * Whether the chain holds a {@see ConcurrencyConflict} whose attempt already committed (no safe retry).
     */
    public static function committed(Throwable $failure): bool
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof ConcurrencyConflict && $current->committed) {
                return true;
            }
        }

        return false;
    }

    /**
     * The Retry-After hint (seconds) of a transient failure (anywhere in the chain); null when the failure is not transient.
     */
    private function retryAfter(Throwable $failure): ?int
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof TransientStorageFailure) {
                $hint = $current->retryAfterSeconds();

                return $hint !== null ? max(1, $hint) : $this->fallbackHint();
            }
            if ($current instanceof RetryableException) {
                return $this->fallbackHint();
            }
        }

        return null;
    }

    /**
     * Whether a unique key refused the write (anywhere in the chain).
     */
    /**
     * The policy's own Retry-After, as the hint of a failure that carries none.
     */
    private function fallbackHint(): int
    {
        return ($this->policy ?? new ConflictResponsePolicy())->retryAfterSeconds;
    }

    private function isConflict(Throwable $failure): bool
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof UniqueConstraintViolationException) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the request is one of the bundle's (a controller of the bundle, or a `nowo_time_track*` route).
     */
    public static function isBundleRequest(Request $request): bool
    {
        $controller = $request->attributes->get('_controller');
        if (is_string($controller) && str_starts_with($controller, self::CONTROLLER_PREFIX)) {
            return true;
        }
        $route = $request->attributes->get('_route');

        return is_string($route) && str_starts_with($route, self::ROUTE_PREFIX);
    }

    /**
     * Whether the bundle answers this request in JSON (the client API, or a page asked for JSON).
     */
    public static function wantsJson(Request $request): bool
    {
        $controller = $request->attributes->get('_controller');
        if (is_string($controller) && str_starts_with($controller, self::CONTROLLER_PREFIX)) {
            foreach (self::HTML_CONTROLLERS as $prefix) {
                if (str_starts_with($controller, $prefix)) {
                    return $request->getPreferredFormat() === 'json';
                }
            }

            return true;
        }

        return $request->getPreferredFormat() === 'json';
    }

    /**
     * @param array<string, string> $headers
     */
    private function json(Request $request, string $error, string $message, int $status, array $headers = []): JsonResponse
    {
        $response = $this->responses instanceof ClientResponseFactory
            ? $this->responses->error($error, $message, $status, $request, $headers)
            : new JsonResponse(['error' => $error, 'message' => $message], $status, $headers);
        // @igor-ignore - Request-scoped value (the response of this exception); no worker state.
        $response->headers->set('Cache-Control', 'no-store, private');
        // @igor-ignore - Request-scoped value (the response of this exception); no worker state.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
