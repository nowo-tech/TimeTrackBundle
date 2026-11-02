<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Controller;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\Client\ClientApiAuditor;
use Nowo\TimeTrackBundle\Client\ClientApiGuard;
use Nowo\TimeTrackBundle\Client\ClientApiPrincipal;
use Nowo\TimeTrackBundle\Client\ClientAuthResult;
use Nowo\TimeTrackBundle\Client\ClientLoginRateLimiter;
use Nowo\TimeTrackBundle\Client\ClientMinSupportedVersionProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientResponseFactory;
use Nowo\TimeTrackBundle\Client\ClientUserProfileProviderInterface;
use Nowo\TimeTrackBundle\Dto\TaskListQuery;
use Nowo\TimeTrackBundle\Dto\TaskReference;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientApiAuditAction;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\IdleResolution;
use Nowo\TimeTrackBundle\Exception\ActiveTimerConflictException;
use Nowo\TimeTrackBundle\Security\TaskAccessCheckerInterface;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Service\TeamAccessGuard;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Support\QueryPeriodResolver;
use Nowo\TimeTrackBundle\Support\TimeEntryCsv;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function array_map;
use function in_array;
use function intdiv;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function max;
use function mb_substr;
use function min;
use function preg_replace;
use function sprintf;
use function trim;

use const DATE_ATOM;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Browser extension and desktop app API (contract v0.2): stateless, Bearer only, exact-origin CORS, per-IP and
 * per-token rate limits, per-request credential state and scope checks ({@see ClientApiGuard}), per-request task check
 * on start / switch ({@see TaskAccessCheckerInterface}, deny by default; a missing and a foreign task both answer
 * `403 task_forbidden`), audit events ({@see ClientApiAuditor}). Errors are `{error: code, message}`.
 */
