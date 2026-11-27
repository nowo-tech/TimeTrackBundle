<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Controller;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\Calendar\CalendarConnectionService;
use Nowo\TimeTrackBundle\Client\ClientAllocationInput;
use Nowo\TimeTrackBundle\Client\ClientAllocationPresenter;
use Nowo\TimeTrackBundle\Client\ClientApiAuditor;
use Nowo\TimeTrackBundle\Client\ClientApiGuard;
use Nowo\TimeTrackBundle\Client\ClientApiPrincipal;
use Nowo\TimeTrackBundle\Client\ClientApiSettingsProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientAuthResult;
use Nowo\TimeTrackBundle\Client\ClientCapabilitiesProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientCapabilitiesResolver;
use Nowo\TimeTrackBundle\Client\ClientContextResolver;
use Nowo\TimeTrackBundle\Client\ClientDaysOffNormalizer;
use Nowo\TimeTrackBundle\Client\ClientLoginRateLimiter;
use Nowo\TimeTrackBundle\Client\ClientMinSupportedVersionProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientPrivacyNormalizer;
use Nowo\TimeTrackBundle\Client\ClientResponseFactory;
use Nowo\TimeTrackBundle\Client\ClientTaskPresenter;
use Nowo\TimeTrackBundle\Client\ClientWorkingHoursNormalizer;
use Nowo\TimeTrackBundle\Client\ClientWorkingTimeContext;
use Nowo\TimeTrackBundle\Client\ConfigClientApiSettingsProvider;
use Nowo\TimeTrackBundle\Client\DeviceScopeFamily;
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
use Nowo\TimeTrackBundle\Exception\PeriodLockedException;
use Nowo\TimeTrackBundle\Exception\PeriodWriteRefusedException;
use Nowo\TimeTrackBundle\Exception\TaskNotTrackableException;
use Nowo\TimeTrackBundle\Exception\TimeAllocationException;
use Nowo\TimeTrackBundle\Exception\TimeAllocationRequiredException;
use Nowo\TimeTrackBundle\Meeting\MeetingContext;
use Nowo\TimeTrackBundle\Monitoring\MonitoringContext;
use Nowo\TimeTrackBundle\Port\Internal\ClientUserDaysOffProviderInterface;
use Nowo\TimeTrackBundle\Port\Internal\ClientUserLocaleProviderInterface;
use Nowo\TimeTrackBundle\Port\Internal\ClientUserPrivacyProviderInterface;
use Nowo\TimeTrackBundle\Port\Internal\ClientUserProfileProviderInterface;
use Nowo\TimeTrackBundle\Port\Internal\TaskAccessCheckerInterface;
use Nowo\TimeTrackBundle\Port\Ports;
use Nowo\TimeTrackBundle\Routing\TimeTrackRouteLoader;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Service\ManualEntryService;
use Nowo\TimeTrackBundle\Service\TeamAccessGuard;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Subject\AllocationLabels;
use Nowo\TimeTrackBundle\Subject\TimeAllocation;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;
use Nowo\TimeTrackBundle\Support\NoteSanitizer;
use Nowo\TimeTrackBundle\Support\QueryPeriodResolver;
use Nowo\TimeTrackBundle\Support\TimeEntryCsv;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScope;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_map;
use function array_replace;
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
use function strtolower;
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
#[Route(requirements: TimeTrackRouteLoader::REQUIREMENTS)]
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

    /** Since 1.14 (contract Q-B22): the only metadata accepted without `client_api.allow_metadata` — "track the meeting". */
    public const MEETING_ORIGIN = ['origin' => 'meeting'];

    /**
     * Features of `GET me` reserved for later releases; always false (1.13: `workday`, 1.26: `monitoring` became real).
     * Empty since 1.26; kept for BC.
     *
     * @var list<string>
     */
    public const RESERVED_FEATURES = [];

    /** Earliest UTC offset accepted on client timestamps, in minutes (-12:00; 1.9.2). */
    public const MIN_UTC_OFFSET_MINUTES = -720;

    /** Latest UTC offset accepted on client timestamps, in minutes (+14:00; 1.9.2). */
    public const MAX_UTC_OFFSET_MINUTES = 840;

    /** `me.locale` values accepted (1.11): a language, then up to three subtags (`es`, `pt_BR`, `zh-Hant-TW`). */
    public const LOCALE_PATTERN = '/^[A-Za-z]{2,3}(?:[_-][A-Za-z0-9]{2,8}){0,3}$/D';

    /** Translation domain of the masked title (1.11). */
    private const TRANSLATION_DOMAIN = 'NowoTimeTrackBundle';

    /** Since 1.12: languages the bundle ships translations for; the masked title is translated only into these. */
    public const TRANSLATION_LOCALES = ['de', 'en', 'es', 'fr', 'it', 'nl', 'pt'];

    private ClockInterface $clock;

    private LoggerInterface $logger;

    private ClientTaskPresenter $taskPresenter;

    private ClientApiSettingsProviderInterface $settingsProvider;

    private IdleCheckSettingsResolver $idleCheckResolver;

    /**
     * Receives the services of the client API.
     *
     * @param TeamAccessGuard $teamAccessGuard @deprecated since 2.0, ignored (it only checked the removed `GET entries?userId=`); removed in 3.0
     * @param int $idleThresholdSeconds @deprecated since 2.0, ignored (it was only answered by the removed `POST heartbeat`); removed in 3.0
     * @param bool $allowMetadata `client_api.allow_metadata`: accept free `metadata` on timer start (default false)
     * @param ClockInterface|null $clock Reference time of the default periods (defaults to the system clock)
     * @param LoggerInterface|null $logger Logs throttled logins without username or IP and (1.12) malformed working hours / days off without user data (defaults to no logging)
     * @param ClientMinSupportedVersionProviderInterface|null $minSupportedVersionProvider `me.minSupportedVersion` source (null = key never sent)
     * @param ClientTaskPresenter|null $taskPresenter Task details of tasks and timers (null = request origin, no logging)
     * @param ClientApiSettingsProviderInterface|null $settingsProvider Backdate and idle settings (null = the defaults)
     * @param IdleCheckSettingsResolver|null $idleCheckResolver Per-user idle-check settings of `me` (null = instance settings)
     * @param TranslatorInterface|null $translator Translates the masked title of imported entries (1.11; null = English)
     * @param ClientWorkingTimeContext|null $workingTime Working-time flags, `me.timesheet` and entry locks (1.13; null = all off)
     * @param CalendarConnectionService|null $calendar Calendar suggestions (`me.features.calendar_suggestions`, 1.14; null = off)
     * @param ManualEntryService|null $manualEntries `POST entries` (`me.features.manual_entries`, 1.14; null = off)
     * @param MeetingContext|null $meetings Meetings API (`me.features.meetings`, `me.meetings`, `me.links.calendars`, 1.14; null = off)
     * @param MonitoringContext|null $monitoring Activity monitoring (`me.features.monitoring`, `me.monitoring`, 1.26; null = off)
     * @param ClientAllocationPresenter|null $allocations Since 2.1.0 (spec 256): subjects and activity types (null = none: every subject refused or masked)
     * @param ClientContextResolver|null $contexts Since 2.2.0: `me.contexts` / `me.context` with a context policy (null = no keys)
     * @param ClientCapabilitiesResolver|null $capabilities Since 2.4.0: `GET me/capabilities` and the module check of `login` (null = every registered family usable)
     */
    // @phpstan-ignore constructor.unusedParameter, constructor.unusedParameter
    public function __construct(
        private ClientAuthService $authService,
        private TimerService $timerService,
        TeamAccessGuard $teamAccessGuard,
        private ClientResponseFactory $responseFactory,
        private ClientLoginRateLimiter $loginRateLimiter,
        int $idleThresholdSeconds,
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
        private ?ClientWorkingTimeContext $workingTime = null,
        private ?CalendarConnectionService $calendar = null,
        private ?ManualEntryService $manualEntries = null,
        private ?MeetingContext $meetings = null,
        /** @var list<string> Since 1.15: `framework.enabled_locales`, what the `privacy` language is chosen among */
        private array $enabledLocales = [],
        private ?MonitoringContext $monitoring = null,
        private ?ClientAllocationPresenter $allocations = null,
        private ?ClientContextResolver $contexts = null,
        private ?ClientCapabilitiesResolver $capabilities = null,
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
    #[Route('/api/time-track/login', name: 'nowo_time_track_api_login', methods: ['POST', 'OPTIONS'])]
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
        $user = $result->requireUser();
        // Since 2.4.0 (Beacon spec 262): a password login grants the time family only to a person who may use it.
        if ($this->capabilities?->isEnforced() === true && !$this->capabilities->canUse($user, DeviceScopeFamily::TIME, null)) {
            $this->auditor->record(ClientApiAuditAction::RequestDenied, $user, $result->getCredentialId(), $clientType, $clientIp, ClientCapabilitiesResolver::MODULE_FORBIDDEN, DeviceScopeFamily::TIME);

            return $this->guard->moduleForbidden($request, DeviceScopeFamily::TIME);
        }
        // Since 1.26 (Beacon Q5): a password never grants monitoring:write.
        $token = $this->authService->issueToken($user, $clientType, $result->getCredentialId(), ClientScope::grantableByPassword($result->getScopes()));
        $this->auditor->record(ClientApiAuditAction::TokenIssued, $user, $result->getCredentialId(), $clientType, $clientIp, null, $token['scopeFamily']);

        return $this->responseFactory->json($token, Response::HTTP_OK, $request);
    }

    /**
     * POST logout: revokes the presented token.
     */
    #[Route('/api/time-track/logout', name: 'nowo_time_track_api_logout', methods: ['POST', 'OPTIONS'])]
    public function logout(Request $request): Response
    {
        $limited = $this->preflightOrIpLimit($request);
        if ($limited instanceof Response) {
            return $limited;
        }

        $plain = ClientApiGuard::bearerToken($request);
        // Since 2.2.0: the time API revokes time tokens only (a token of another family is revoked through its own API).
        $revoked = $plain !== null ? $this->authService->logout($plain, DeviceScopeFamily::TIME) : null;
        if ($revoked instanceof ClientToken) {
            $this->auditor->record(ClientApiAuditAction::TokenRevoked, $revoked->getUser(), $revoked->getCredentialId(), $revoked->getClientType(), $request->getClientIp(), null, $revoked->getScopeFamily());
        }

        return $this->responseFactory->empty(Response::HTTP_NO_CONTENT, $request);
    }

    /**
     * GET me: user id, identifier, display name, scopes and working hours of the token user, plus
     * `minSupportedVersion` for the token client type when the host sets a valid semver one (1.9.1), and (1.10)
     * `features` (object of booleans; since 1.26 `monitoring` is true only with a host policy for the user) and `limits` (backdate and
     * idle-check settings for the token user), and (1.11) `locale` (the user's preferred locale, or null), and (1.12)
     * `daysOff` (holidays / vacations today → +60 days, or null); since 1.12 `workingHours` is normalised to the `slots`
     * shape ({@see ClientWorkingHoursNormalizer}), and (1.15) `privacy` when the profile provider implements
     * {@see ClientUserPrivacyProviderInterface} (no key otherwise), and (1.22, spec 204) `features.leave` and
     * `links.leave` and `leave` days in `daysOff` only with a `client_api.leave_provider` (no keys otherwise), and (2.2.0)
     * `contexts` and `context` only with a `client_api.context.policy` (no keys otherwise).
     */
    #[Route('/api/time-track/me', name: 'nowo_time_track_api_me', methods: ['GET', 'OPTIONS'])]
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
        // Since 1.23.0 also the framework-free ClientUserPrivacySourceInterface ({@see Ports}).
        $privacy = Ports::privacy($this->profileProvider);
        if ($privacy instanceof ClientUserPrivacyProviderInterface) {
            $body['privacy'] = ClientPrivacyNormalizer::normalize($privacy->getPrivacy($user, $this->privacyLocale($request)), $this->logger);
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
            // Since 1.13 (contract §10.1).
            'workday'              => false,
            'timesheets'           => false,
            'balance'              => false,
            'calendar_suggestions' => false,
            'manual_entries'       => false,
            'task_effort'          => true,
        ];
        if ($this->workingTime instanceof ClientWorkingTimeContext) {
            $features = array_replace($features, $this->workingTime->features($user));
        }
        // Since 1.14 (contract §10.1).
        $features['calendar_suggestions'] = $this->calendar?->scopeOf($user, null) instanceof TimesheetScope;
        $features['manual_entries']       = $this->manualEntries?->isEnabled() ?? false;
        // Since 1.14 (contract §22.8, spec 210).
        $meetings             = $this->meetings?->summary($user);
        $features['meetings'] = $meetings !== null;
        // Since 1.22 (spec 204 FR-029): only with a leave provider (no key otherwise, as in 1.21); `GET workday` carries it.
        $reportsLeave = $this->workingTime?->reportsLeave() ?? false;
        if ($reportsLeave) {
            $features['leave'] = $features['workday'];
        }
        // Since 1.26 (contract §24.1, spec 199): true only with a policy for this user (no `monitoring` key otherwise).
        $monitoring             = $this->monitoring?->summary($user);
        $features['monitoring'] = $monitoring !== null;
        // Since 2.1.0 (contract v0.4, spec 256): subject types, activity types and the allocation requirements.
        $features += $this->allocations?->features($this->userLocale($user)) ?? ['subjects' => [], 'activityTypes' => false, 'requireAllocation' => false, 'requireActivityType' => false];

        $body['features'] = $features;
        $body['limits']   = [
            'backdate'   => ['max_seconds' => $settings->backdate->maxSeconds, 'clock_skew_seconds' => $settings->backdate->clockSkewSeconds],
            'idle_check' => $idleCheck->limitsToArray(),
        ];
        // Since 1.13: the current period of the current scope (null without timesheets).
        $body['timesheet'] = $this->workingTime?->timesheetSummary($user);
        // Since 1.14: meeting sync state (null when off) and the host's Calendars page for the Connect button.
        $body['meetings'] = $meetings;
        if ($monitoring !== null) {
            $body['monitoring'] = $monitoring;
        }
        $body['links'] = [
            'calendars' => $this->meetings?->calendarsLink(),
            // Since 1.19 (spec 198): the host's working-time page, only while the workday API is on for the user.
            'workingTime' => $features['workday'] ? $this->workingTime?->workingTimeLink() : null,
        ];
        if ($reportsLeave) {
            // Since 1.22 (spec 204): the host's Leave page, only while the workday API is on for the user (audit NG-4).
            $body['links']['leave'] = $features['workday'] ? $this->workingTime?->leaveLink() : null;
        }
        // Since 2.2.0 (contract v0.5, Q-BD-7): with a context policy, the contexts the user may name and the one this request named.
        $contexts = $this->contexts?->contextsOf($user, DeviceScopeFamily::TIME);
        if ($contexts !== null) {
            $body['contexts'] = $contexts;
            $body['context']  = $principal->contextKey;
        }

        return $this->responseFactory->json($body, Response::HTTP_OK, $request);
    }

    /**
     * Since 2.4.0 (Beacon spec 262 block B): GET me/capabilities — the modules (scope families) the token's person may use
     * now, in the context the request names, from the host {@see ClientCapabilitiesProviderInterface}. Takes a token of
     * **any** registered family and skips the module check (a device whose module was revoked still learns what it may
     * use); every other check of the client API applies (`401 invalid_token`, `403 context_forbidden`, `429`).
     *
     * `200 {"userId", "scopeFamily", "scopes", "context", "modules": {"time": bool, "chat": bool, "tasks": bool, …},
     * "grantableScopes": {"<family>": [scope, …]}}`, `Cache-Control: no-store, private`.
     */
    #[Route('/api/time-track/me/capabilities', name: 'nowo_time_track_api_me_capabilities', methods: ['GET', 'OPTIONS'])]
    public function capabilities(Request $request): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->responseFactory->empty(Response::HTTP_NO_CONTENT, $request);
        }
        $principal = $this->guard->authenticateAnyFamily($request);
        if ($principal instanceof Response) {
            return $principal;
        }

        $capabilities = ($this->capabilities ?? new ClientCapabilitiesResolver())->capabilitiesOf($principal->user, $principal->contextKey);
        $response     = $this->responseFactory->json([
            'userId'          => UserIdResolver::getId($principal->user),
            'scopeFamily'     => $principal->token->getScopeFamily(),
            'scopes'          => $principal->token->getScopeValues(),
            'context'         => $principal->contextKey,
            'modules'         => $capabilities['modules'],
            'grantableScopes' => (object) $capabilities['grantableScopes'],
        ], Response::HTTP_OK, $request);
        // @igor-ignore - Request-scoped value or I/O delegate (response, clock value, repository call); no worker state.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * GET tasks: trackable tasks (`search`, `limit` 1-50, `offset`).
     */
    #[Route('/api/time-track/tasks', name: 'nowo_time_track_api_tasks', methods: ['GET', 'OPTIONS'])]
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
            'tasks' => $this->taskPresenter->tasks($this->timerService->listTasks($principal->user, $query), $request, $principal->user),
        ], Response::HTTP_OK, $request);
    }

    /**
     * GET timer: the active timer (204 when none).
     */
    #[Route('/api/time-track/timer', name: 'nowo_time_track_api_timer', methods: ['GET', 'OPTIONS'])]
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

        return $this->responseFactory->json(['timer' => $this->timerBody($active, $principal->user, $request)], Response::HTTP_OK, $request);
    }

    /**
     * POST timer/start `{taskId, note?, source?, startedAt?}`: starts a timer (403 task_forbidden, 409 timer_running).
     * Since 1.10 an RFC 3339 `startedAt` backdates the start after the server clamps (reported as `startedAtResult`).
     * Since 2.1.0 (contract v0.4): `taskId`, `subject: {type, id}`, or both when the type allows tasks (neither only with
     * `entries.allow_without_task` on and `entries.require_allocation` off), `activityTypeKey?`, `scopeKey?` (ignored with
     * a task); task access is checked first (403 task_forbidden), then the subject (403 subject_forbidden, 422
     * allocation_conflict / activity_type_unknown / activity_type_inactive).
     */
    #[Route('/api/time-track/timer/start', name: 'nowo_time_track_api_timer_start', methods: ['POST', 'OPTIONS'])]
    public function timerStart(Request $request): Response
    {
        $principal = $this->authorize($request, ClientScope::TimerWrite);
        if ($principal instanceof Response) {
            return $principal;
        }

        $payload    = $this->payload($request);
        $allocation = $this->allocationInput($payload, $request);
        if ($allocation instanceof Response) {
            return $allocation;
        }
        [$allocation, $scopeKey] = $allocation;

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

        if ($allocation->taskId !== null && !$this->taskAccessChecker->canTrack($principal->user, $allocation->taskId)) {
            return $this->taskForbidden($principal, $request);
        }

        try {
            $result = $this->timerService->startAt($principal->user, $allocation, $clientType, $metadata, $startedAt, $this->settingsProvider->getSettings()->backdate, scopeKey: $scopeKey);
        } catch (ActiveTimerConflictException) {
            return $this->timerRunning($request);
        } catch (TimeAllocationException $e) {
            return $this->allocationRefused($e, $principal, $request);
        } catch (InvalidArgumentException) {
            return $this->taskForbidden($principal, $request);
        } catch (PeriodWriteRefusedException $e) {
            return $this->periodRefused($e, $request);
        }
        $this->auditSubject($principal, $request, $allocation->subject, $clientType);

        $body = ['timer' => $this->timerBody($result['timer'], $principal->user, $request, $result['task'])];
        if ($result['startTime'] instanceof ClampedTime) {
            $body['startedAtResult'] = $result['startTime']->toArray();
        }

        return $this->responseFactory->json($body, Response::HTTP_CREATED, $request);
    }

    /**
     * POST timer/stop `{note?, endedAt?}`: stops the active timer and returns the entry. Since 1.10 an RFC 3339
     * `endedAt` sets an earlier end after the server clamps (reported as `endedAtResult`). Since 2.1.0 (contract v0.4)
     * optional `subject` (`null` clears it) and `activityTypeKey`; an interactive stop: with `entries.require_allocation`
     * a timer with neither task nor subject is refused with `409 allocation_required` and `{timer}` (nothing is written,
     * the timer keeps running; stop again with the `endedAt` the dialog showed).
     */
    #[Route('/api/time-track/timer/stop', name: 'nowo_time_track_api_timer_stop', methods: ['POST', 'OPTIONS'])]
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

        try {
            $patch = ClientAllocationInput::patch($payload);
        } catch (InvalidArgumentException $e) {
            return $this->responseFactory->error('invalid_request', $e->getMessage(), Response::HTTP_BAD_REQUEST, $request);
        }

        // Audit ZBQ-L3: a change of the subject of a task timer asks the task access again first.
        $active = $patch->changesSubject ? $this->timerService->getActive($principal->user) : null;
        if ($active instanceof ActiveTimer && $active->hasTask() && $patch->changesSubjectOf($active->subject()) && !$this->taskAccessChecker->canTrack($principal->user, $active->getTaskId())) {
            return $this->taskForbidden($principal, $request);
        }

        try {
            $result = $this->timerService->stopAt($principal->user, $endedAt, $this->settingsProvider->getSettings()->backdate, $this->note($payload), $patch, true);
        } catch (TimeAllocationException $e) {
            return $this->allocationRefused($e, $principal, $request);
        } catch (TaskNotTrackableException) {
            return $this->taskForbidden($principal, $request);
        }
        if ($result === null) {
            return $this->noActiveTimer($request);
        }
        $this->auditSubject($principal, $request, $patch->changesSubject ? $patch->subject : null, null);

        $body = ['entry' => $this->entryBody($result['entry'], $principal->user, $request)];
        if ($result['endTime'] instanceof ClampedTime) {
            $body['endedAtResult'] = $result['endTime']->toArray();
        }

        return $this->responseFactory->json($body, Response::HTTP_OK, $request);
    }

    /**
     * POST timer/switch `{taskId, note?, source?, metadata?}`: stops the running timer (if any, with the note) and starts
     * one on the task. The task is checked first: a refused task leaves the running timer untouched. Since 1.14 the only
     * accepted `metadata` is `{origin: "meeting"}` (*Track the meeting*, Q-B22), kept on the new timer and its entry.
     * Since 2.1.0 (contract v0.4) the same allocation body as `timer/start` for the next timer, checked before anything
     * stops.
     */
    #[Route('/api/time-track/timer/switch', name: 'nowo_time_track_api_timer_switch', methods: ['POST', 'OPTIONS'])]
    public function timerSwitch(Request $request): Response
    {
        $principal = $this->authorize($request, ClientScope::TimerWrite);
        if ($principal instanceof Response) {
            return $principal;
        }

        $payload    = $this->payload($request);
        $allocation = $this->allocationInput($payload, $request);
        if ($allocation instanceof Response) {
            return $allocation;
        }
        [$allocation, $scopeKey] = $allocation;

        $clientType = $this->source($payload, $principal);
        if (!$clientType instanceof ClientType) {
            return $this->invalidSource($request);
        }

        // Since 1.14 (Q-B22): "track the meeting" marks the new timer with `metadata: {origin: "meeting"}`; nothing else.
        $origin = $payload['metadata'] ?? null;
        if (!in_array($origin, [null, [], self::MEETING_ORIGIN], true)) {
            return $this->responseFactory->error('metadata_not_allowed', 'timer/switch accepts only metadata {"origin": "meeting"}.', Response::HTTP_BAD_REQUEST, $request);
        }

        if ($allocation->taskId !== null && !$this->taskAccessChecker->canTrack($principal->user, $allocation->taskId)) {
            return $this->taskForbidden($principal, $request);
        }

        try {
            $result = $this->timerService->switchTo($principal->user, $allocation, $clientType, $this->note($payload), $origin === self::MEETING_ORIGIN ? self::MEETING_ORIGIN : [], $scopeKey);
        } catch (ActiveTimerConflictException) {
            return $this->timerRunning($request);
        } catch (TimeAllocationException $e) {
            return $this->allocationRefused($e, $principal, $request);
        } catch (InvalidArgumentException) {
            return $this->taskForbidden($principal, $request);
        } catch (PeriodWriteRefusedException $e) {
            return $this->periodRefused($e, $request);
        }
        $this->auditSubject($principal, $request, $allocation->subject, $clientType);

        return $this->responseFactory->json([
            'entry' => $result['entry'] instanceof TimeEntry ? $this->entryBody($result['entry'], $principal->user, $request) : null,
            'timer' => $this->timerBody($result['timer'], $principal->user, $request),
        ], Response::HTTP_CREATED, $request);
    }

    /**
     * POST timer/discard-idle `{from, to, action: keep|discard|split|reassign, taskId?, then?, note?, source?}` (`note` on
     * `keep` since 1.10.1): the
     * user's decision about an idle span the client detected locally (replaces the server-side idle flag of the
     * heartbeat). `from` / `to` are converted to the server time zone and must carry an offset between -12:00 and
     * +14:00 (1.9.2). 409 timer_running when a concurrent start took the timer slot before the follow-up timer started
     * (1.9.1). Since 1.10 `reassign` gives the span to `taskId` (checked like `timer/start`: an unknown and a foreign
     * task both answer the same `403 task_forbidden`) and then continues, switches or stops (`then`). Since 2.1.0
     * (contract v0.4) the reassign target may be a `subject` (with or without `taskId`) and an `activityTypeKey`.
     */
    #[Route('/api/time-track/timer/discard-idle', name: 'nowo_time_track_api_timer_discard_idle', methods: ['POST', 'OPTIONS'])]
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

            if ($taskId->taskId !== null && !$this->taskAccessChecker->canTrack($principal->user, $taskId->taskId)) {
                return $this->taskForbidden($principal, $request);
            }
        }

        try {
            $result = $this->timerService->resolveIdle($principal->user, $from, $to, $resolution, $taskId, $followUp, $source, $note);
        } catch (ActiveTimerConflictException) {
            return $this->timerRunning($request);
        } catch (TimeAllocationException $e) {
            return $this->allocationRefused($e, $principal, $request);
        } catch (TaskNotTrackableException) {
            return $this->taskForbidden($principal, $request);
        } catch (InvalidArgumentException $e) {
            return $this->responseFactory->error('invalid_request', $e->getMessage(), Response::HTTP_BAD_REQUEST, $request);
        } catch (PeriodWriteRefusedException $e) {
            return $this->periodRefused($e, $request);
        }

        if ($result === null) {
            return $this->noActiveTimer($request);
        }

        $this->auditSubject($principal, $request, $taskId?->subject, $source);

        return $this->responseFactory->json([
            'entry'      => $result['entry'] instanceof TimeEntry ? $this->entryBody($result['entry'], $principal->user, $request) : null,
            'timer'      => $result['timer'] instanceof ActiveTimer ? $this->timerBody($result['timer'], $principal->user, $request) : null,
            'reassigned' => $result['reassigned'] instanceof TimeEntry ? $this->entryBody($result['reassigned'], $principal->user, $request) : null,
        ], Response::HTTP_OK, $request);
    }

    /**
     * GET entries: own time entries of a period (`from`, `to`, `limit` 1-100, `offset`). Since 2.0 the 1.x `userId`
     * parameter (deprecated since 1.9) is gone: the caller's own entries only.
     */
    #[Route('/api/time-track/entries', name: 'nowo_time_track_api_entries', methods: ['GET', 'OPTIONS'])]
    public function entries(Request $request): Response
    {
        $principal = $this->authorize($request, ClientScope::EntriesRead);
        if ($principal instanceof Response) {
            return $principal;
        }

        $period = QueryPeriodResolver::resolve($request->query->getString('from'), $request->query->getString('to'), $this->clock->now());
        $limit  = max(1, min(self::ENTRIES_MAX_LIMIT, $request->query->getInt('limit', self::ENTRIES_MAX_LIMIT)));
        $offset = max(0, $request->query->getInt('offset', 0));
        $user   = $principal->user;
        $list   = $this->timerService->listOwnEntries($user, $period['from'], $period['to'], $limit, $offset);

        $hidden      = $this->timerService->hiddenTaskEntryIds($list);
        $hiddenTitle = $hidden === [] ? TimeEntry::HIDDEN_TASK_TITLE : $this->hiddenTaskTitle($user);
        $locked      = $this->workingTime?->blockedEntryIds($list) ?? [];
        // Since 2.1.0 (contract v0.4): subject and activity type, labelled once per page with the token's user as viewer.
        $labels = $this->allocations?->labelsFor($user, $list) ?? AllocationLabels::none();

        return $this->responseFactory->json([
            'entries' => array_map(fn (TimeEntry $entry): array => $entry->toArray(!isset($hidden[$entry->getId()]), $hiddenTitle) + ['locked' => isset($locked[$entry->getId()])] + $this->allocationFields($entry, $labels, $request), $list),
            'limit'   => $limit,
            'offset'  => $offset,
        ], Response::HTTP_OK, $request);
    }

    /**
     * GET export `?from&to&format=csv|json`: the user's own entries of the period (RGPD art. 15/20), as a download.
     */
    #[Route('/api/time-track/export', name: 'nowo_time_track_api_export', methods: ['GET', 'OPTIONS'])]
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
        $locked  = $this->workingTime?->blockedEntryIds($entries) ?? [];
        // Since 2.1.0 (contract v0.4): subject and activity type, labelled once per page with the token's user as viewer.
        $labels = $this->allocations?->labelsFor($principal->user, $entries) ?? AllocationLabels::none();

        $response = $this->responseFactory->empty(Response::HTTP_OK, $request);
        // @igor-ignore - Request-scoped value or I/O delegate (response, clock value, repository call); no worker state.
        $response->setContent($format === 'csv'
            ? TimeEntryCsv::render($entries, $hidden, $masked, $locked, $labels)
            : json_encode([
                'from'    => $period['from']->format(DATE_ATOM),
                'to'      => $period['to']->format(DATE_ATOM),
                'entries' => array_map(fn (TimeEntry $entry): array => $entry->toArray(!isset($hidden[$entry->getId()]), $masked) + ['locked' => isset($locked[$entry->getId()])] + $this->allocationFields($entry, $labels, $request), $entries),
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
     * Since 1.15: the language `privacy` is asked in: the best match of `Accept-Language` among the enabled locales (any
     * language without a list), or null without the header, so the host uses the user's own.
     */
    private function privacyLocale(Request $request): ?string
    {
        if (trim((string) $request->headers->get('Accept-Language')) === '') {
            return null;
        }
        $locale = $request->getPreferredLanguage($this->enabledLocales === [] ? null : $this->enabledLocales);

        return is_string($locale) && preg_match(self::LOCALE_PATTERN, $locale) === 1 ? $locale : null;
    }

    /**
     * Since 1.11: the user's preferred locale for `me` — the profile provider's when it implements
     * {@see ClientUserLocaleProviderInterface}, else the user's own `getLocale()` when it has one callable without
     * arguments (since 1.12) — or null when unknown or not a locale ({@see self::LOCALE_PATTERN}).
     */
    private function userLocale(UserInterface $user): ?string
    {
        $provider = Ports::locale($this->profileProvider);
        if ($provider instanceof ClientUserLocaleProviderInterface) {
            $locale = $provider->getLocale($user);
        } else {
            $locale = method_exists($user, 'getLocale') && $this->isArgumentless(new ReflectionMethod($user, 'getLocale')) ? $user->getLocale() : null;
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
        $provider = Ports::daysOff($this->profileProvider);
        if (!$provider instanceof ClientUserDaysOffProviderInterface) {
            return null;
        }

        [$from, $to] = ClientDaysOffNormalizer::range($this->clock->now(), $timezone ?? date_default_timezone_get());

        // Since 1.22: the `leave` kind only when the host reports leave (audit NG-1; without it the 1.21 output).
        return ClientDaysOffNormalizer::normalize($provider->getDaysOff($user, $from, $to), $from->format('Y-m-d'), $to->format('Y-m-d'), $this->logger, $this->workingTime?->reportsLeave() ?? false);
    }

    /**
     * Since 1.11: the title of a masked imported entry (`reports.task_not_visible` in the user's language when the
     * bundle ships it — since 1.12, so an arbitrary tag never makes the translator build a catalogue — else the
     * translator's default locale; English without a translator).
     */
    private function hiddenTaskTitle(UserInterface $user): string
    {
        if (!$this->translator instanceof TranslatorInterface) {
            return TimeEntry::HIDDEN_TASK_TITLE;
        }

        $title = $this->translator->trans('reports.task_not_visible', [], self::TRANSLATION_DOMAIN, $this->translationLocale($this->userLocale($user)));

        return $title === '' || $title === 'reports.task_not_visible' ? TimeEntry::HIDDEN_TASK_TITLE : $title;
    }

    /**
     * Since 1.12: the shipped language of a `me.locale` value (`pt_BR` → `pt`), or null (the translator's default).
     */
    private function translationLocale(?string $locale): ?string
    {
        if ($locale === null) {
            return null;
        }
        $language = strtolower((string) preg_replace('/[_-].*$/s', '', $locale));

        return in_array($language, self::TRANSLATION_LOCALES, true) ? $language : null;
    }

    /**
     * Since 1.12: whether a method is public, non-static and needs no argument (a user `getLocale()` that takes
     * arguments would otherwise make `GET me` fail).
     */
    private function isArgumentless(ReflectionMethod $method): bool
    {
        return $method->isPublic() && !$method->isStatic() && $method->getNumberOfRequiredParameters() === 0;
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

    /**
     * Since 1.13: 409 `period_locked` (with `lockedThrough`) or `period_submitted`.
     */
    private function periodRefused(PeriodWriteRefusedException $e, Request $request): JsonResponse
    {
        $body = ['error' => $e->errorCode(), 'message' => $e instanceof PeriodLockedException ? 'The period is locked.' : 'The period is submitted for approval.'];
        if ($e instanceof PeriodLockedException) {
            $body['lockedThrough'] = $e->getLockedThrough();
        }

        return $this->responseFactory->json($body, Response::HTTP_CONFLICT, $request);
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
        if ($metadata !== null && $metadata !== [] && !$this->allowMetadata && $metadata !== self::MEETING_ORIGIN) {
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
     * @return array{taskId: TimeAllocation, followUp: IdleFollowUp, source: ClientType}|JsonResponse
     */
    private function reassignInput(array $payload, ClientApiPrincipal $principal, Request $request): array|JsonResponse
    {
        if (!$this->settingsProvider->getSettings()->allowIdleReassign) {
            return $this->responseFactory->error('invalid_request', 'action reassign is not available.', Response::HTTP_BAD_REQUEST, $request);
        }

        // Since 2.1.0: the target is a task, a subject, or both (with an optional activity type).
        $taskId = $this->string($payload, 'taskId');
        try {
            [, $subject]  = ClientAllocationInput::subject($payload);
            [, $activity] = ClientAllocationInput::activityTypeKey($payload);
            $target       = $taskId !== '' ? TimeAllocation::task($taskId, $subject, $activity) : ($subject instanceof TimeSubjectRef ? TimeAllocation::subject($subject, $activity) : null);
        } catch (InvalidArgumentException $e) {
            return $this->responseFactory->error('invalid_request', $e->getMessage(), Response::HTTP_BAD_REQUEST, $request);
        }
        if (!$target instanceof TimeAllocation) {
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

        return ['taskId' => $target, 'followUp' => $followUp, 'source' => $source];
    }

    /**
     * Since 2.1.0 (contract v0.4): the allocation of `timer/start` and `timer/switch` (`taskId`, `subject`,
     * `activityTypeKey`) and the requested `scopeKey`, or the 400 answer. Neither task nor subject only when time may be
     * unallocated.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{0: TimeAllocation, 1: string|null}|JsonResponse
     */
    private function allocationInput(array $payload, Request $request): array|JsonResponse
    {
        $taskId = $this->string($payload, 'taskId');
        try {
            [, $subject]  = ClientAllocationInput::subject($payload);
            [, $activity] = ClientAllocationInput::activityTypeKey($payload);
            $scopeKey     = ClientAllocationInput::scopeKey($payload);
        } catch (InvalidArgumentException $e) {
            return $this->responseFactory->error('invalid_request', $e->getMessage(), Response::HTTP_BAD_REQUEST, $request);
        }
        if ($taskId === '' && !$subject instanceof TimeSubjectRef && !($this->allocations?->allowsUnallocated() ?? false)) {
            return $this->responseFactory->error('invalid_request', 'taskId is required.', Response::HTTP_BAD_REQUEST, $request);
        }

        return [match (true) {
            $taskId !== ''                     => TimeAllocation::task($taskId, $subject, $activity),
            $subject instanceof TimeSubjectRef => TimeAllocation::subject($subject, $activity),
            default                            => TimeAllocation::none($activity),
        }, $taskId !== '' ? null : $scopeKey];
    }

    /**
     * Since 2.1.0: the answer to a refused subject or activity type (`403 subject_forbidden`, `409 allocation_required`
     * / `activity_type_required` with `{timer}` on a stop, 422 for the values). Refusals of the subject are audited
     * (throttled) like a refused task.
     */
    private function allocationRefused(TimeAllocationException $e, ClientApiPrincipal $principal, Request $request): JsonResponse
    {
        if ($e->errorCode === TimeAllocationException::SUBJECT_FORBIDDEN) {
            $this->auditor->denied('subject_forbidden', $principal->token, $request->getClientIp());

            return $this->responseFactory->error('subject_forbidden', 'You may not log time on this subject.', Response::HTTP_FORBIDDEN, $request);
        }
        $body = ['error' => $e->errorCode, 'message' => 'The allocation of this time was refused.'];
        if ($e instanceof TimeAllocationRequiredException) {
            $timer         = $this->timerService->getActive($principal->user);
            $body['timer'] = $timer instanceof ActiveTimer ? $this->timerBody($timer, $principal->user, $request) : null;
        }

        return $this->responseFactory->json($body, $e->httpStatus(), $request);
    }

    /**
     * Since 2.1.0: audits a write that set a subject (`subject_allocated`, reason = the subject type, never its id).
     */
    private function auditSubject(ClientApiPrincipal $principal, Request $request, ?TimeSubjectRef $subject, ?ClientType $clientType): void
    {
        if ($subject instanceof TimeSubjectRef) {
            $this->auditor->record(ClientApiAuditAction::SubjectAllocated, $principal->user, $principal->token->getCredentialId(), $clientType ?? $principal->token->getClientType(), $request->getClientIp(), $subject->type, $principal->token->getScopeFamily());
        }
    }

    /**
     * The wire shape of a timer: the task details and (since 2.1.0) its subject and activity type.
     *
     * @return array<string, mixed>
     */
    private function timerBody(ActiveTimer $timer, UserInterface $user, Request $request, ?TaskReference $known = null): array
    {
        return $this->taskPresenter->timer($timer, $user, $request, $known) + ($this->allocations?->timer($user, $timer, $request) ?? $this->allocationFields($timer, AllocationLabels::none(), null));
    }

    /**
     * The wire shape of one entry the caller just wrote (stop, switch, idle): with its subject and activity type.
     *
     * @return array<string, mixed>
     */
    private function entryBody(TimeEntry $entry, UserInterface $user, Request $request): array
    {
        return $entry->toArray() + ($this->allocations?->single($user, $entry, $request) ?? $this->allocationFields($entry, AllocationLabels::none(), null));
    }

    /**
     * Since 2.1.0: `subject` and `activityType` of an entry or a timer with labels already resolved for the page.
     *
     * @return array{subject: array<string, mixed>|null, activityType: array{key: string, label: string}|null}
     */
    private function allocationFields(TimeEntry|ActiveTimer $row, AllocationLabels $labels, ?Request $request): array
    {
        if ($row instanceof TimeEntry && $this->allocations instanceof ClientAllocationPresenter) {
            return $this->allocations->entry($row, $labels, $request);
        }

        return ['subject' => $labels->subject($row->getScopeKey(), $row->subject()), 'activityType' => $labels->activityType($row->getScopeKey(), $row->getActivityTypeKey())];
    }

    private function clientType(string $value): ClientType
    {
        return ClientType::tryFrom($value) ?? ClientType::Extension;
    }
}
