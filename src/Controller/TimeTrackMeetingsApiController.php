<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Controller;

use DateTimeZone;
use Nowo\TimeTrackBundle\Client\ClientApiGuard;
use Nowo\TimeTrackBundle\Client\ClientApiPrincipal;
use Nowo\TimeTrackBundle\Client\ClientApiRateLimiter;
use Nowo\TimeTrackBundle\Client\ClientResponseFactory;
use Nowo\TimeTrackBundle\Client\ClientUserProfileProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientWorkingHoursNormalizer;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Meeting\MeetingContext;
use Nowo\TimeTrackBundle\Meeting\MeetingProviderInterface;
use Nowo\TimeTrackBundle\Meeting\MeetingQueryParser;
use Nowo\TimeTrackBundle\Meeting\MeetingRequestException;
use Nowo\TimeTrackBundle\Meeting\MeetingResponder;
use Nowo\TimeTrackBundle\Meeting\MeetingSettings;
use Nowo\TimeTrackBundle\Meeting\MeetingSyncStatus;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function count;
use function hrtime;
use function implode;
use function intdiv;
use function is_array;
use function json_decode;
use function max;

/**
 * Since 1.14 (contract §22, spec 210 FR-007): `GET /api/time-track/me/meetings?from=&to=&tz=&limit=` — the caller's
 * **own** meetings (there is no user parameter; unknown parameters are refused), minimal fields, from the host
 * {@see MeetingProviderInterface}. Bearer token with the `meetings:read` scope only (explicit grant), 6 / min and
 * 60 / h per token on top of the global limits (429 with an integer `Retry-After`). Every answer, errors included,
 * carries `Cache-Control: no-store`, `Pragma: no-cache`, `Vary: Authorization`, `X-Content-Type-Options: nosniff` and
 * no validators. 404 while the feature is off or the host says `disabled`; 503 `meetings_unavailable` when the provider
 * fails. The bundle stores, caches and logs nothing of a meeting: the access log line has the status, the count and
 * the duration only.
 */
