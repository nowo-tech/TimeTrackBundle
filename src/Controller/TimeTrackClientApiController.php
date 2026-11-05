<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Controller;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\Client\ClientApiAuditor;
use Nowo\TimeTrackBundle\Client\ClientApiGuard;
use Nowo\TimeTrackBundle\Client\ClientApiPrincipal;
use Nowo\TimeTrackBundle\Client\ClientApiSettingsProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientAuthResult;
use Nowo\TimeTrackBundle\Client\ClientDaysOffNormalizer;
use Nowo\TimeTrackBundle\Client\ClientLoginRateLimiter;
use Nowo\TimeTrackBundle\Client\ClientMinSupportedVersionProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientResponseFactory;
use Nowo\TimeTrackBundle\Client\ClientTaskPresenter;
use Nowo\TimeTrackBundle\Client\ClientUserDaysOffProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientUserLocaleProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientUserProfileProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientWorkingHoursNormalizer;
use Nowo\TimeTrackBundle\Client\ConfigClientApiSettingsProvider;
use Nowo\TimeTrackBundle\Client\IdleCheckSettingsResolver;
use Nowo\TimeTrackBundle\DependencyInjection\Configuration;
use Nowo\TimeTrackBundle\Dto\ClampedTime;
use Nowo\TimeTrackBundle\Dto\TaskListQuery;
use Nowo\TimeTrackBundle\Dto\TaskReference;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientApiAuditAction;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\IdleFollowUp;
use Nowo\TimeTrackBundle\Enum\IdleResolution;
use Nowo\TimeTrackBundle\Exception\ActiveTimerConflictException;
use Nowo\TimeTrackBundle\Exception\TaskNotTrackableException;
use Nowo\TimeTrackBundle\Security\TaskAccessCheckerInterface;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Service\TeamAccessGuard;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Support\NoteSanitizer;
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
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_map;
use function date_default_timezone_get;
use function in_array;
use function intdiv;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function max;
use function mb_strlen;
use function method_exists;
use function min;
use function preg_match;
use function preg_replace;
use function sprintf;
use function trim;

use const DATE_ATOM;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Browser extension and desktop app API (contract v0.3 since 1.10; v0.2 in 1.9): stateless, Bearer only, exact-origin CORS, per-IP and
 * per-token rate limits, per-request credential state and scope checks ({@see ClientApiGuard}), per-request task check
 * on start / switch ({@see TaskAccessCheckerInterface}, deny by default; a missing and a foreign task both answer
 * `403 task_forbidden`), audit events ({@see ClientApiAuditor}). Errors are `{error: code, message}`.
 *
 * Contract v0.3 (1.10, additive): task details on tasks and timers ({@see ClientTaskPresenter}), `startedAt` on
 * `timer/start` and `endedAt` on `timer/stop` (clamped and reported), `reassign` on `timer/discard-idle`, and
 * `features` / `limits` on `me`.
 */