final readonly class TimeTrackClientApiController
{
    /** Max `limit` of `GET tasks`. */
    public const TASKS_MAX_LIMIT = 50;

    /** Max `limit` of `GET entries`. */
    public const ENTRIES_MAX_LIMIT = 100;

    /** Max rows of `GET export` (the period is capped at 366 days too). */
    public const EXPORT_MAX_ROWS = 10_000;

    /** Max length of a timer note. */
    public const NOTE_MAX_LENGTH = 500;

    private ClockInterface $clock;

    private LoggerInterface $logger;

    /**
     * Receives the services of the client API.
     *
     * @param bool $allowMetadata `client_api.allow_metadata`: accept free `metadata` on timer start (default false)
     * @param ClockInterface|null $clock Reference time of the default periods (defaults to the system clock)
     * @param LoggerInterface|null $logger Logs throttled logins without username or IP (defaults to no logging)
     * @param ClientMinSupportedVersionProviderInterface|null $minSupportedVersionProvider `me.minSupportedVersion` source (null = key never sent)
     */
    public function __construct(
        private ClientAuthService $authService,
        private TimerService $timerService,
        private TeamAccessGuard $teamAccessGuard,
        private ClientResponseFactory $responseFactory,
        private ClientLoginRateLimiter $loginRateLimiter,
        private int $idleThresholdSeconds,
        private ClientApiGuard $guard,
        private TaskAccessCheckerInterface $taskAccessChecker,
        private ClientUserProfileProviderInterface $profileProvider,
        private ClientApiAuditor $auditor,
        private bool $allowMetadata = false,
        ?ClockInterface $clock = null,
        ?LoggerInterface $logger = null,
        private ?ClientMinSupportedVersionProviderInterface $minSupportedVersionProvider = null,
    ) {
        $this->clock  = $clock ?? new NativeClock();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * POST login: issues a session token for valid credentials (throttled).
     */
    public function login(Request $request): Response
    {
        $limited = $this->preflightOrIpLimit($request);
        if ($limited instanceof Response) {
            return $limited;
        }

        $payload    = $this->payload($request);
        $username   = $this->string($payload, 'username');
        $password   = $this->string($payload, 'password');
        $clientType = $this->clientType($this->string($payload, 'clientType'));

        if ($username === '' || $password === '') {
            return $this->responseFactory->error('invalid_request', 'username and password are required.', Response::HTTP_BAD_REQUEST, $request);
        }

        $clientIp = (string) $request->getClientIp();
        if ($this->loginRateLimiter->isLimited($clientIp, $username)) {
            $this->logger->warning('TimeTrack client login throttled.', ['bundle' => 'time_track', 'action' => 'client_login', 'client' => $clientType->value]);
            $this->auditor->denied('rate_limited', null, $clientIp);

            return $this->responseFactory->error('rate_limited', 'Too many login attempts. Try again later.', Response::HTTP_TOO_MANY_REQUESTS, $request, ['Retry-After' => '60']);
        }

        $result = $this->authService->authenticate($username, $password, $clientType);
        if (!$result instanceof ClientAuthResult) {
            // @igor-ignore - HTTP handler delegates to services; no controller worker state.
            $this->loginRateLimiter->registerFailedAttempt($clientIp, $username);

            return $this->responseFactory->error('invalid_credentials', 'Invalid credentials.', Response::HTTP_UNAUTHORIZED, $request);
        }

        $this->loginRateLimiter->reset($clientIp, $username);
        $user  = $result->requireUser();
        $token = $this->authService->issueToken($user, $clientType, $result->getCredentialId(), $result->getScopes());
        $this->auditor->record(ClientApiAuditAction::TokenIssued, $user, $result->getCredentialId(), $clientType, $clientIp);

        return $this->responseFactory->json($token, Response::HTTP_OK, $request);
    }

    /**
     * POST logout: revokes the presented token.
     */
    public function logout(Request $request): Response
    {
        $limited = $this->preflightOrIpLimit($request);
        if ($limited instanceof Response) {
            return $limited;
        }

        $plain   = ClientApiGuard::bearerToken($request);
        $revoked = $plain !== null ? $this->authService->logout($plain) : null;
        if ($revoked instanceof ClientToken) {
            $this->auditor->record(ClientApiAuditAction::TokenRevoked, $revoked->getUser(), $revoked->getCredentialId(), $revoked->getClientType(), $request->getClientIp());
        }

        return $this->responseFactory->empty(Response::HTTP_NO_CONTENT, $request);
    }

    /**
     * GET me: user id, identifier, display name, scopes and working hours of the token user, plus
     * `minSupportedVersion` for the token client type when the host sets one (1.9.1).
     */
    public function me(Request $request): Response
    {
        $principal = $this->authorize($request, null);
        if ($principal instanceof Response) {
            return $principal;
        }

        $user = $principal->user;
        $body = [
            'userId'       => UserIdResolver::getId($user),
            'identifier'   => $user->getUserIdentifier(),
            'displayName'  => $this->profileProvider->getDisplayName($user),
            'scopes'       => ClientScope::values($principal->token->getScopes()),
            'workingHours' => $this->profileProvider->getWorkingHours($user),
        ];
        $minSupportedVersion = $this->minSupportedVersionProvider?->getMinSupportedVersion($principal->token->getClientType());
        if ($minSupportedVersion !== null) {
            $body['minSupportedVersion'] = $minSupportedVersion;
        }

        return $this->responseFactory->json($body, Response::HTTP_OK, $request);
    }

    /**
     * GET tasks: trackable tasks (`search`, `limit` 1-50, `offset`).
     */
    public function tasks(Request $request): Response
    {
        $principal = $this->authorize($request, ClientScope::TasksRead);
        if ($principal instanceof Response) {
            return $principal;
        }

        $query = TaskListQuery::bounded(
            $request->query->getString('search') ?: null,
            min(self::TASKS_MAX_LIMIT, $request->query->getInt('limit', self::TASKS_MAX_LIMIT)),
            $request->query->getInt('offset', 0),
        );

        return $this->responseFactory->json([
            'tasks' => array_map(static fn (TaskReference $task): array => $task->toArray(), $this->timerService->listTasks($principal->user, $query)),
        ], Response::HTTP_OK, $request);
    }

    /**
     * GET timer: the active timer (204 when none).
     */
    public function timer(Request $request): Response
    {
        $principal = $this->authorize($request, ClientScope::TimerRead);
        if ($principal instanceof Response) {
            return $principal;
        }

        $active = $this->timerService->getActive($principal->user);
        if (!$active instanceof ActiveTimer) {
            return $this->responseFactory->empty(Response::HTTP_NO_CONTENT, $request);
        }

        return $this->responseFactory->json(['timer' => $active->toArray()], Response::HTTP_OK, $request);
    }

    /**
     * POST timer/start `{taskId, note?, source?}`: starts a timer (403 task_forbidden, 409 timer_running).
     */
    public function timerStart(Request $request): Response
    {
        $principal = $this->authorize($request, ClientScope::TimerWrite);
        if ($principal instanceof Response) {
            return $principal;
        }

        $payload = $this->payload($request);
        $taskId  = $this->string($payload, 'taskId');
        if ($taskId === '') {
            return $this->responseFactory->error('invalid_request', 'taskId is required.', Response::HTTP_BAD_REQUEST, $request);
        }

        $metadata = $this->metadata($payload, $request);
        if ($metadata instanceof Response) {
            return $metadata;
        }

        $clientType = $this->source($payload, $principal);
        if (!$clientType instanceof ClientType) {
            return $this->invalidSource($request);
        }

        if (!$this->taskAccessChecker->canTrack($principal->user, $taskId)) {
            return $this->taskForbidden($principal, $request);
        }

        try {
            $timer = $this->timerService->start($principal->user, $taskId, $clientType, $metadata);
        } catch (ActiveTimerConflictException) {
            return $this->timerRunning($request);
        } catch (InvalidArgumentException) {
            return $this->taskForbidden($principal, $request);
        }

        return $this->responseFactory->json(['timer' => $timer->toArray()], Response::HTTP_CREATED, $request);
    }

    /**
     * POST timer/stop `{note?}`: stops the active timer and returns the entry.
     */
    public function timerStop(Request $request): Response
    {
        $principal = $this->authorize($request, ClientScope::TimerWrite);
        if ($principal instanceof Response) {
            return $principal;
        }

        $entry = $this->timerService->stop($principal->user, null, $this->note($this->payload($request)));
        if (!$entry instanceof TimeEntry) {
            return $this->noActiveTimer($request);
        }

        return $this->responseFactory->json(['entry' => $entry->toArray()], Response::HTTP_OK, $request);
    }

    /**
     * POST timer/switch `{taskId, note?, source?}`: stops the running timer (if any, with the note) and starts one on
     * the task. The task is checked first: a refused task leaves the running timer untouched.
     */
    public function timerSwitch(Request $request): Response
    {
        $principal = $this->authorize($request, ClientScope::TimerWrite);
        if ($principal instanceof Response) {
            return $principal;
        }

        $payload = $this->payload($request);
        $taskId  = $this->string($payload, 'taskId');
        if ($taskId === '') {
            return $this->responseFactory->error('invalid_request', 'taskId is required.', Response::HTTP_BAD_REQUEST, $request);
        }

        $clientType = $this->source($payload, $principal);
        if (!$clientType instanceof ClientType) {
            return $this->invalidSource($request);
        }

        if (!$this->taskAccessChecker->canTrack($principal->user, $taskId)) {
            return $this->taskForbidden($principal, $request);
        }

        try {
            $result = $this->timerService->switchTo($principal->user, $taskId, $clientType, $this->note($payload));
        } catch (ActiveTimerConflictException) {
            return $this->timerRunning($request);
        } catch (InvalidArgumentException) {
            return $this->taskForbidden($principal, $request);
        }

        return $this->responseFactory->json([
            'entry' => $result['entry']?->toArray(),
            'timer' => $result['timer']->toArray(),
        ], Response::HTTP_CREATED, $request);
    }

    /**
     * POST timer/discard-idle `{from, to, action: keep|discard|split}`: the user's decision about an idle span the
     * client detected locally (replaces the server-side idle flag of the heartbeat).
     */
    public function timerDiscardIdle(Request $request): Response
    {
        $principal = $this->authorize($request, ClientScope::TimerWrite);
        if ($principal instanceof Response) {
            return $principal;
        }

        $payload    = $this->payload($request);
        $from       = $this->dateTime($this->string($payload, 'from'));
        $to         = $this->dateTime($this->string($payload, 'to'));
        $resolution = IdleResolution::tryFrom($this->string($payload, 'action'));
        if (!$from instanceof DateTimeImmutable || !$to instanceof DateTimeImmutable || !$resolution instanceof IdleResolution) {
            return $this->responseFactory->error('invalid_request', 'from and to (RFC 3339) and action (keep, discard, split) are required.', Response::HTTP_BAD_REQUEST, $request);
        }

        try {
            $result = $this->timerService->resolveIdle($principal->user, $from, $to, $resolution);
        } catch (InvalidArgumentException $e) {
            return $this->responseFactory->error('invalid_request', $e->getMessage(), Response::HTTP_BAD_REQUEST, $request);
        }

        if ($result === null) {
            return $this->noActiveTimer($request);
        }

        return $this->responseFactory->json([
            'entry' => $result['entry']?->toArray(),
            'timer' => $result['timer']?->toArray(),
        ], Response::HTTP_OK, $request);
    }

    /**
     * POST heartbeat (deprecated since 1.9, removed in 2.0): keeps answering with the running timer; `isIdle` is
     * ignored (idle is the user's decision on the client, see `timer/discard-idle`).
     */
    public function heartbeat(Request $request): Response
    {
        $principal = $this->authorize($request, ClientScope::TimerWrite);
        if ($principal instanceof Response) {
            return $principal;
        }

        $timer    = $this->timerService->keepAlive($principal->user);
        $response = $timer instanceof ActiveTimer
            ? $this->responseFactory->json([
                'timer'                => $timer->toArray(),
                'idleThresholdSeconds' => $this->idleThresholdSeconds,
                'deprecated'           => true,
            ], Response::HTTP_OK, $request)
            : $this->noActiveTimer($request);
        // @igor-ignore - Request-scoped value or I/O delegate (response, clock value, repository call); no worker state.
        $response->headers->set('Deprecation', 'true');

        return $response;
    }

    /**
     * GET entries: own time entries of a period (`from`, `to`, `limit` 1-100, `offset`). The deprecated `userId`
     * parameter keeps the 1.x team view (checked by the team guard).
     */
    public function entries(Request $request): Response
    {
        $principal = $this->authorize($request, ClientScope::EntriesRead);
        if ($principal instanceof Response) {
            return $principal;
        }

        $period       = QueryPeriodResolver::resolve($request->query->getString('from'), $request->query->getString('to'), $this->clock->now());
        $limit        = max(1, min(self::ENTRIES_MAX_LIMIT, $request->query->getInt('limit', self::ENTRIES_MAX_LIMIT)));
        $offset       = max(0, $request->query->getInt('offset', 0));
        $targetUserId = $request->query->getString('userId') ?: null;
        $user         = $principal->user;

        if ($targetUserId !== null && $targetUserId !== UserIdResolver::getId($user)) {
            if (!$this->teamAccessGuard->canViewUserEntries($user, $targetUserId)) {
                $this->auditor->denied('forbidden', $principal->token, $request->getClientIp());

                return $this->responseFactory->error('forbidden', 'Forbidden.', Response::HTTP_FORBIDDEN, $request);
            }

            $list = $this->timerService->listEntriesPage($user, $period['from'], $period['to'], 1 + intdiv($offset, $limit), $limit, $targetUserId)->entries;
        } else {
            $list = $this->timerService->listOwnEntries($user, $period['from'], $period['to'], $limit, $offset);
        }

        return $this->responseFactory->json([
            'entries' => array_map(static fn (TimeEntry $entry): array => $entry->toArray(), $list),
            'limit'   => $limit,
            'offset'  => $offset,
        ], Response::HTTP_OK, $request);
    }

    /**
     * GET export `?from&to&format=csv|json`: the user's own entries of the period (RGPD art. 15/20), as a download.
     */
    public function export(Request $request): Response
    {
        $principal = $this->authorize($request, ClientScope::EntriesRead);
        if ($principal instanceof Response) {
            return $principal;
        }

        $format = $request->query->getString('format', 'json');
        if (!in_array($format, ['csv', 'json'], true)) {
            return $this->responseFactory->error('invalid_request', 'format must be csv or json.', Response::HTTP_BAD_REQUEST, $request);
        }

        $period  = QueryPeriodResolver::resolve($request->query->getString('from'), $request->query->getString('to'), $this->clock->now());
        $entries = $this->timerService->listOwnEntries($principal->user, $period['from'], $period['to'], self::EXPORT_MAX_ROWS);
        $name    = sprintf('time-entries-%s-%s.%s', $period['from']->format('Ymd'), $period['to']->format('Ymd'), $format);

        $response = $this->responseFactory->empty(Response::HTTP_OK, $request);
        // @igor-ignore - Request-scoped value or I/O delegate (response, clock value, repository call); no worker state.
        $response->setContent($format === 'csv'
            ? TimeEntryCsv::render($entries)
            : json_encode([
                'from'    => $period['from']->format(DATE_ATOM),
                'to'      => $period['to']->format(DATE_ATOM),
                'entries' => array_map(static fn (TimeEntry $entry): array => $entry->toArray(), $entries),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        // @igor-ignore - Request-scoped value or I/O delegate (response, clock value, repository call); no worker state.
        $response->headers->set('Content-Type', $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/json');
        // @igor-ignore - Request-scoped value or I/O delegate (response, clock value, repository call); no worker state.
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $name . '"');
        // @igor-ignore - Request-scoped value or I/O delegate (response, clock value, repository call); no worker state.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * CORS preflight (204) or the per-IP limit of an unauthenticated route; null to go on.
     */
    private function preflightOrIpLimit(Request $request): ?Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->responseFactory->empty(Response::HTTP_NO_CONTENT, $request);
        }

        return $this->guard->limitIp($request);
    }

    /**
     * Answers a CORS preflight, or authenticates the request and checks the scope.
     */
    private function authorize(Request $request, ?ClientScope $scope): ClientApiPrincipal|Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->responseFactory->empty(Response::HTTP_NO_CONTENT, $request);
        }

        return $this->guard->authenticate($request, $scope);
    }

    private function taskForbidden(ClientApiPrincipal $principal, Request $request): JsonResponse
    {
        $this->auditor->denied('task_forbidden', $principal->token, $request->getClientIp());

        return $this->responseFactory->error('task_forbidden', 'You may not track time on this task.', Response::HTTP_FORBIDDEN, $request);
    }

    private function timerRunning(Request $request): JsonResponse
    {
        return $this->responseFactory->error('timer_running', 'A timer is already running.', Response::HTTP_CONFLICT, $request);
    }

    private function noActiveTimer(Request $request): JsonResponse
    {
        return $this->responseFactory->error('no_active_timer', 'No active timer.', Response::HTTP_NOT_FOUND, $request);
    }

    private function invalidSource(Request $request): JsonResponse
    {
        return $this->responseFactory->error('invalid_request', 'source must be one of extension, desktop, web.', Response::HTTP_BAD_REQUEST, $request);
    }

    /**
     * Free client metadata: rejected unless `client_api.allow_metadata`; the note is stored as `note`.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|Response
     */
    private function metadata(array $payload, Request $request): array|Response
    {
        $metadata = $payload['metadata'] ?? null;
        if ($metadata !== null && $metadata !== [] && !$this->allowMetadata) {
            return $this->responseFactory->error('metadata_not_allowed', 'Free metadata is not accepted; send source (extension, desktop, web) and note instead.', Response::HTTP_BAD_REQUEST, $request);
        }

        /** @var array<string, mixed> $kept */
        $kept = is_array($metadata) ? $metadata : [];
        $note = $this->note($payload);
        if ($note !== null) {
            $kept['note'] = $note;
        }

        return $kept;
    }

    /**
     * Client type of a timer: `source` (closed enum), the 1.x `clientType`, else the token's client; null when
     * `source` is invalid.
     *
     * @param array<string, mixed> $payload
     */
    private function source(array $payload, ClientApiPrincipal $principal): ?ClientType
    {
        if (isset($payload['source'])) {
            return is_string($payload['source']) ? ClientType::tryFrom($payload['source']) : null;
        }

        $legacy = $this->string($payload, 'clientType');

        return $legacy !== '' ? $this->clientType($legacy) : $principal->token->getClientType();
    }

    /**
     * Decoded JSON object body (empty array for anything else).
     *
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($request->getContent(), true);

        /** @var array<string, mixed> $payload */
        $payload = is_array($decoded) ? $decoded : [];

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function string(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }

    /**
     * Trimmed note capped at 500 characters (null when absent or blank).
     *
     * @param array<string, mixed> $payload
     */
    private function note(array $payload): ?string
    {
        $note = $this->string($payload, 'note');

        return $note === '' ? null : mb_substr($note, 0, self::NOTE_MAX_LENGTH);
    }

    /**
     * Strict RFC 3339 date-time (`Z` or an offset, optional milliseconds); null for anything else.
     */
    private function dateTime(string $value): ?DateTimeImmutable
    {
        $value = (string) preg_replace('/Z$/', '+00:00', $value);
        foreach ([DATE_ATOM, 'Y-m-d\TH:i:s.vP'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($date instanceof DateTimeImmutable && $date->format($format) === $value) {
                return $date;
            }
        }

        return null;
    }

    private function clientType(string $value): ClientType
    {
        return ClientType::tryFrom($value) ?? ClientType::Extension;
    }
}