final readonly class TimeTrackMeetingsApiController
{
    private ClockInterface $clock;

    private LoggerInterface $logger;

    /**
     * Receives the client API collaborators and the meeting services.
     */
    public function __construct(
        private ClientApiGuard $guard,
        private ClientResponseFactory $responseFactory,
        private ClientApiRateLimiter $rateLimiter,
        private ClientUserProfileProviderInterface $profileProvider,
        private MeetingContext $context,
        private MeetingQueryParser $parser,
        private MeetingResponder $responder,
        private MeetingSettings $settings,
        ?ClockInterface $clock = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->clock  = $clock ?? new NativeClock();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * GET me/meetings.
     */
    public function meetings(Request $request): Response
    {
        $started  = hrtime(true);
        $response = $this->answer($request);
        $this->logger->info('TimeTrack meetings request.', [
            'bundle'      => 'time_track',
            'action'      => 'client_me_meetings',
            'status'      => $response['response']->getStatusCode(),
            'count'       => $response['count'],
            'dropped'     => $response['dropped'],
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
        ]);

        return $this->secure($response['response']);
    }

    /**
     * @return array{response: Response, count: int, dropped: int}
     */
    private function answer(Request $request): array
    {
        if ($request->isMethod('OPTIONS')) {
            return ['response' => $this->responseFactory->empty(Response::HTTP_NO_CONTENT, $request), 'count' => 0, 'dropped' => 0];
        }
        $provider = $this->context->provider();
        if (!$provider instanceof MeetingProviderInterface) {
            return $this->plain($this->notFound($request));
        }
        $principal = $this->guard->authenticate($request, ClientScope::MeetingsRead);
        if (!$principal instanceof ClientApiPrincipal) {
            return $this->plain($principal);
        }
        $limited = $this->limit($principal, $request);
        if ($limited instanceof Response) {
            return $this->plain($limited);
        }
        try {
            $hours = ClientWorkingHoursNormalizer::normalize($this->profileProvider->getWorkingHours($principal->user));
            $query = $this->parser->parse($request->query->all(), $this->clock->now(), $hours['timezone'] ?? null);
        } catch (MeetingRequestException $e) {
            $error = $this->responseFactory->json(['error' => $e->errorCode, 'message' => 'Invalid ' . $e->field . '.', 'field' => $e->field], Response::HTTP_BAD_REQUEST, $request);

            return $this->plain($error);
        }
        try {
            if ($provider->stateFor($principal->user)->status === MeetingSyncStatus::Disabled) {
                return $this->plain($this->notFound($request));
            }
            $page = $provider->meetingsFor($principal->user, $query);
        } catch (Throwable $exception) {
            $this->logger->warning('TimeTrack meeting provider failed.', ['bundle' => 'time_track', 'action' => 'client_me_meetings', 'exception' => $exception::class]);

            return $this->plain($this->responseFactory->error('meetings_unavailable', 'Meetings are unavailable. Try again later.', Response::HTTP_SERVICE_UNAVAILABLE, $request, ['Retry-After' => '60']));
        }
        if ($page->sync->status === MeetingSyncStatus::Disabled) {
            return $this->plain($this->notFound($request));
        }
        $shaped = $this->responder->respond($principal->user, $query, $page);
        // @igor-ignore - Request-scoped value or I/O delegate (response, clock value, repository call); no worker state.
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));

        return [
            'response' => $this->responseFactory->json([
                'from'        => $query->from->format('Y-m-d\TH:i:s\Z'),
                'to'          => $query->to->format('Y-m-d\TH:i:s\Z'),
                'tz'          => $query->timeZone->getName(),
                'generatedAt' => $now->format('Y-m-d\TH:i:s\Z'),
                'sync'        => [
                    'status'              => $page->sync->status->value,
                    'lastSyncAt'          => $page->sync->lastSyncAt?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                    'refreshAfterSeconds' => $this->context->refreshAfter($page->sync),
                ],
                'truncated' => $shaped['truncated'],
                'meetings'  => $shaped['meetings'],
            ], Response::HTTP_OK, $request),
            'count'   => count($shaped['meetings']),
            'dropped' => $shaped['dropped'],
        ];
    }

    /**
     * 6 / min and 60 / h per token (`meetings.rate_limit`); null when allowed.
     */
    private function limit(ClientApiPrincipal $principal, Request $request): ?JsonResponse
    {
        $key   = $principal->token->getTokenHash();
        $retry = $this->rateLimiter->hit('meetings_minute', $key, $this->settings->perMinute, 60);
        if ($retry === 0) {
            $retry = $this->rateLimiter->hit('meetings_hour', $key, $this->settings->perHour, 3600);
        }

        return $retry > 0 ? $this->responseFactory->error('rate_limited', 'Too many requests. Try again later.', Response::HTTP_TOO_MANY_REQUESTS, $request, ['Retry-After' => (string) $retry]) : null;
    }

    /**
     * The privacy headers of every answer; a 429 also gets `retryAfter` in the body (`Retry-After` header too).
     */
    private function secure(Response $response): Response
    {
        $headers = $response->headers;
        // @igor-ignore - Request-scoped value or I/O delegate (response, clock value, repository call); no worker state.
        $headers->set('Cache-Control', 'no-store');
        // @igor-ignore - Request-scoped value or I/O delegate (response, clock value, repository call); no worker state.
        $headers->set('Pragma', 'no-cache');
        // @igor-ignore - Request-scoped value or I/O delegate (response, clock value, repository call); no worker state.
        $headers->set('X-Content-Type-Options', 'nosniff');
        // @igor-ignore - Request-scoped value or I/O delegate (response, clock value, repository call); no worker state.
        $headers->set('Vary', implode(', ', [...$headers->all('vary'), 'Authorization']));
        // @igor-ignore - Request-scoped value or I/O delegate (response, clock value, repository call); no worker state.
        $headers->remove('ETag');
        // @igor-ignore - Request-scoped value or I/O delegate (response, clock value, repository call); no worker state.
        $headers->remove('Last-Modified');
        if ($response->getStatusCode() === Response::HTTP_TOO_MANY_REQUESTS && $response instanceof JsonResponse) {
            $retry = max(1, (int) $headers->get('Retry-After', '60'));
            $body  = json_decode((string) $response->getContent(), true);
            // @igor-ignore - Request-scoped value or I/O delegate (response, clock value, repository call); no worker state.
            $headers->set('Retry-After', (string) $retry);
            // @igor-ignore - Request-scoped value or I/O delegate (response, clock value, repository call); no worker state.
            $response->setData((is_array($body) ? $body : []) + ['retryAfter' => $retry]);
        }

        return $response;
    }

    /**
     * @return array{response: Response, count: int, dropped: int}
     */
    private function plain(Response $response): array
    {
        return ['response' => $response, 'count' => 0, 'dropped' => 0];
    }

    private function notFound(Request $request): JsonResponse
    {
        return $this->responseFactory->error('not_found', 'Not found.', Response::HTTP_NOT_FOUND, $request);
    }
}