final readonly class TimeTrackClientApiController
{
    /** Max `limit` of `GET tasks`. */
    public const TASKS_MAX_LIMIT = 50;

    /** Max `limit` of `GET entries`. */
    public const ENTRIES_MAX_LIMIT = 100;

    /** Max rows of `GET export` (the period is capped at 366 days too). */
    public const EXPORT_MAX_ROWS = 10_000;

    /** Max length of a timer note, in code points ({@see NoteSanitizer::MAX_LENGTH}). */
    public const NOTE_MAX_LENGTH = NoteSanitizer::MAX_LENGTH;

    /** Features of `GET me` reserved for later releases; always false in 1.10. */
    public const RESERVED_FEATURES = ['workday', 'monitoring'];

    /** Earliest UTC offset accepted on client timestamps, in minutes (-12:00; 1.9.2). */
    public const MIN_UTC_OFFSET_MINUTES = -720;

    /** Latest UTC offset accepted on client timestamps, in minutes (+14:00; 1.9.2). */
    public const MAX_UTC_OFFSET_MINUTES = 840;

    /** `me.locale` values accepted (1.11): a language, then up to three subtags (`es`, `pt_BR`, `zh-Hant-TW`). */
    public const LOCALE_PATTERN = '/^[A-Za-z]{2,3}(?:[_-][A-Za-z0-9]{2,8}){0,3}$/D';

    /** Translation domain of the masked title (1.11). */
    private const TRANSLATION_DOMAIN = 'NowoTimeTrackBundle';

    private ClockInterface $clock;

    private LoggerInterface $logger;

    private ClientTaskPresenter $taskPresenter;

    private ClientApiSettingsProviderInterface $settingsProvider;

    private IdleCheckSettingsResolver $idleCheckResolver;

    /**
     * Receives the services of the client API.
     *
     * @param bool $allowMetadata `client_api.allow_metadata`: accept free `metadata` on timer start (default false)
     * @param ClockInterface|null $clock Reference time of the default periods (defaults to the system clock)
     * @param LoggerInterface|null $logger Logs throttled logins without username or IP and (1.12) malformed working hours / days off without user data (defaults to no logging)
     * @param ClientMinSupportedVersionProviderInterface|null $minSupportedVersionProvider `me.minSupportedVersion` source (null = key never sent)
     * @param ClientTaskPresenter|null $taskPresenter Task details of tasks and timers (null = request origin, no logging)
     * @param ClientApiSettingsProviderInterface|null $settingsProvider Backdate and idle settings (null = the defaults)
     * @param IdleCheckSettingsResolver|null $idleCheckResolver Per-user idle-check settings of `me` (null = instance settings)
     * @param TranslatorInterface|null $translator Translates the masked title of imported entries (1.11; null = English)
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
        ?ClientTaskPresenter $taskPresenter = null,
        ?ClientApiSettingsProviderInterface $settingsProvider = null,
        ?IdleCheckSettingsResolver $idleCheckResolver = null,
        private ?TranslatorInterface $translator = null,
    ) {
        $this->clock             = $clock ?? new NativeClock();
        $this->logger            = $logger ?? new NullLogger();
        $this->taskPresenter     = $taskPresenter ?? new ClientTaskPresenter($this->timerService);
        $this->settingsProvider  = $settingsProvider ?? new ConfigClientApiSettingsProvider();
        $this->idleCheckResolver = $idleCheckResolver ?? new IdleCheckSettingsResolver($this->settingsProvider);
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
     * `minSupportedVersion` for the token client type when the host sets a valid semver one (1.9.1), and (1.10)
     * `features` (object of booleans; `workday` and `monitoring` reserved, always false) and `limits` (backdate and
     * idle-check settings for the token user), and (1.11) `locale` (the user's preferred locale, or null), and (1.12)
     * `daysOff` (holidays / vacations today → +60 days, or null); since 1.12 `workingHours` is normalised to the `slots`
     * shape ({@see ClientWorkingHoursNormalizer}).
     */
    public function me(Request $request): Response
    {
        $principal = $this->authorize($request, null);
        if ($principal instanceof Response) {
            return $principal;
        }

        $user         = $principal->user;
        $workingHours = ClientWorkingHoursNormalizer::normalize($this->profileProvider->getWorkingHours($user), $this->logger);
        $body         = [
            'userId'       => UserIdResolver::getId($user),
            'identifier'   => $user->getUserIdentifier(),
            'displayName'  => $this->profileProvider->getDisplayName($user),
            'scopes'       => ClientScope::values($principal->token->getScopes()),
            'workingHours' => $workingHours,
            'locale'       => $this->userLocale($user),
            'daysOff'      => $this->daysOff($user, $workingHours['timezone'] ?? null),
        ];
        $minSupportedVersion = $this->minSupportedVersionProvider?->getMinSupportedVersion($principal->token->getClientType());
        if ($minSupportedVersion !== null && preg_match(Configuration::SEMVER_PATTERN, $minSupportedVersion) === 1) {
            $body['minSupportedVersion'] = $minSupportedVersion;
        }

        $settings  = $this->settingsProvider->getSettings();
        $idleCheck = $this->idleCheckResolver->forUser($user);
        $backdate  = $settings->backdate->isEnabled();
        $features  = [
            'task_details'   => true,
            'start_backdate' => $backdate,
            'stop_backdate'  => $backdate,
            'idle_reassign'  => $settings->allowIdleReassign,
            'idle_keep_note' => true,
            'idle_check'     => $idleCheck->enabled,
        ];
        foreach (self::RESERVED_FEATURES as $reserved) {
            $features[$reserved] = false;
        }

        $body['features'] = $features;
        $body['limits']   = [
            'backdate'   => ['max_seconds' => $settings->backdate->maxSeconds, 'clock_skew_seconds' => $settings->backdate->clockSkewSeconds],
            'idle_check' => $idleCheck->limitsToArray(),
        ];

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
            'tasks' => array_map(fn (TaskReference $task): array => $this->taskPresenter->task($task, $request), $this->timerService->listTasks($principal->user, $query)),
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

        return $this->responseFactory->json(['timer' => $this->taskPresenter->timer($active, $principal->user, $request)], Response::HTTP_OK, $request);
    }

    /**
     * POST timer/start `{taskId, note?, source?, startedAt?}`: starts a timer (403 task_forbidden, 409 timer_running).
     * Since 1.10 an RFC 3339 `startedAt` backdates the start after the server clamps (reported as `startedAtResult`).
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

        $startedAt = $this->optionalDateTime($payload, 'startedAt');
        if ($startedAt === false) {
            return $this->responseFactory->error('invalid_request', 'startedAt must be an RFC 3339 date-time.', Response::HTTP_BAD_REQUEST, $request);
        }

        if (!$this->taskAccessChecker->canTrack($principal->user, $taskId)) {
            return $this->taskForbidden($principal, $request);
        }

        try {
            $result = $this->timerService->startAt($principal->user, $taskId, $clientType, $metadata, $startedAt, $this->settingsProvider->getSettings()->backdate);
        } catch (ActiveTimerConflictException) {
            return $this->timerRunning($request);
        } catch (InvalidArgumentException) {
            return $this->taskForbidden($principal, $request);
        }

        $body = ['timer' => $this->taskPresenter->timer($result['timer'], $principal->user, $request, $result['task'])];
        if ($result['startTime'] instanceof ClampedTime) {
            $body['startedAtResult'] = $result['startTime']->toArray();
        }

        return $this->responseFactory->json($body, Response::HTTP_CREATED, $request);
    }

    /**
     * POST timer/stop `{note?, endedAt?}`: stops the active timer and returns the entry. Since 1.10 an RFC 3339
     * `endedAt` sets an earlier end after the server clamps (reported as `endedAtResult`).
     */
    public function timerStop(Request $request): Response
    {
        $principal = $this->authorize($request, ClientScope::TimerWrite);
        if ($principal instanceof Response) {
            return $principal;
        }

        $payload = $this->payload($request);
        $endedAt = $this->optionalDateTime($payload, 'endedAt');
        if ($endedAt === false) {
            return $this->responseFactory->error('invalid_request', 'endedAt must be an RFC 3339 date-time.', Response::HTTP_BAD_REQUEST, $request);
        }

        $result = $this->timerService->stopAt($principal->user, $endedAt, $this->settingsProvider->getSettings()->backdate, $this->note($payload));
        if ($result === null) {
            return $this->noActiveTimer($request);
        }

        $body = ['entry' => $result['entry']->toArray()];
        if ($result['endTime'] instanceof ClampedTime) {
            $body['endedAtResult'] = $result['endTime']->toArray();
        }

        return $this->responseFactory->json($body, Response::HTTP_OK, $request);
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
            'timer' => $this->taskPresenter->timer($result['timer'], $principal->user, $request),
        ], Response::HTTP_CREATED, $request);
    }

    /**
     * POST timer/discard-idle `{from, to, action: keep|discard|split|reassign, taskId?, then?, note?, source?}` (`note` on
     * `keep` since 1.10.1): the
     * user's decision about an idle span the client detected locally (replaces the server-side idle flag of the
     * heartbeat). `from` / `to` are converted to the server time zone and must carry an offset between -12:00 and
     * +14:00 (1.9.2). 409 timer_running when a concurrent start took the timer slot before the follow-up timer started
     * (1.9.1). Since 1.10 `reassign` gives the span to `taskId` (checked like `timer/start`: an unknown and a foreign
     * task both answer the same `403 task_forbidden`) and then continues, switches or stops (`then`).
     */
    public function timerDiscardIdle(Request $request): Response
    {
        $principal = $this->authorize($request, ClientScope::TimerWrite);
        if ($principal instanceof Response) {
            return $principal;
        }

        $payload    = $this->payload($request);
        $from       = $this->dateTime($this->rawString($payload, 'from'));
        $to         = $this->dateTime($this->rawString($payload, 'to'));
        $resolution = IdleResolution::tryFrom($this->string($payload, 'action'));
        if (!$from instanceof DateTimeImmutable || !$to instanceof DateTimeImmutable || !$resolution instanceof IdleResolution) {
            return $this->responseFactory->error('invalid_request', 'from and to (RFC 3339) and action (keep, discard, split, reassign) are required.', Response::HTTP_BAD_REQUEST, $request);
        }

        $taskId   = null;
        $followUp = IdleFollowUp::Continue;
        $source   = null;
        $note     = null;
        if ($resolution === IdleResolution::Keep) {
            $note = $this->keepNote($payload);
            if ($note === false) {
                return $this->responseFactory->error('invalid_request', sprintf('note must be plain text of at most %d characters.', self::NOTE_MAX_LENGTH), Response::HTTP_BAD_REQUEST, $request);
            }
        }

        if ($resolution === IdleResolution::Reassign) {
            $invalid = $this->reassignInput($payload, $principal, $request);
            if ($invalid instanceof Response) {
                return $invalid;
            }

            ['taskId' => $taskId, 'followUp' => $followUp, 'source' => $source] = $invalid;
            $note                                                               = $this->note($payload);

            if (!$this->taskAccessChecker->canTrack($principal->user, $taskId)) {
                return $this->taskForbidden($principal, $request);
            }
        }

        try {
            $result = $this->timerService->resolveIdle($principal->user, $from, $to, $resolution, $taskId, $followUp, $source, $note);
        } catch (ActiveTimerConflictException) {
            return $this->timerRunning($request);
        } catch (TaskNotTrackableException) {
            return $this->taskForbidden($principal, $request);
        } catch (InvalidArgumentException $e) {
            return $this->responseFactory->error('invalid_request', $e->getMessage(), Response::HTTP_BAD_REQUEST, $request);
        }

        if ($result === null) {
            return $this->noActiveTimer($request);
        }

        return $this->responseFactory->json([
            'entry'      => $result['entry']?->toArray(),
            'timer'      => $result['timer'] instanceof ActiveTimer ? $this->taskPresenter->timer($result['timer'], $principal->user, $request) : null,
            'reassigned' => $result['reassigned']?->toArray(),
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
                'timer'                => $this->taskPresenter->timer($timer, $principal->user, $request),
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

        $hidden      = $this->timerService->hiddenTaskEntryIds($list);
        $hiddenTitle = $hidden === [] ? TimeEntry::HIDDEN_TASK_TITLE : $this->hiddenTaskTitle($user);

        return $this->responseFactory->json([
            'entries' => array_map(static fn (TimeEntry $entry): array => $entry->toArray(!isset($hidden[$entry->getId()]), $hiddenTitle), $list),
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
        $hidden  = $this->timerService->hiddenTaskEntryIds($entries);
        $masked  = $hidden === [] ? TimeEntry::HIDDEN_TASK_TITLE : $this->hiddenTaskTitle($principal->user);

        $response = $this->responseFactory->empty(Response::HTTP_OK, $request);
        // @igor-ignore - Request-scoped value or I/O delegate (response, clock value, repository call); no worker state.
        $response->setContent($format === 'csv'
            ? TimeEntryCsv::render($entries, $hidden, $masked)
            : json_encode([
                'from'    => $period['from']->format(DATE_ATOM),
                'to'      => $period['to']->format(DATE_ATOM),
                'entries' => array_map(static fn (TimeEntry $entry): array => $entry->toArray(!isset($hidden[$entry->getId()]), $masked), $entries),
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
     * Since 1.11: the user's preferred locale for `me` — the profile provider's when it implements
     * {@see ClientUserLocaleProviderInterface}, else the user's own `getLocale()` when it has one — or null when unknown
     * or not a locale ({@see self::LOCALE_PATTERN}).
     */
    private function userLocale(UserInterface $user): ?string
    {
        if ($this->profileProvider instanceof ClientUserLocaleProviderInterface) {
            $locale = $this->profileProvider->getLocale($user);
        } else {
            $locale = method_exists($user, 'getLocale') ? $user->getLocale() : null;
        }

        return is_string($locale) && preg_match(self::LOCALE_PATTERN, $locale) === 1 ? $locale : null;
    }

    /**
     * Since 1.12: the user's days off from today to today + {@see ClientDaysOffNormalizer::HORIZON_DAYS} in the
     * working-hours time zone (else the PHP default one) — from the profile provider when it implements
     * {@see ClientUserDaysOffProviderInterface}, validated by {@see ClientDaysOffNormalizer} — or null when unknown.
     *
     * @return list<array{date: string, kind: string, label: string|null}>|null
     */
    private function daysOff(UserInterface $user, ?string $timezone): ?array
    {
        if (!$this->profileProvider instanceof ClientUserDaysOffProviderInterface) {
            return null;
        }

        [$from, $to] = ClientDaysOffNormalizer::range($this->clock->now(), $timezone ?? date_default_timezone_get());

        return ClientDaysOffNormalizer::normalize($this->profileProvider->getDaysOff($user, $from, $to), $from->format('Y-m-d'), $to->format('Y-m-d'), $this->logger);
    }

    /**
     * Since 1.11: the title of a masked imported entry (`reports.task_not_visible` in the user's locale, else the
     * translator's default locale; English without a translator).
     */
    private function hiddenTaskTitle(UserInterface $user): string
    {
        if (!$this->translator instanceof TranslatorInterface) {
            return TimeEntry::HIDDEN_TASK_TITLE;
        }

        $title = $this->translator->trans('reports.task_not_visible', [], self::TRANSLATION_DOMAIN, $this->userLocale($user));

        return $title === '' || $title === 'reports.task_not_visible' ? TimeEntry::HIDDEN_TASK_TITLE : $title;
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
     * Free client metadata: rejected unless `client_api.allow_metadata`; the note (the `note` field, else a string
     * `metadata.note`) is cleaned like every note and stored as `note`.
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
        $note = $this->note($payload) ?? (isset($kept['note']) && is_string($kept['note']) ? NoteSanitizer::cleanAndCut($kept['note']) : null);
        unset($kept['note']);
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
     * Note of `timer/start`, `timer/stop`, `timer/switch` and reassign, cleaned by {@see NoteSanitizer} and cut at
     * {@see self::NOTE_MAX_LENGTH} code points (null when absent, not a string, or blank).
     *
     * @param array<string, mixed> $payload
     */
    private function note(array $payload): ?string
    {
        return NoteSanitizer::cleanAndCut($this->rawString($payload, 'note'));
    }

    /**
     * Optional note of an idle `keep` (1.10.1), cleaned by {@see NoteSanitizer}; null when absent or blank; false when
     * it is not a string or is longer than {@see self::NOTE_MAX_LENGTH} code points after cleaning (400, unlike the other
     * notes, which are cut).
     *
     * @param array<string, mixed> $payload
     */
    private function keepNote(array $payload): string|false|null
    {
        $note = $payload['note'] ?? null;
        if ($note === null) {
            return null;
        }

        if (!is_string($note)) {
            return false;
        }

        $clean = NoteSanitizer::clean($note);
        if ($clean === '') {
            return null;
        }

        return mb_strlen($clean) > self::NOTE_MAX_LENGTH ? false : $clean;
    }

    /**
     * Untrimmed string field ('' when absent or not a string).
     *
     * @param array<string, mixed> $payload
     */
    private function rawString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * Strict RFC 3339 date-time (`Z`, `z` or an offset between -12:00 and +14:00, optional milliseconds; no surrounding
     * whitespace), converted to the server's default time zone (the one Doctrine hydrates date-times in); null for
     * anything else.
     *
     * 1.9.2 (EF-M1): Doctrine's `datetime_immutable` stores the wall time without its offset and reads it back in the
     * server time zone, so a value kept in the client's offset would be stored shifted by that offset.
     */
    private function dateTime(string $value): ?DateTimeImmutable
    {
        $value = (string) preg_replace('/[Zz]\z/', '+00:00', $value);
        foreach ([DATE_ATOM, 'Y-m-d\TH:i:s.vP'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($date instanceof DateTimeImmutable && $date->format($format) === $value) {
                $offsetMinutes = intdiv($date->getOffset(), 60);
                if ($offsetMinutes < self::MIN_UTC_OFFSET_MINUTES || $offsetMinutes > self::MAX_UTC_OFFSET_MINUTES) {
                    return null;
                }

                return $date->setTimezone(new DateTimeZone(date_default_timezone_get()));
            }
        }

        return null;
    }

    /**
     * Optional RFC 3339 field: null when absent or empty, false when malformed (surrounding whitespace included).
     *
     * @param array<string, mixed> $payload
     */
    private function optionalDateTime(array $payload, string $key): DateTimeImmutable|false|null
    {
        if (!isset($payload[$key])) {
            return null;
        }

        $value = $payload[$key];
        if (!is_string($value)) {
            return false;
        }

        if ($value === '') {
            return null;
        }

        return $this->dateTime($value) ?? false;
    }

    /**
     * Validated `reassign` input, or the 400 answer.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{taskId: string, followUp: IdleFollowUp, source: ClientType}|JsonResponse
     */
    private function reassignInput(array $payload, ClientApiPrincipal $principal, Request $request): array|JsonResponse
    {
        if (!$this->settingsProvider->getSettings()->allowIdleReassign) {
            return $this->responseFactory->error('invalid_request', 'action reassign is not available.', Response::HTTP_BAD_REQUEST, $request);
        }

        $taskId = $this->string($payload, 'taskId');
        if ($taskId === '') {
            return $this->responseFactory->error('invalid_request', 'taskId is required to reassign an idle span.', Response::HTTP_BAD_REQUEST, $request);
        }

        $then     = $payload['then'] ?? null;
        $followUp = $then === null ? IdleFollowUp::Continue : (is_string($then) ? IdleFollowUp::tryFrom($then) : null);
        if (!$followUp instanceof IdleFollowUp) {
            return $this->responseFactory->error('invalid_request', 'then must be one of continue, switch, stop.', Response::HTTP_BAD_REQUEST, $request);
        }

        $source = $this->source($payload, $principal);
        if (!$source instanceof ClientType) {
            return $this->invalidSource($request);
        }

        return ['taskId' => $taskId, 'followUp' => $followUp, 'source' => $source];
    }

    private function clientType(string $value): ClientType
    {
        return ClientType::tryFrom($value) ?? ClientType::Extension;
    }
}
