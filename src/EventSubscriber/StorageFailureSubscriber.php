<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\EventSubscriber;

use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\TimeTrackBundle\Client\ClientResponseFactory;
use Nowo\TransactionContract\TransientStorageFailure;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

use function is_string;
use function max;
use function str_starts_with;

/**
 * 1.27.0 (Beacon spec 239): a storage failure of a TimeTrack route answers 503 or 409 instead of 500.
 *
 * - transient ({@see TransientStorageFailure} of the bundle's runner, or a DBAL {@see RetryableException}: deadlock,
 *   lock wait timeout): `503 Service Unavailable` with `Retry-After` (the runner's hint, else 1 s);
 * - conflict (a unique key refused the write of a concurrent request): `409 Conflict`.
 *
 * The client API (desktop agent, browser extension, calendar, meetings, monitoring, working time) answers its usual
 * error body, `{"error": "unavailable" | "conflict", "message": …}`, with its CORS headers and `Cache-Control: no-store`;
 * the HTML pages (manage UI, device verification) get a {@see ServiceUnavailableHttpException} /
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

    /** Retry-After when the failure carries no hint. */
    private const DEFAULT_RETRY_AFTER = 1;

    /**
     * @param ClientResponseFactory|null $responses The client API's JSON answers (CORS headers); a plain JSON body without it
     */
    public function __construct(private ?ClientResponseFactory $responses = null)
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
        if (!$this->isBundleRequest($request)) {
            return;
        }

        $failure = $event->getThrowable();
        $retry   = $this->retryAfter($failure);
        if ($retry !== null) {
            if ($this->wantsJson($request)) {
                $event->setResponse($this->json($request, 'unavailable', 'The service is temporarily unavailable. Retry later.', Response::HTTP_SERVICE_UNAVAILABLE, ['Retry-After' => (string) $retry]));

                return;
            }
            $event->setThrowable(new ServiceUnavailableHttpException($retry, 'The service is temporarily unavailable. Retry later.', $failure));

            return;
        }

        if ($this->isConflict($failure)) {
            if ($this->wantsJson($request)) {
                $event->setResponse($this->json($request, 'conflict', 'A concurrent request changed the same data. Reload and retry.', Response::HTTP_CONFLICT));

                return;
            }
            $event->setThrowable(new ConflictHttpException('A concurrent request changed the same data. Reload and retry.', $failure));
        }
    }

    /**
     * The Retry-After seconds of a transient failure (anywhere in the chain); null when the failure is not transient.
     */
    private function retryAfter(Throwable $failure): ?int
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof TransientStorageFailure) {
                return max(1, $current->retryAfterSeconds() ?? self::DEFAULT_RETRY_AFTER);
            }
            if ($current instanceof RetryableException) {
                return self::DEFAULT_RETRY_AFTER;
            }
        }

        return null;
    }

    /**
     * Whether a unique key refused the write (anywhere in the chain).
     */
    private function isConflict(Throwable $failure): bool
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof UniqueConstraintViolationException) {
                return true;
            }
        }

        return false;
    }

    private function isBundleRequest(Request $request): bool
    {
        $controller = $request->attributes->get('_controller');
        if (is_string($controller) && str_starts_with($controller, self::CONTROLLER_PREFIX)) {
            return true;
        }
        $route = $request->attributes->get('_route');

        return is_string($route) && str_starts_with($route, self::ROUTE_PREFIX);
    }

    private function wantsJson(Request $request): bool
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
