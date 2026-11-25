<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Service;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\Doctrine\FormerPersonSnapshotListener;
use Nowo\TimeTrackBundle\Dto\BackdateLimits;
use Nowo\TimeTrackBundle\Dto\ClampedTime;
use Nowo\TimeTrackBundle\Dto\TaskListQuery;
use Nowo\TimeTrackBundle\Dto\TaskReference;
use Nowo\TimeTrackBundle\Dto\TimeEntryPage;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\IdleFollowUp;
use Nowo\TimeTrackBundle\Enum\IdleResolution;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Enum\WriteKind;
use Nowo\TimeTrackBundle\Event\IdleResolvedEvent;
use Nowo\TimeTrackBundle\Event\TimeEntryListQueryEvent;
use Nowo\TimeTrackBundle\Event\TimeEntryUnallocatedEvent;
use Nowo\TimeTrackBundle\Event\TimerAllocatedEvent;
use Nowo\TimeTrackBundle\Event\TimerStartedWithoutTaskEvent;
use Nowo\TimeTrackBundle\Event\TimerStartEvent;
use Nowo\TimeTrackBundle\Event\TimerStopEvent;
use Nowo\TimeTrackBundle\Event\TimesheetLateEntryEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Exception\ActiveTimerConflictException;
use Nowo\TimeTrackBundle\Exception\PeriodLockedException;
use Nowo\TimeTrackBundle\Exception\PeriodSubmittedException;
use Nowo\TimeTrackBundle\Exception\TaskNotTrackableException;
use Nowo\TimeTrackBundle\Exception\TimeAllocationException;
use Nowo\TimeTrackBundle\Exception\TimeAllocationRequiredException;
use Nowo\TimeTrackBundle\Integration\PassThroughUserReferenceResolver;
use Nowo\TimeTrackBundle\Integration\ServiceAccountPolicyInterface;
use Nowo\TimeTrackBundle\Integration\TimeEntryBillableDefaultProviderInterface;
use Nowo\TimeTrackBundle\Port\Internal\BatchTaskProviderInterface;
use Nowo\TimeTrackBundle\Port\Internal\TaskProviderInterface;
use Nowo\TimeTrackBundle\Port\Internal\TimesheetScopeProviderInterface;
use Nowo\TimeTrackBundle\Port\Internal\UserReferenceResolverInterface;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefs;
use Nowo\TimeTrackBundle\Repository\ActiveTimerRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\ConditionalActiveTimerRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmActiveTimerRepository;
use Nowo\TimeTrackBundle\Repository\FormerPersonTimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\ImportedTimeEntryStoreInterface;
use Nowo\TimeTrackBundle\Repository\LatestTimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\PaginatedTimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TasklessScopedTimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryListing;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\UserSerialisedTimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Subject\ActivityType;
use Nowo\TimeTrackBundle\Subject\ActivityTypeList;
use Nowo\TimeTrackBundle\Subject\AllocationGuard;
use Nowo\TimeTrackBundle\Subject\TimeAllocation;
use Nowo\TimeTrackBundle\Subject\TimeAllocationPatch;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;
use Nowo\TimeTrackBundle\Support\NoteSanitizer;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\Timer\HostTimerApi;
use Nowo\TimeTrackBundle\Timer\TimerChannel;
use Nowo\TimeTrackBundle\Timer\TimerSnapshot;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;
use Nowo\TimeTrackBundle\Timesheet\ScopeKeys;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function array_diff;
use function array_filter;
use function array_keys;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function date_default_timezone_get;
use function in_array;
use function is_string;
use function max;
use function mb_strlen;
use function min;
use function preg_match;
use function sprintf;
use function usort;

/**
 * Timer use cases shared by the manage UI and the client API: one active timer per user, start / stop / heartbeat,
 * trackable tasks and the time entries of a period.
 *
 * Logging (REQ-OBS-001): time entries are personal data (GDPR), so log records carry the action and the client type
 * only — never the user, the task, its title, the board, the duration or the metadata.
 *
 * Since 1.13 (timesheets on): every entry gets the host tenant scope of its task (`scope_key`), and the period lock
 * ({@see PeriodLockGuard}) refuses a start or an idle decision in a locked or submitted period (409 `period_locked` /
 * `period_submitted`). A stop is always allowed: an entry belongs to its start date, which was writable at start;
 * when that period was locked while the timer ran (manual lock), the entry is written and
 * {@see TimesheetLateEntryEvent} fires.
 *
 * Since 1.36.0 (Beacon spec 250, `entries.allow_without_task`): a timer may run without a task (a null `$taskId` on
 * {@see self::startAt()} / {@see self::startReplacing()}), with the user's free text and a tenant scope that must be one
 * of the user's own ({@see ScopeKeys::forEntryWithoutTask()}). Its entry has no task, so it is only ever reached through
 * its owner and, for anyone else, through the existing per-user rules within the same tenant
 * ({@see self::listEntriesPage()}, {@see TeamAccessGuard}). With the option off a null task is refused like an unknown
 * task, and everything else behaves as in 1.35.
 *
 * Since 2.1.0 (Beacon spec 256): every write path takes a {@see TimeAllocation} where it took a task id (a task id or
 * null still works): a task, a task and a subject (a type that allows tasks), a subject alone, or nothing, plus an
 * optional activity type. The subject and the activity type are checked by {@see AllocationGuard} before the owner's
 * lock (task access stays the gate of a task entry; `canLog` is asked in addition when the subject is set or changed).
 * A subject entry needs no `entries.allow_without_task`: its tenant is one of the owner's scopes, chosen like the scope of
 * an entry without a task. With `entries.require_allocation` an interactive stop ({@see self::stopAt()} with
 * `interactive: true`) of a timer with neither task nor subject is refused inside the unit of work
 * ({@see TimeAllocationRequiredException}); stops the bundle triggers itself are never refused and raise
 * {@see TimeEntryUnallocatedEvent} instead.
 */
final readonly class TimerService
{
    private UserReferenceResolverInterface $userReferences;

    /** Max length of a note on a timer or an entry, in code points ({@see NoteSanitizer::MAX_LENGTH}). */
    public const NOTE_MAX_LENGTH = NoteSanitizer::MAX_LENGTH;

    /**
     * Since 1.25.0: timer metadata key of the channel a {@see HostTimerApi} timer was started through. Reserved: a client
     * cannot set it, and it moves to the entry's `channel` column when the timer stops.
     */
    public const CHANNEL_KEY = 'channel';

    /** Since 1.25.0: attempts of {@see self::startReplacing()} when a concurrent start takes the account's timer slot. */
    public const REPLACE_ATTEMPTS = 3;

    private ClockInterface $clock;

    private LoggerInterface $logger;

    private AllocationGuard $allocations;

    /**
     * @param TaskProviderInterface $taskProvider Trackable tasks of a user
     * @param ActiveTimerRepositoryInterface $activeTimerRepository Running timers (one per user)
     * @param TimeEntryRepositoryInterface $timeEntryRepository Closed time entries
     * @param EventDispatcherInterface $eventDispatcher Dispatches the timer and list-query events
     * @param ClockInterface|null $clock Time source (defaults to the system clock)
     * @param LoggerInterface|null $logger Operational log (defaults to no logging)
     * @param PeriodLockGuard|null $periodLockGuard Period lock (since 1.13; null = no timesheets)
     * @param TimesheetScopeProviderInterface|null $scopeProvider Tenant scope of new entries (since 1.13; null = none)
     */
    public function __construct(
        private TaskProviderInterface $taskProvider,
        private ActiveTimerRepositoryInterface $activeTimerRepository,
        private TimeEntryRepositoryInterface $timeEntryRepository,
        private EventDispatcherInterface $eventDispatcher,
        ?ClockInterface $clock = null,
        ?LoggerInterface $logger = null,
        private ?PeriodLockGuard $periodLockGuard = null,
        private ?TimesheetScopeProviderInterface $scopeProvider = null,
        ?UserReferenceResolverInterface $userReferences = null,
        // Since 1.21: the default `billable` of the entries a timer records (null = none, the flag stays unset).
        private ?TimeEntryBillableDefaultProviderInterface $billableDefaults = null,
        // Since 1.25.0: tells a service account (agent) from a person (null = everyone is a person).
        private ?ServiceAccountPolicyInterface $serviceAccounts = null,
        // Since 1.36.0: `entries.allow_without_task` (false = a null task is refused, the 1.35 behaviour).
        private bool $allowWithoutTask = false,
        // Since 2.1.0: subject and activity type checks (null = no provider, no requirement: the 2.0 behaviour).
        ?AllocationGuard $allocationGuard = null,
    ) {
        $this->allocations    = $allocationGuard ?? AllocationGuard::off();
        $this->clock          = $clock ?? new NativeClock();
        $this->logger         = $logger ?? new NullLogger();
        $this->userReferences = $userReferences ?? new PassThroughUserReferenceResolver();
    }

    /**
     * Returns the running timer of the user, if any.
     *
     * @param UserInterface $user Timer owner
     *
     * @return ActiveTimer|null The active timer, or null when none is running
     */
    public function getActive(UserInterface $user): ?ActiveTimer
    {
        return $this->activeTimerRepository->findByUserId(UserIdResolver::getId($user));
    }

    /**
     * Starts a timer on a task the user may track.
     *
     * @param UserInterface $user Timer owner
     * @param string|TimeAllocation|null $taskId Task id resolved through the task provider; since 1.36.0 null = a timer
     *                                           without a task (`entries.allow_without_task`); since 2.1.0 a
     *                                           {@see TimeAllocation} (task, task and subject, subject, none)
     * @param ClientType $clientType Client that starts the timer (web, extension, desktop)
     * @param array<string, mixed> $metadata Client metadata stored with the timer
     * @param string|null $description Since 1.36.0: free text of a timer without a task (ignored with a task)
     * @param string|null $scopeKey Since 1.36.0: tenant scope of a timer without a task (one of the user's; null = the current one)
     *
     * @throws ActiveTimerConflictException When the user already has a running timer
     * @throws TaskNotTrackableException When the task is unknown or not trackable by the user (or, without a task, the option is off or the scope is not the user's)
     * @throws TimeAllocationException When the subject or the activity type is refused (since 2.1.0)
     *
     * @return ActiveTimer The new running timer
     */
    public function start(UserInterface $user, TimeAllocation|string|null $taskId, ClientType $clientType, array $metadata = [], ?string $description = null, ?string $scopeKey = null): ActiveTimer
    {
        return $this->startAt($user, $taskId, $clientType, $metadata, description: $description, scopeKey: $scopeKey)['timer'];
    }

    /**
     * Starts a timer, optionally from a client-supplied start (`startedAt` on `timer/start`, since 1.10). The start is
     * clamped: not after now (beyond the clock-skew allowance it is reported as `future`), not before the end of the
     * user's latest entry (`overlap`) and not older than the backdate limit (`max_backdate`).
     *
     * @param UserInterface $user Timer owner
     * @param string|TimeAllocation|null $taskId Task id resolved through the task provider; since 1.36.0 null = a timer
     *                                           without a task; since 2.1.0 a {@see TimeAllocation}
     * @param ClientType $clientType Client that starts the timer
     * @param array<string, mixed> $metadata Client metadata stored with the timer
     * @param DateTimeImmutable|null $requestedStart Client-supplied start (null = now, 1.9 behaviour)
     * @param BackdateLimits|null $limits Backdate limits (null = the defaults)
     * @param string|null $description Since 1.36.0: free text of a timer without a task (cleaned and cut like a note; ignored with a task)
     * @param string|null $scopeKey Since 1.36.0: tenant scope of a timer without a task (one of the user's, `===`; null = the current one; ignored with a task)
     *
     * @throws ActiveTimerConflictException When the user already has a running timer
     * @throws TaskNotTrackableException When the task is unknown or not trackable by the user (or, without a task nor subject, the option is off or the scope is not the user's)
     * @throws TimeAllocationException When the subject or the activity type is refused, or a subject timer's scope is not the user's (since 2.1.0)
     * @throws PeriodLockedException When the (applied) start is in a locked period (since 1.13)
     * @throws PeriodSubmittedException When the (applied) start is in a submitted period (since 1.13)
     *
     * @return ($taskId is string ? array{timer: ActiveTimer, task: TaskReference, startTime: ClampedTime|null} : array{timer: ActiveTimer, task: TaskReference|null, startTime: ClampedTime|null}) The timer, its task (since 1.36.0 null without one) and the clamp result (null without a requested start)
     */
    public function startAt(UserInterface $user, TimeAllocation|string|null $taskId, ClientType $clientType, array $metadata = [], ?DateTimeImmutable $requestedStart = null, ?BackdateLimits $limits = null, ?string $description = null, ?string $scopeKey = null): array
    {
        return $this->startAllocatedAt($user, self::allocationOf($taskId), $clientType, $metadata, $requestedStart, $limits, $description, $scopeKey);
    }

    /**
     * {@see self::startAt()} with an allocation; `$checked` = the subject and activity type were checked by the caller
     * already (a switch checks them before it stops the running timer).
     *
     * @param array<string, mixed> $metadata
     *
     * @return array{timer: ActiveTimer, task: TaskReference|null, startTime: ClampedTime|null}
     */
    private function startAllocatedAt(UserInterface $user, TimeAllocation $allocation, ClientType $clientType, array $metadata = [], ?DateTimeImmutable $requestedStart = null, ?BackdateLimits $limits = null, ?string $description = null, ?string $scopeKey = null, bool $checked = false): array
    {
        $existing = $this->getActive($user);
        if ($existing instanceof ActiveTimer) {
            throw ActiveTimerConflictException::alreadyRunning($existing->getTaskId());
        }
        if ($allocation->taskId === null) {
            return $this->startWithoutTaskAt($user, $clientType, $metadata, $requestedStart, $limits, $description, $scopeKey, $allocation, $checked);
        }

        $task  = $this->resolveTrackable($user, $allocation->taskId);
        $scope = $this->scopeKeyFor($user, $task->id, $task->boardId);
        if (!$checked) {
            $this->allocations->checkNew($user, $scope, $allocation, false);
        }
        $metadata  = $this->startMetadata($metadata);
        $now       = $this->clock->now();
        $startTime = $requestedStart instanceof DateTimeImmutable ? $this->clampStart($user, $requestedStart, $limits ?? new BackdateLimits(), $now) : null;
        $startedAt = $startTime instanceof ClampedTime ? $startTime->applied : $now;
        $this->periodLockGuard?->assertWritable($user, $scope, $startedAt, WriteKind::TimerStart);

        $timer = (new ActiveTimer(
            $this->userReferences->reference($user),
            $task->id,
            $task->title,
            $task->boardId,
            $clientType,
            $metadata,
            $startedAt,
        ))->allocate($allocation->subject, $allocation->activityTypeKey);

        $this->insertTimer($user, $timer, 'timer_start', $clientType);

        $this->eventDispatcher->dispatch(new TimerStartEvent($user, $task, $clientType, $startTime, $timer->subject(), $timer->getActivityTypeKey()), TimeTrackEvents::TIMER_START);
        $this->logger->info('TimeTrack timer started.', $this->context('timer_start', $clientType) + ['backdated' => $startTime instanceof ClampedTime] + $this->allocationContext($timer->subject(), $timer->getActivityTypeKey()));
        if ($startTime instanceof ClampedTime && $startTime->clamped) {
            $this->logger->info('TimeTrack timer start clamped.', $this->context('timer_start_clamped', $clientType) + ['reason' => $startTime->reason]);
        }

        return ['timer' => $timer, 'task' => $task, 'startTime' => $startTime];
    }

    /**
     * Since 1.36.0: {@see self::startAt()} without a task. The same checks, clamping, lock order and concurrency guard as
     * a task timer (one running timer per user: {@see self::insertTimer()}); the scope is the requested one when it is
     * one of the user's, else the current one; {@see TimerStartedWithoutTaskEvent} instead of TIMER_START. Since 2.1.0
     * also a timer on a subject alone (no `entries.allow_without_task` needed).
     *
     * @param array<string, mixed> $metadata
     *
     * @return array{timer: ActiveTimer, task: null, startTime: ClampedTime|null}
     */
    private function startWithoutTaskAt(UserInterface $user, ClientType $clientType, array $metadata, ?DateTimeImmutable $requestedStart, ?BackdateLimits $limits, ?string $description, ?string $scopeKey, TimeAllocation $allocation, bool $checked): array
    {
        $scopeKey = $this->scopeKeyOfTaskless($user, $scopeKey, $allocation->subject);
        if (!$checked) {
            $this->allocations->checkNew($user, $scopeKey, $allocation, false);
        }
        $metadata  = $this->startMetadata($metadata);
        $now       = $this->clock->now();
        $startTime = $requestedStart instanceof DateTimeImmutable ? $this->clampStart($user, $requestedStart, $limits ?? new BackdateLimits(), $now) : null;
        $startedAt = $startTime instanceof ClampedTime ? $startTime->applied : $now;
        $this->periodLockGuard?->assertWritable($user, $scopeKey, $startedAt, WriteKind::TimerStart);

        $timer = ActiveTimer::withoutTask($this->userReferences->reference($user), $clientType, self::cleanDescription($description), $scopeKey, $metadata, $startedAt)
            ->allocate($allocation->subject, $allocation->activityTypeKey);
        $this->insertTimer($user, $timer, 'timer_start', $clientType);

        $this->dispatchStartedWithoutTask($user, $timer, $startTime);
        $this->logger->info('TimeTrack timer started.', $this->context('timer_start', $clientType) + ['backdated' => $startTime instanceof ClampedTime, 'without_task' => true] + $this->allocationContext($timer->subject(), $timer->getActivityTypeKey()));
        if ($startTime instanceof ClampedTime && $startTime->clamped) {
            $this->logger->info('TimeTrack timer start clamped.', $this->context('timer_start_clamped', $clientType) + ['reason' => $startTime->reason]);
        }

        return ['timer' => $timer, 'task' => null, 'startTime' => $startTime];
    }

    /**
     * Since 2.1.0: the allocation of a 2.0 task argument (a task id, null for none) or the allocation itself.
     */
    public static function allocationOf(TimeAllocation|string|null $taskId): TimeAllocation
    {
        return $taskId instanceof TimeAllocation ? $taskId : TimeAllocation::ofTask($taskId);
    }

    /**
     * Since 2.1.0: the tenant scope of new time without a task: a subject entry's is chosen like the scope of an entry
     * without a task but needs no `entries.allow_without_task` (it is allocated); a requested scope that is not one of the
     * user's answers `subject_forbidden`.
     *
     * @throws TaskNotTrackableException Without a subject: the option is off, or the scope is not the user's
     * @throws TimeAllocationException With a subject: the scope is not one of the user's
     */
    public function scopeKeyOfTaskless(UserInterface $user, ?string $requested, ?TimeSubjectRef $subject): ?string
    {
        if (!$subject instanceof TimeSubjectRef) {
            return $this->scopeKeyWithoutTask($user, $requested);
        }

        return $this->userScope($user, $requested);
    }

    /**
     * Since 2.1.0: one of the user's tenant scopes (the requested one, compared byte for byte, else the current one; null
     * without a scope provider), for subject time and the client API's subject search and activity types.
     *
     * @throws TimeAllocationException `subject_forbidden` when the requested scope is not one of the user's, or the
     *                                 user has none under a scope provider
     */
    public function userScope(UserInterface $user, ?string $requested): ?string
    {
        try {
            return ScopeKeys::forEntryWithoutTask($this->scopeProvider, $user, $requested, $this->logger);
        } catch (InvalidArgumentException) {
            throw new TimeAllocationException(TimeAllocationException::SUBJECT_FORBIDDEN);
        }
    }

    /**
     * Since 2.1.0: the tenant scope a running timer's entry will belong to (the task's, asked again; or the scope a timer
     * without a task was started in): the scope its subject is checked and labelled in.
     *
     * @internal
     */
    public function scopeKeyOfTimer(UserInterface $user, ActiveTimer $timer): ?string
    {
        return $this->timerScopeKey($user, $timer);
    }

    /**
     * Since 2.1.0: the subject and activity type checks of the write paths.
     *
     * @internal
     */
    public function allocationGuard(): AllocationGuard
    {
        return $this->allocations;
    }

    /**
     * REQ-OBS-001: the subject type and whether an activity type is set; never the subject id.
     *
     * @return array<string, bool|string>
     */
    private function allocationContext(?TimeSubjectRef $subject, ?string $activityTypeKey): array
    {
        return ($subject instanceof TimeSubjectRef ? ['subject_type' => $subject->type] : []) + ($activityTypeKey !== null ? ['activity_type' => true] : []);
    }

    /**
     * Since 1.36.0: the tenant scope of a new timer or entry without a task, when the option allows one.
     *
     * @throws TaskNotTrackableException When the option is off, or the requested scope is not one of the user's
     */
    private function scopeKeyWithoutTask(UserInterface $user, ?string $requested): ?string
    {
        if (!$this->allowWithoutTask) {
            throw TaskNotTrackableException::withoutTask();
        }

        try {
            return ScopeKeys::forEntryWithoutTask($this->scopeProvider, $user, $requested, $this->logger);
        } catch (InvalidArgumentException) {
            throw TaskNotTrackableException::withoutTask();
        }
    }

    /**
     * Since 1.36.0: the free text of a timer or entry without a task, cleaned and cut like a note (blank = none).
     */
    public static function cleanDescription(?string $description): ?string
    {
        return $description === null ? null : NoteSanitizer::cleanAndCut($description);
    }

    /**
     * Since 1.36.0: whether `entries.allow_without_task` is on.
     */
    public function allowsEntriesWithoutTask(): bool
    {
        return $this->allowWithoutTask;
    }

    /**
     * Since 1.36.0: the tenant scope a new entry without a task gets (ManualEntryService), checked as for a timer.
     *
     * @throws TaskNotTrackableException When the option is off, or the requested scope is not one of the user's
     */
    public function scopeForEntryWithoutTask(UserInterface $user, ?string $requested): ?string
    {
        return $this->scopeKeyWithoutTask($user, $requested);
    }

    private function dispatchStartedWithoutTask(UserInterface $user, ActiveTimer $timer, ?ClampedTime $startTime = null): void
    {
        $this->eventDispatcher->dispatch(new TimerStartedWithoutTaskEvent(UserIdResolver::getId($user), $timer->getId(), $timer->getScopeKey(), $timer->getClientType(), $timer->getStartedAt(), $startTime, $timer->subject(), $timer->getActivityTypeKey()), TimeTrackEvents::TIMER_START_WITHOUT_TASK);
    }

    /**
     * Stops the running timer of the user and records it as a time entry.
     *
     * @param UserInterface $user Timer owner
     * @param DateTimeImmutable|null $endedAt End of the entry (default: now). Since 1.26 an end before now is reported as
     *                                        `TimerStopEvent::getEndTime()` (requested = applied = `$endedAt`)
     * @param string|null $note Note of the user stored in the entry metadata (`note`)
     * @param TimeAllocationPatch|null $allocation Since 2.1.0: sets, changes or clears the subject (next to the task only
     *                                             for a type that allows tasks) and the activity type of the entry; the
     *                                             task is never changed at stop
     * @param bool $interactive Since 2.1.0: a stop the person asked for (web UI, `HostTimerApi::stop()`): refused under
     *                          `entries.require_allocation` / `require_activity_type` when the time misses it. false (the
     *                          default, as in 2.0) is a stop the system triggers: never refused
     *
     * @throws TimeAllocationException When the patch's subject or activity type is refused (since 2.1.0; nothing is written)
     * @throws TimeAllocationRequiredException When an interactive stop misses an allocation (since 2.1.0; the timer keeps running)
     *
     * @return TimeEntry|null The recorded entry, or null when no timer was running (or a concurrent stop recorded it)
     */
    public function stop(UserInterface $user, ?DateTimeImmutable $endedAt = null, ?string $note = null, ?TimeAllocationPatch $allocation = null, bool $interactive = false): ?TimeEntry
    {
        $now = $this->clock->now();
        // Since 1.26 (audit PM delta D2-L2): an explicit end before now is reported on TimerStopEvent::getEndTime(), as a
        // backdated `timer/stop` is, so the time given up ([end, now)) can be told apart.
        $endTime = $endedAt instanceof DateTimeImmutable && $endedAt->getTimestamp() < $now->getTimestamp() ? new ClampedTime($endedAt, $endedAt) : null;
        $timer   = $this->getActive($user);
        if (!$timer instanceof ActiveTimer) {
            return null;
        }

        return $this->closeTimer($user, $timer, $endedAt ?? $now, $note, $endTime, stop: $this->stopAllocation($user, $timer, $allocation ?? TimeAllocationPatch::keep(), $interactive));
    }

    /**
     * Since 2.1.0: the checks of a stop's allocation patch, asked before the owner's lock on the timer as loaded (the
     * subject in the timer's scope, the activity type in that scope), and the scope's activity types for the entry's
     * billable default.
     *
     * @throws TimeAllocationException When the patch's subject or activity type is refused
     *
     * @return array{patch: TimeAllocationPatch, interactive: bool, types: ActivityTypeList|null}
     */
    private function stopAllocation(UserInterface $user, ActiveTimer $timer, TimeAllocationPatch $patch, bool $interactive): array
    {
        $scope   = false;
        $subject = $patch->subjectAfter($timer->subject());
        if ($patch->changesSubjectOf($timer->subject()) && $subject instanceof TimeSubjectRef) {
            $scope = $this->timerScopeKey($user, $timer);
            $this->allocations->assertSubject($user, $scope, $subject, $timer->hasTask());
        }
        $types = null;
        $key   = $patch->activityTypeAfter($timer->getActivityTypeKey());
        // A key the patch sets is checked even without a provider (refused as unknown: no unchecked key is stored).
        $changes = $key !== null && $patch->changesActivityTypeOf($timer->getActivityTypeKey());
        if ($key !== null && ($changes || $this->allocations->activityTypes()->isEnabled())) {
            $scope = $scope === false ? $this->timerScopeKey($user, $timer) : $scope;
            $types = $this->allocations->activityTypes()->listFor($scope);
            if ($changes) {
                $this->allocations->activityType($scope, $key, $types);
            }
        }

        return ['patch' => $patch, 'interactive' => $interactive, 'types' => $types];
    }

    /**
     * Since 1.15 (owner ruling 2026-11-06): stops the running timer of a user who is being deleted, so the time in progress
     * is not lost. The end is now, and the entry is marked ({@see TimeEntry::isClosedByUserDeletion()}). Like every stop it
     * is written even in a locked period, without throwing (the approver gets {@see TimesheetLateEntryEvent}), and it checks
     * no overlaps. It writes and flushes at once, also the host's pending work: `TIMER_STOP` needs a live user and its
     * listeners flush (the task totals), so it cannot wait for the host's flush. Delete inside a transaction to make the
     * stop and the delete one unit; otherwise, if the delete then fails, the user keeps a stopped entry and nothing is lost.
     * {@see FormerPersonSnapshotListener} calls it on the entity manager's `remove()`. **A host deleting by SQL must call it
     * first**: the foreign key of the timers table deletes the running timer, with no code to close it.
     *
     * @return TimeEntry|null The recorded entry, or null when no timer was running (or a concurrent stop recorded it)
     */
    public function stopForUserDeletion(UserInterface $user): ?TimeEntry
    {
        $timer = $this->getActive($user);
        if (!$timer instanceof ActiveTimer) {
            return null;
        }

        return $this->closeTimer($user, $timer, $this->clock->now(), extraMetadata: ['closed_by' => TimeEntry::CLOSED_BY_USER_DELETION]);
    }

    /**
     * Stops the running timer at a client-supplied end (`endedAt` on `timer/stop`, since 1.10). The end is clamped: not
     * after now (beyond the clock-skew allowance it is reported as `future`) and not before the timer start
     * (`before_start`). An earlier end only ever shortens the recorded time.
     *
     * @param UserInterface $user Timer owner
     * @param DateTimeImmutable|null $requestedEnd Client-supplied end (null = now, 1.9 behaviour)
     * @param BackdateLimits|null $limits Backdate limits (null = the defaults)
     * @param string|null $note Note of the user stored in the entry metadata (`note`)
     * @param TimeAllocationPatch|null $allocation Since 2.1.0: the allocation set at stop (see {@see self::stop()})
     * @param bool $interactive Since 2.1.0: a stop the person asked for (the client API): refused under
     *                          `entries.require_allocation` / `require_activity_type` when the time misses it
     *
     * @throws TimeAllocationException When the patch's subject or activity type is refused (since 2.1.0; nothing is written)
     * @throws TimeAllocationRequiredException When an interactive stop misses an allocation (since 2.1.0; the timer keeps running)
     *
     * @return array{entry: TimeEntry, endTime: ClampedTime|null}|null The entry and the clamp result, or null when no timer was running
     */
    public function stopAt(UserInterface $user, ?DateTimeImmutable $requestedEnd = null, ?BackdateLimits $limits = null, ?string $note = null, ?TimeAllocationPatch $allocation = null, bool $interactive = false): ?array
    {
        $timer = $this->getActive($user);
        if (!$timer instanceof ActiveTimer) {
            return null;
        }

        $stop    = $this->stopAllocation($user, $timer, $allocation ?? TimeAllocationPatch::keep(), $interactive);
        $now     = $this->clock->now();
        $endTime = $requestedEnd instanceof DateTimeImmutable ? $this->clampEnd($timer, $requestedEnd, $limits ?? new BackdateLimits(), $now) : null;
        $entry   = $this->closeTimer($user, $timer, $endTime instanceof ClampedTime ? $endTime->applied : $now, $note, $endTime, stop: $stop);
        if (!$entry instanceof TimeEntry) {
            return null;
        }

        if ($endTime instanceof ClampedTime && $endTime->clamped) {
            $this->logger->info('TimeTrack timer stop clamped.', $this->context('timer_stop_clamped', $timer->getClientType()) + ['reason' => $endTime->reason]);
        }

        return ['entry' => $entry, 'endTime' => $endTime];
    }

    /**
     * Stops the running timer (if any) and starts one on another task (`POST timer/switch`). The new task is checked
     * before the running timer is stopped, so a refused task leaves the running timer untouched.
     *
     * @param UserInterface $user Timer owner
     * @param string|TimeAllocation $taskId Task to track next; since 2.1.0 a {@see TimeAllocation} (task, task and subject,
     *                                      subject, or none with `entries.allow_without_task`)
     * @param ClientType $clientType Client that switches
     * @param string|null $note Note stored in the entry of the stopped timer
     * @param array<string, mixed> $metadata Metadata of the new timer (since 1.14: `{origin: "meeting"}` from the client API)
     * @param string|null $scopeKey Since 2.1.0: tenant scope of a next timer without a task (one of the user's; null = the current one)
     *
     * @throws TaskNotTrackableException When the task is unknown or not trackable by the user
     * @throws TimeAllocationException When the subject or the activity type is refused (since 2.1.0; the running timer is left untouched)
     * @throws ActiveTimerConflictException When a concurrent start won the race
     * @throws PeriodLockedException When the current period is locked (since 1.13; the running timer is left untouched)
     * @throws PeriodSubmittedException When the current period is submitted (since 1.13)
     *
     * @return array{entry: TimeEntry|null, timer: ActiveTimer} The entry of the stopped timer and the new timer
     */
    public function switchTo(UserInterface $user, TimeAllocation|string $taskId, ClientType $clientType, ?string $note = null, array $metadata = [], ?string $scopeKey = null): array
    {
        $allocation = self::allocationOf($taskId);
        if ($allocation->taskId !== null) {
            $task  = $this->resolveTrackable($user, $allocation->taskId);
            $scope = $this->scopeKeyFor($user, $task->id, $task->boardId);
        } else {
            $scope = $this->scopeKeyOfTaskless($user, $scopeKey, $allocation->subject);
        }
        // Since 2.1.0: the next timer's subject and activity type are checked before the running timer stops.
        $this->allocations->checkNew($user, $scope, $allocation, false);
        // Since 1.13: refused before the running timer stops, so a locked period leaves it untouched.
        $this->periodLockGuard?->assertWritable($user, $scope, $this->clock->now(), WriteKind::TimerStart);

        $entry = $this->stop($user, null, $note);

        return ['entry' => $entry, 'timer' => $this->startAllocatedAt($user, $allocation, $clientType, $metadata, scopeKey: $scope, checked: true)['timer']];
    }

    /**
     * Since 1.25.0 (Beacon spec 234 FR-023 / FR-024, {@see HostTimerApi}): starts a timer now on a task and, when the
     * account already has one running, stops that one first and returns its entry. Only the account's
     * own timer is ever stopped: an agent's call cannot stop a person's timer.
     *
     * The task and the period lock are checked before anything is stopped, so a refused start leaves the running timer
     * untouched. Each attempt runs in one transaction holding the account's write lock (stores with
     * {@see UserSerialisedTimeEntryRepositoryInterface}), so two calls of this method for one account run one after the
     * other: the second stops the first one's timer. When a start that does not take the lock (the client API) wins the
     * unique index in between, the attempt is retried: up to {@see self::REPLACE_ATTEMPTS} attempts, the last call wins,
     * and every stopped timer is recorded once.
     *
     * Since 1.36.0 a null `$taskId` starts a timer without a task (`entries.allow_without_task`, see {@see self::startAt()}):
     * the scope is checked before anything is stopped, as the task is, and {@see TimerStartedWithoutTaskEvent} replaces
     * TIMER_START. The one-running-timer guarantee is the same.
     *
     * @param UserInterface $user Timer owner
     * @param string|TimeAllocation|null $taskId Task to track (since 1.36.0 null = no task; since 2.1.0 a {@see TimeAllocation})
     * @param ClientType $clientType Client of the new timer (source of its entry)
     * @param string $channel Channel label ({@see TimerChannel}), stored on the entry
     * @param array<string, mixed> $metadata Metadata of the new timer (`note`; reserved keys are dropped)
     * @param string|null $stopNote Note stored on the entry of the replaced timer
     * @param string|null $description Since 1.36.0: free text of a timer without a task
     * @param string|null $scopeKey Since 1.36.0: tenant scope of a timer without a task (one of the user's; null = the current one)
     *
     * @throws TaskNotTrackableException When the task is unknown or not trackable by the user
     * @throws TimeAllocationException When the subject or the activity type is refused (since 2.1.0; nothing is stopped)
     * @throws PeriodLockedException When the current period is locked
     * @throws PeriodSubmittedException When the current period is submitted
     * @throws InvalidArgumentException When the channel is not a valid label
     * @throws ActiveTimerConflictException When concurrent starts took the slot on every attempt; the entries this call
     *                                      already recorded are on {@see ActiveTimerConflictException::$stoppedEntryIds}
     *                                      (they are written: a refused start leaves the running timer untouched for a
     *                                      task, period-lock or channel refusal, not for an exhausted conflict)
     *
     * @return array{timer: ActiveTimer, task: TaskReference|null, stopped: list<TimeEntry>} The new timer, its task (null without one) and the entries of the timers it stopped (oldest first)
     */
    public function startReplacing(UserInterface $user, TimeAllocation|string|null $taskId, ClientType $clientType, string $channel, array $metadata = [], ?string $stopNote = null, ?string $description = null, ?string $scopeKey = null): array
    {
        $allocation  = self::allocationOf($taskId);
        $channel     = TimerChannel::assertValid($channel);
        $task        = $allocation->taskId === null ? null : $this->resolveTrackable($user, $allocation->taskId);
        $scopeKey    = $task instanceof TaskReference ? $this->scopeKeyFor($user, $task->id, $task->boardId) : $this->scopeKeyOfTaskless($user, $scopeKey, $allocation->subject);
        $description = $task instanceof TaskReference ? null : self::cleanDescription($description);
        // Since 2.1.0: the subject and activity type, before anything is stopped.
        $this->allocations->checkNew($user, $scopeKey, $allocation, false);
        $this->periodLockGuard?->assertWritable($user, $scopeKey, $this->clock->now(), WriteKind::TimerStart);
        $metadata                    = $this->startMetadata($metadata);
        $metadata[self::CHANNEL_KEY] = $channel;

        $stopped = [];
        for ($attempt = 1;; ++$attempt) {
            $running = $this->getActive($user);
            if ($running instanceof ActiveTimer) {
                // Recorded once even when a concurrent call stops the same timer (conditional removal, CL-05).
                $replaced = $this->closeTimer($user, $running, $this->clock->now(), $stopNote, extraMetadata: ['closed_by' => TimeEntry::CLOSED_BY_REPLACED]);
                if ($replaced instanceof TimeEntry) {
                    $stopped[] = $replaced;
                    // Spec 234 (Beacon review): the replaced timer is logged with the entry id and the account id only.
                    $this->logger->info('TimeTrack running timer replaced by a new start.', $this->context('timer_replace', $clientType) + ['channel' => $channel, 'entry_id' => $replaced->getId(), 'account_id' => UserIdResolver::getId($user)]);
                }
            }

            try {
                // @igor-ignore - Request-scoped closure passed to a per-request call; nothing is stored on the service.
                $timer = $this->serialised($user, function () use ($user, $task, $clientType, $metadata, $description, $scopeKey, $allocation): ?ActiveTimer {
                    if ($this->getActive($user) instanceof ActiveTimer) {
                        return null; // Another start of this account got in first: stop that one on the next attempt.
                    }
                    $timer = $task instanceof TaskReference
                        ? new ActiveTimer($this->userReferences->reference($user), $task->id, $task->title, $task->boardId, $clientType, $metadata, $this->clock->now())
                        : ActiveTimer::withoutTask($this->userReferences->reference($user), $clientType, $description, $scopeKey, $metadata, $this->clock->now());
                    $timer->allocate($allocation->subject, $allocation->activityTypeKey);
                    $this->activeTimerRepository->save($timer);

                    return $timer;
                });
            } catch (UniqueConstraintViolationException $e) {
                // A start that does not take the account lock (client API) inserted its timer first.
                $this->logger->warning('TimeTrack timer start rejected: concurrent start.', $this->context('timer_replace', $clientType) + ['attempt' => $attempt]);
                if ($attempt >= self::REPLACE_ATTEMPTS) {
                    throw ActiveTimerConflictException::concurrentStart($e, $this->entryIds($stopped));
                }

                continue;
            }
            if ($timer instanceof ActiveTimer) {
                break;
            }
            if ($attempt >= self::REPLACE_ATTEMPTS) {
                throw ActiveTimerConflictException::concurrentStart(null, $this->entryIds($stopped));
            }
        }

        if ($task instanceof TaskReference) {
            $this->eventDispatcher->dispatch(new TimerStartEvent($user, $task, $clientType, null, $timer->subject(), $timer->getActivityTypeKey()), TimeTrackEvents::TIMER_START);
        } else {
            $this->dispatchStartedWithoutTask($user, $timer);
        }
        $this->logger->info('TimeTrack timer started.', $this->context('timer_start', $clientType) + ['channel' => $channel, 'replaced' => $stopped !== []] + ($task instanceof TaskReference ? [] : ['without_task' => true]) + $this->allocationContext($timer->subject(), $timer->getActivityTypeKey()));

        return ['timer' => $timer, 'task' => $task, 'stopped' => $stopped];
    }

    /**
     * Applies the user's decision about an idle span of the running timer (`POST timer/discard-idle`): keep it,
     * discard it and stop at its start, discard it and continue on the same task from its end (split), or (since 1.10)
     * give it to another task (reassign) and then continue, switch to that task or stop.
     *
     * Reassign writes the entry up to the idle start (omitted when the span starts with the timer) and the entry of the
     * span on the other task in the same unit of work as the timer removal, and dispatches a {@see TimerStopEvent} for
     * both. Every applied decision dispatches an {@see IdleResolvedEvent}.
     *
     * @param UserInterface $user Timer owner
     * @param DateTimeImmutable $from Idle start (not before the timer start)
     * @param DateTimeImmutable $to Idle end (after `from`, not in the future)
     * @param IdleResolution $resolution The user's decision
     * @param string|TimeAllocation|null $targetTaskId Task the span goes to (`reassign` only; required there); since
     *                                                 2.1.0 a {@see TimeAllocation} with a task or a subject (a subject
     *                                                 alone lands in the running timer's scope)
     * @param IdleFollowUp $followUp What runs after the span (`reassign` only)
     * @param ClientType|null $clientType Client of the decision and source of the reassigned entry (null = the timer's client)
     * @param string|null $note `keep`: added to the running timer's note (1.10.1, see {@see ActiveTimer::appendNote()}); `reassign`: stored on the reassigned entry
     *
     * @throws ActiveTimerConflictException When a concurrent start took the timer slot before the follow-up timer started
     * @throws TaskNotTrackableException When the reassign target is unknown or not trackable by the user
     * @throws TimeAllocationException When the target's subject or activity type is refused (since 2.1.0)
     * @throws InvalidArgumentException When the span is not inside the running timer, or the target is the timer's task
     * @throws PeriodLockedException When the idle span starts in a locked period (discard, split, reassign; since 1.13)
     * @throws PeriodSubmittedException When the idle span starts in a submitted period (since 1.13)
     *
     * @return array{entry: TimeEntry|null, timer: ActiveTimer|null, reassigned: TimeEntry|null}|null Recorded entries and running timer, or null when no timer runs
     */
    public function resolveIdle(
        UserInterface $user,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        IdleResolution $resolution,
        TimeAllocation|string|null $targetTaskId = null,
        IdleFollowUp $followUp = IdleFollowUp::Continue,
        ?ClientType $clientType = null,
        ?string $note = null,
    ): ?array {
        // Whole seconds in the server zone (EF-M1, EF-I5): what is compared is what is stored.
        $from       = $this->toSecond($from);
        $to         = $this->toSecond($to);
        $target     = null;
        $allocation = null;
        if ($resolution === IdleResolution::Reassign) {
            $allocation = $targetTaskId === '' ? null : ($targetTaskId === null ? null : self::allocationOf($targetTaskId));
            if (!$allocation instanceof TimeAllocation || !$allocation->isAllocated()) {
                throw new InvalidArgumentException('taskId is required to reassign an idle span.');
            }

            $target = $allocation->taskId === null ? null : $this->resolveTrackable($user, $allocation->taskId);
        }

        $timer = $this->getActive($user);
        if (!$timer instanceof ActiveTimer) {
            return null;
        }

        if ($allocation instanceof TimeAllocation && $allocation->taskId === $timer->taskIdOrNull() && TimeSubjectRef::same($allocation->subject, $timer->subject())) {
            throw new InvalidArgumentException('The idle span is already on this task: use keep or split.');
        }
        // Since 2.1.0: a target subject alone lands in the running timer's scope; the target is checked before any write.
        $targetScope = null;
        if ($allocation instanceof TimeAllocation) {
            $targetScope = $target instanceof TaskReference ? $this->scopeKeyFor($user, $target->id, $target->boardId) : $this->timerScopeKey($user, $timer);
            $this->allocations->checkNew($user, $targetScope, $allocation, false);
        }

        if ($from < $timer->getStartedAt() || $to <= $from || $to > $this->clock->now()) {
            throw new InvalidArgumentException('The idle span must lie inside the running timer.');
        }

        $clientType ??= $timer->getClientType();
        $note = $note !== null ? NoteSanitizer::cleanAndCut($note) : null;

        if ($resolution !== IdleResolution::Keep && $this->periodLockGuard instanceof PeriodLockGuard) {
            $this->periodLockGuard->assertWritable($user, $this->timerScopeKey($user, $timer), $from, WriteKind::IdleResolve);
            if ($allocation instanceof TimeAllocation) {
                $this->periodLockGuard->assertWritable($user, $targetScope, $from, WriteKind::IdleResolve);
            }
        }

        if ($resolution === IdleResolution::Keep) {
            if ($note !== null) {
                $timer = $this->appendNote($user, $timer, $note);
                if (!$timer instanceof ActiveTimer) {
                    return null; // A concurrent call stopped the timer first.
                }
            }

            // REQ-OBS-001: the note's length only, never its text.
            $this->logger->info('TimeTrack idle span kept.', $this->context('timer_idle_keep', $clientType) + ['note_length' => $note === null ? 0 : mb_strlen($note)]);
            $this->dispatchIdleResolved($user, $resolution, $from, $to, $clientType, null, null, $note);

            return ['entry' => null, 'timer' => $timer, 'reassigned' => null];
        }

        if ($allocation instanceof TimeAllocation) {
            return $this->reassignIdle($user, $timer, $from, $to, $target, $allocation, $targetScope, $followUp, $clientType, $note);
        }

        $entry = $this->closeTimer($user, $timer, $from);
        if (!$entry instanceof TimeEntry) {
            return null; // A concurrent call stopped the timer first (1.9.1, CL-05).
        }

        if ($resolution === IdleResolution::Discard) {
            $this->dispatchIdleResolved($user, $resolution, $from, $to, $clientType);

            return ['entry' => $entry, 'timer' => null, 'reassigned' => null];
        }

        // The note stays on the entry up to the idle start; the follow-up timer starts without it (EF2-I4).
        $next = $this->continueTimer($user, $timer, $timer->getClientType(), $this->withoutNote($timer->getMetadata()), $to, 'timer_split');
        $this->logger->info('TimeTrack idle span discarded, timer continued.', $this->context('timer_split', $timer->getClientType()));
        $this->dispatchIdleResolved($user, $resolution, $from, $to, $clientType);

        return ['entry' => $entry, 'timer' => $next, 'reassigned' => null];
    }

    /**
     * The user's own entries of the period, newest first, one page (client API `entries` / `export`; no team
     * widening through the list-query event).
     *
     * @param UserInterface $user Owner
     * @param DateTimeImmutable $from Period start (inclusive)
     * @param DateTimeImmutable $to Period end (inclusive)
     * @param int $limit Page size (at least 1)
     * @param int $offset Entries to skip
     *
     * @return list<TimeEntry>
     */
    public function listOwnEntries(UserInterface $user, DateTimeImmutable $from, DateTimeImmutable $to, int $limit, int $offset = 0): array
    {
        $limit  = max(1, $limit);
        $offset = max(0, $offset);
        $userId = UserIdResolver::getId($user);
        if ($this->timeEntryRepository instanceof PaginatedTimeEntryRepositoryInterface) {
            return $this->timeEntryRepository->findPageByUsersAndPeriod([$userId], $from, $to, $offset, $limit);
        }

        $entries = $this->timeEntryRepository->findByUserAndPeriod($userId, $from, $to);
        usort($entries, static fn (TimeEntry $a, TimeEntry $b): int => $b->getStartedAt() <=> $a->getStartedAt());

        return array_slice($entries, $offset, $limit);
    }

    /**
     * Records a heartbeat on the running timer without any activity signal (deprecated `POST heartbeat` since 1.9).
     *
     * @param UserInterface $user Timer owner
     *
     * @return ActiveTimer|null The updated timer, or null when no timer is running
     */
    public function keepAlive(UserInterface $user): ?ActiveTimer
    {
        $timer = $this->getActive($user);
        if (!$timer instanceof ActiveTimer) {
            return null;
        }

        $timer->touchHeartbeat($this->clock->now());
        $this->activeTimerRepository->save($timer);

        return $timer;
    }

    /**
     * Records a heartbeat (and the idle flag) on the running timer of the user.
     *
     * @param UserInterface $user Timer owner
     * @param bool $isIdle Whether the client reports the user as idle
     *
     * @return ActiveTimer|null The updated timer, or null when no timer is running
     */
    public function heartbeat(UserInterface $user, bool $isIdle = false): ?ActiveTimer
    {
        $timer = $this->getActive($user);
        if (!$timer instanceof ActiveTimer) {
            return null;
        }

        $now    = $this->clock->now();
        $timers = $this->activeTimerRepository;
        if (!$timers instanceof DoctrineOrmActiveTimerRepository) {
            $timer->heartbeat($isIdle, $now);
            $timers->save($timer);

            return $timer;
        }

        // Since 1.27.0 (audit QO-I4): on the timer as committed, under the user's and the timer's locks (as a note), so
        // a heartbeat never writes back metadata loaded before a concurrent note.
        // @igor-ignore - Request-scoped closure passed to a per-request call; nothing is stored on the service.
        $beat = static function () use ($timers, $timer, $isIdle, $now): ?ActiveTimer {
            $current = $timers->lockById($timer->getId());
            if ($current instanceof ActiveTimer) {
                // @igor-ignore - $current is a Doctrine entity loaded for this request, not a shared service.
                $current->heartbeat($isIdle, $now);
                $timers->save($current);
            }

            return $current;
        };

        return $this->serialised($user, static fn (): ?ActiveTimer => $timers->runner()->run($beat));
    }

    /**
     * Since 2.1.0 (Beacon spec 256, Q-TS-19): sets, changes or clears the subject and the activity type of the running
     * timer (client API `timer/allocate`), task timer or not. A subject that is set or changed is checked first (a
     * subject next to a task needs a type that allows tasks, then `canLog` in the timer's scope), an activity type that
     * is set must exist and be active in that scope; all before the owner's lock. The change itself runs under the
     * owner's write lock on the timer that runs **then**: when a switch replaced the timer in between, the checks run
     * again for the new one (it is the running one). Clearing the subject of a timer without a task is refused when it
     * would leave the time unallocated under `entries.require_allocation` or without `entries.allow_without_task`.
     * Concurrent allocations: the last one wins (a running timer has no version). Dispatches {@see TimerAllocatedEvent}
     * after the commit when something changed.
     *
     * @throws TimeAllocationException When the subject or activity type is refused, or the change would leave the time unallocated
     * @throws ActiveTimerConflictException When the running timer was replaced on every attempt
     *
     * @return ActiveTimer|null The running timer as it is now, or null when none runs
     */
    public function allocateRunning(UserInterface $user, TimeAllocationPatch $patch): ?ActiveTimer
    {
        for ($attempt = 1;; ++$attempt) {
            $timer = $this->getActive($user);
            if (!$timer instanceof ActiveTimer || $patch->isKeep()) {
                return $timer;
            }
            $this->stopAllocation($user, $timer, $patch, false);
            $timers  = $this->activeTimerRepository;
            $timerId = $timer->getId();
            // @igor-ignore - Request-scoped closure passed to a per-request call; nothing is stored on the service.
            $apply  = fn (): array|false|null => $this->applyAllocation($user, $timerId, $patch);
            $result = $this->serialised($user, static fn (): mixed => $timers instanceof DoctrineOrmActiveTimerRepository ? $timers->runner()->run($apply) : $apply());
            if ($result !== false) {
                break;
            }
            if ($attempt >= self::REPLACE_ATTEMPTS) {
                throw ActiveTimerConflictException::concurrentStart();
            }
        }
        if ($result === null) {
            return null;
        }

        /* @var array{0: ActiveTimer, 1: TimeSubjectRef|null, 2: string|null} $result */
        [$current, $previousSubject, $previousKey] = $result;
        if (!TimeSubjectRef::same($previousSubject, $current->subject()) || $previousKey !== $current->getActivityTypeKey()) {
            $this->eventDispatcher->dispatch(new TimerAllocatedEvent(UserIdResolver::getId($user), $current->getId(), $current->taskIdOrNull(), $current->getScopeKey(), $previousSubject, $previousKey, $current->subject(), $current->getActivityTypeKey()), TimeTrackEvents::TIMER_ALLOCATED);
            $this->logger->info('TimeTrack running timer allocated.', $this->context('timer_allocate', $current->getClientType()) + $this->allocationContext($current->subject(), $current->getActivityTypeKey()));
        }

        return $current;
    }

    /**
     * The change of {@see self::allocateRunning()} under the owner's lock: false when another timer runs now (check it
     * again), null when none runs, else the timer and its allocation before the change.
     *
     * @throws TimeAllocationException When the subject cannot sit next to the timer's task, or the time would be left unallocated
     *
     * @return array{0: ActiveTimer, 1: TimeSubjectRef|null, 2: string|null}|false|null
     */
    private function applyAllocation(UserInterface $user, string $timerId, TimeAllocationPatch $patch): array|false|null
    {
        $found = $this->getActive($user);
        if (!$found instanceof ActiveTimer) {
            return null;
        }
        if ($found->getId() !== $timerId) {
            return false;
        }
        $timers  = $this->activeTimerRepository;
        $current = $timers instanceof DoctrineOrmActiveTimerRepository ? $timers->lockById($timerId) : $found;
        if (!$current instanceof ActiveTimer) {
            // Only a delete between the read and the row lock, under the owner's lock.
            return null; // @codeCoverageIgnore
        }
        $previousSubject = $current->subject();
        $previousKey     = $current->getActivityTypeKey();
        $subject         = $patch->subjectAfter($previousSubject);
        if ($patch->changesSubjectOf($previousSubject)) {
            $this->allocations->assertAllowsTask($subject, $current->hasTask());
            $this->allocations->assertMayClearSubject($current->hasTask(), $previousSubject, $subject);
        }
        // @igor-ignore - $current is a Doctrine entity loaded for this request, not a shared service.
        $current->allocate($subject, $patch->activityTypeAfter($previousKey));
        $timers->save($current);

        return [$current, $previousSubject, $previousKey];
    }

    /**
     * The task as the provider shows it to the user (since 1.10; live task details of the client API).
     *
     * @return TaskReference|null The task, or null when the user may not see it
     */
    public function findTask(UserInterface $user, string $taskId): ?TaskReference
    {
        return $this->taskProvider->findForUser($taskId, $user);
    }

    /**
     * Lists the tasks the user may track.
     *
     * @param UserInterface $user Viewer
     * @param TaskListQuery $query Search, limit and offset
     *
     * @return list<TaskReference>
     */
    public function listTasks(UserInterface $user, TaskListQuery $query): array
    {
        return $this->taskProvider->listTrackableForUser($user, $query);
    }

    /**
     * Lists every time entry of the period visible to the viewer (newest first). The
     * `nowo_time_track.time_entry.list_query` event may widen the user ids (team reports).
     *
     * @param UserInterface $viewer User asking for the entries
     * @param DateTimeImmutable $from Period start (inclusive)
     * @param DateTimeImmutable $to Period end (inclusive)
     * @param string|null $targetUserId Entries of this user instead of the viewer (access checked by the caller)
     * @param bool $allowOwnerless Since 1.15: false never lists ownerless entries, whatever a listener says (the client API)
     *
     * @return list<TimeEntry>
     */
    public function listEntries(UserInterface $viewer, DateTimeImmutable $from, DateTimeImmutable $to, ?string $targetUserId = null, bool $allowOwnerless = true): array
    {
        $query = $this->resolveEntryQuery($viewer, $from, $to, $targetUserId, $allowOwnerless);
        // Since 1.36.0: another owner's entries without a task only in the viewer's tenant scopes.
        $listing = $this->tasklessListing($viewer, $from, $to, $query);
        if ($listing instanceof TimeEntryListing) {
            if ($this->timeEntryRepository instanceof TasklessScopedTimeEntryRepositoryInterface) {
                $total = $this->timeEntryRepository->countListing($listing);

                return $total === 0 ? [] : $this->timeEntryRepository->findListingPage($listing, 0, $total);
            }

            return array_values(array_filter($this->listEntriesOf($query, $from, $to), static fn (TimeEntry $entry): bool => $entry->hasTask() || $listing->keepsWithoutTask($entry->getUserId(), $entry->getScopeKey())));
        }

        return $this->listEntriesOf($query, $from, $to);
    }

    /**
     * The entries of a resolved query (the 1.35 listing).
     *
     * @param array{userIds: list<string>, includeImported: bool, includeOwnerless: bool, ownerlessScopeKeys: list<string>|null} $query
     *
     * @return list<TimeEntry>
     */
    private function listEntriesOf(array $query, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $entries = [];
        // Since 1.15: with the ownerless entries a list-query listener let this viewer see.
        if ($query['includeOwnerless'] && $this->timeEntryRepository instanceof FormerPersonTimeEntryRepositoryInterface) {
            $total = $this->timeEntryRepository->countWithOwnerless($query['userIds'], $query['ownerlessScopeKeys'], $from, $to, $query['includeImported']);

            return $total === 0 ? [] : $this->timeEntryRepository->findPageWithOwnerless($query['userIds'], $query['ownerlessScopeKeys'], $from, $to, $query['includeImported'], 0, $total);
        }
        foreach ($query['userIds'] as $userId) {
            foreach ($this->timeEntryRepository->findByUserAndPeriod($userId, $from, $to) as $entry) {
                if ($query['includeImported'] || $entry->getSource()->isLive()) {
                    $entries[] = $entry;
                }
            }
        }

        usort($entries, static fn (TimeEntry $a, TimeEntry $b): int => $b->getStartedAt() <=> $a->getStartedAt());

        return $entries;
    }

    /**
     * One page of the time entries of the period visible to the viewer (newest first, REQ-PERF-001). Repositories
     * implementing {@see PaginatedTimeEntryRepositoryInterface} page in the database; others are sliced in memory.
     *
     * @param UserInterface $viewer User asking for the entries
     * @param DateTimeImmutable $from Period start (inclusive)
     * @param DateTimeImmutable $to Period end (inclusive)
     * @param int $page 1-based page number (clamped to the existing pages)
     * @param int $perPage Page size (values below 1 are read as 1)
     * @param string|null $targetUserId Entries of this user instead of the viewer (access checked by the caller)
     * @param bool $allowOwnerless Since 1.15: false never lists ownerless entries, whatever a listener says (the client API)
     *
     * @return TimeEntryPage The entries of the page and the pagination totals
     */
    public function listEntriesPage(UserInterface $viewer, DateTimeImmutable $from, DateTimeImmutable $to, int $page, int $perPage, ?string $targetUserId = null, bool $allowOwnerless = true): TimeEntryPage
    {
        $perPage = max(1, $perPage);

        $repository = $this->timeEntryRepository;
        if ($repository instanceof PaginatedTimeEntryRepositoryInterface) {
            $query   = $this->resolveEntryQuery($viewer, $from, $to, $targetUserId, $allowOwnerless);
            $userIds = $query['userIds'];
            // Since 1.36.0: another owner's entries without a task only in the viewer's tenant scopes, in the database
            // when the store can (else listed in memory below).
            $listing = $this->tasklessListing($viewer, $from, $to, $query);
            if ($listing instanceof TimeEntryListing && $repository instanceof TasklessScopedTimeEntryRepositoryInterface) {
                $total = $repository->countListing($listing);
                $page  = $this->clampPage($page, $total, $perPage);

                return new TimeEntryPage($total === 0 ? [] : $repository->findListingPage($listing, ($page - 1) * $perPage, $perPage), $page, $perPage, $total);
            }
            if ($listing instanceof TimeEntryListing) {
                return $this->slicePage($this->listEntries($viewer, $from, $to, $targetUserId, $allowOwnerless), $page, $perPage);
            }
            if ($query['includeOwnerless'] && $repository instanceof FormerPersonTimeEntryRepositoryInterface) {
                $total = $repository->countWithOwnerless($userIds, $query['ownerlessScopeKeys'], $from, $to, $query['includeImported']);
                $page  = $this->clampPage($page, $total, $perPage);

                return new TimeEntryPage($total === 0 ? [] : $repository->findPageWithOwnerless($userIds, $query['ownerlessScopeKeys'], $from, $to, $query['includeImported'], ($page - 1) * $perPage, $perPage), $page, $perPage, $total);
            }
            if ($query['includeImported']) {
                $total = $repository->countByUsersAndPeriod($userIds, $from, $to);
                $page  = $this->clampPage($page, $total, $perPage);

                return new TimeEntryPage($total === 0 ? [] : $repository->findPageByUsersAndPeriod($userIds, $from, $to, ($page - 1) * $perPage, $perPage), $page, $perPage, $total);
            }
            if ($repository instanceof ImportedTimeEntryStoreInterface) {
                $total = $repository->countLiveByUsersAndPeriod($userIds, $from, $to);
                $page  = $this->clampPage($page, $total, $perPage);

                return new TimeEntryPage($total === 0 ? [] : $repository->findLivePageByUsersAndPeriod($userIds, $from, $to, ($page - 1) * $perPage, $perPage), $page, $perPage, $total);
            }
        }

        return $this->slicePage($this->listEntries($viewer, $from, $to, $targetUserId, $allowOwnerless), $page, $perPage);
    }

    /**
     * @param list<TimeEntry> $entries
     */
    private function slicePage(array $entries, int $page, int $perPage): TimeEntryPage
    {
        $total = count($entries);
        $page  = $this->clampPage($page, $total, $perPage);

        return new TimeEntryPage(array_slice($entries, ($page - 1) * $perPage, $perPage), $page, $perPage, $total);
    }

    /**
     * Since 1.36.0 (Beacon spec 250): the listing that keeps another owner's entries without a task only in the viewer's
     * tenant scopes, or null when nothing needs that rule: no scope provider (a single-tenant host keeps the per-user
     * rules), or a listing of the viewer's own entries only. The rule applies whether `entries.allow_without_task` is on
     * or off (coordinator decision 2026-11-22): rows written while it was on are never opened across scopes; without
     * such rows the listing is the 1.35 one. Asked per call (never memoised: worker mode).
     *
     * Audit YM-M1 (no regression for hosts that never stored such rows): with the option off no entry without a task can
     * be written, so one EXISTS read on the Doctrine store decides; when the listing reaches none, null keeps the 1.35
     * (faster) listing, which is then exactly the same. With the option on the exact listing always runs (a row may be
     * written meanwhile).
     *
     * @param array{userIds: list<string>, includeImported: bool, includeOwnerless: bool, ownerlessScopeKeys: list<string>|null} $query
     */
    private function tasklessListing(UserInterface $viewer, DateTimeImmutable $from, DateTimeImmutable $to, array $query): ?TimeEntryListing
    {
        $viewerId = UserIdResolver::getId($viewer);
        if (!$this->scopeProvider instanceof TimesheetScopeProviderInterface || (!$query['includeOwnerless'] && $query['userIds'] === [$viewerId])) {
            return null;
        }

        $listing = new TimeEntryListing($query['userIds'], $from, $to, $query['includeImported'], $query['includeOwnerless'], $query['ownerlessScopeKeys'], $viewerId, ScopeKeys::ofUser($this->scopeProvider, $viewer, $this->logger));
        if (!$this->allowWithoutTask && $this->timeEntryRepository instanceof TasklessScopedTimeEntryRepositoryInterface && !$this->timeEntryRepository->hasEntriesWithoutTask($listing)) {
            return null;
        }

        return $listing;
    }

    private function clampPage(int $page, int $total, int $perPage): int
    {
        return max(1, min($page, (new TimeEntryPage([], 1, $perPage, $total))->totalPages()));
    }

    /**
     * Since 1.11: ids of the imported entries whose task the entry owner cannot see (by the task provider): their
     * title (and since 1.12 their description) must be masked wherever the entries are shown. One provider call per
     * distinct (owner, task), or (since 1.12) one per owner when the provider implements
     * {@see BatchTaskProviderInterface}. The question is what the entry **owner** may track, not the viewer: a manager
     * sees what the member can see, and a task the owner can see but no longer track (closed) is masked too.
     *
     * Since 1.15 an ownerless entry has no owner to ask: it is judged by what `$viewer` may track, and masked without one.
     * Since 1.36.0 an entry without a task is never masked, unless it is ownerless (then always).
     *
     * @param list<TimeEntry> $entries
     *
     * @return array<string, true> entry id => true
     */
    public function hiddenTaskEntryIds(array $entries, ?UserInterface $viewer = null): array
    {
        $hidden = [];
        /** @var array<string, array{owner: UserInterface, entries: array<string, list<string>>}> $byOwner */
        $byOwner = [];
        foreach ($entries as $entry) {
            if (!$entry->hasTask()) {
                // Since 1.36.0: no task to judge an entry without one by. Its owner wrote the free text, so it shows; an
                // ownerless one (user deleted, not anonymised) has no owner to vouch for it either: masked for everyone.
                if ($entry->isOwnerless()) {
                    $hidden[$entry->getId()] = true;
                }
                continue;
            }
            if ($entry->isOwnerless()) {
                if (!$viewer instanceof UserInterface) {
                    $hidden[$entry->getId()] = true;
                    continue;
                }
                $viewerId                                             = UserIdResolver::getId($viewer);
                $byOwner[$viewerId]['owner']                          = $viewer;
                $byOwner[$viewerId]['entries'][$entry->getTaskId()][] = $entry->getId();
                continue;
            }
            if ($entry->getSource()->isLive()) {
                continue;
            }
            $owner = $entry->getUser();
            if (!$owner instanceof UserInterface) {
                $hidden[$entry->getId()] = true;
                continue;
            }
            $ownerId                                             = UserIdResolver::getId($owner);
            $byOwner[$ownerId]['owner']                          = $owner;
            $byOwner[$ownerId]['entries'][$entry->getTaskId()][] = $entry->getId();
        }
        foreach ($byOwner as ['owner' => $owner, 'entries' => $entryIdsByTask]) {
            foreach ($this->untrackableTaskIds($owner, array_map(strval(...), array_keys($entryIdsByTask))) as $taskId) {
                foreach ($entryIdsByTask[$taskId] as $entryId) {
                    $hidden[$entryId] = true;
                }
            }
        }

        return $hidden;
    }

    /**
     * @param list<string> $taskIds distinct
     *
     * @return list<string> the task ids the user may not track
     */
    private function untrackableTaskIds(UserInterface $user, array $taskIds): array
    {
        if ($this->taskProvider instanceof BatchTaskProviderInterface) {
            return array_values(array_diff($taskIds, $this->taskProvider->filterTrackable($user, $taskIds)));
        }

        return array_values(array_filter($taskIds, fn (string $taskId): bool => !$this->taskProvider->canUserTrack($user, $taskId)));
    }

    /**
     * @return array{userIds: list<string>, includeImported: bool, includeOwnerless: bool, ownerlessScopeKeys: list<string>|null}
     */
    private function resolveEntryQuery(UserInterface $viewer, DateTimeImmutable $from, DateTimeImmutable $to, ?string $targetUserId, bool $allowOwnerless): array
    {
        $event = new TimeEntryListQueryEvent($viewer, $from, $to);
        $this->eventDispatcher->dispatch($event, TimeTrackEvents::TIME_ENTRY_LIST_QUERY);

        $userIds = $event->getUserIds();

        return [
            'userIds'         => $userIds === [] ? [$targetUserId ?? UserIdResolver::getId($viewer)] : $userIds,
            'includeImported' => $event->includesImported(),
            // Since 1.15.
            'includeOwnerless'   => $allowOwnerless && $event->includesOwnerless(),
            'ownerlessScopeKeys' => $event->getOwnerlessScopeKeys(),
        ];
    }

    /**
     * The task when the provider lets the user track it.
     *
     * @throws TaskNotTrackableException When the task is unknown or not trackable by the user (one answer for both)
     */
    private function resolveTrackable(UserInterface $user, string $taskId): TaskReference
    {
        if (!$this->taskProvider->canUserTrack($user, $taskId)) {
            throw TaskNotTrackableException::forTask($taskId);
        }

        $task = $this->taskProvider->findForUser($taskId, $user);
        if (!$task instanceof TaskReference) {
            throw TaskNotTrackableException::forTask($taskId);
        }

        return $task;
    }

    /**
     * Records the running timer as an entry ending at `$endedAt` (plus any extra entries in the same unit of work) and
     * removes the timer; dispatches a stop event per recorded entry.
     *
     * @param list<TimeEntry> $extraEntries Entries recorded together with the timer's own entry (idle reassign)
     * @param array<string, mixed> $extraMetadata Added to the metadata of the timer's own entry (since 1.15)
     * @param array{patch: TimeAllocationPatch, interactive: bool, types: ActivityTypeList|null}|null $stop Since 2.1.0: the checked allocation patch of a stop and whether it is interactive (null = a system stop that keeps the timer's allocation)
     *
     * @throws TimeAllocationRequiredException When an interactive stop misses an allocation (nothing is written)
     *
     * @return TimeEntry|null The timer's entry, or null when a concurrent call removed the timer first
     */
    private function closeTimer(UserInterface $user, ActiveTimer $timer, DateTimeImmutable $endedAt, ?string $note = null, ?ClampedTime $endTime = null, array $extraEntries = [], bool $recordOwnEntry = true, array $extraMetadata = [], ?array $stop = null): ?TimeEntry
    {
        // Asked before the timer is locked: the policy is a host port and may read its own data.
        $serviceAccount = $this->isServiceAccount($user);
        $patch          = $stop['patch'] ?? TimeAllocationPatch::keep();
        $types          = $stop['types'] ?? null;
        $interactive    = $stop['interactive'] ?? false;
        if ($types === null && $timer->getActivityTypeKey() !== null && $this->allocations->activityTypes()->isEnabled()) {
            // Since 2.1.0: the scope's activity types, read before the lock, give the entry its billable default (Q-TS-9).
            $types = $this->allocations->activityTypes()->listFor($this->timerScopeKey($user, $timer));
        }
        $allocations = $this->allocations;
        // Since 1.27.0 (Beacon spec 239): the entry is built from the timer as read under the delete's lock, so a task
        // move (FR-008) or a note committed after the caller loaded the timer is what the entry records.
        $build = function (ActiveTimer $current) use ($user, $endedAt, $note, $extraEntries, $recordOwnEntry, $extraMetadata, $serviceAccount, $patch, $types, $interactive, $allocations): array {
            // Since 2.1.0 (Beacon spec 256): the allocation is the re-read timer's with the stop's patch; an interactive
            // stop that misses a required allocation throws here, before the delete commits, so the timer keeps running.
            $subject  = $patch->subjectAfter($current->subject());
            $activity = $patch->activityTypeAfter($current->getActivityTypeKey());
            if ($patch->changesSubjectOf($current->subject())) {
                $allocations->assertAllowsTask($subject, $current->hasTask());
            }
            $missing = $interactive ? $allocations->missingRequirement($current->hasTask(), $subject, $activity) : null;
            if ($missing !== null) {
                throw new TimeAllocationRequiredException(TimerSnapshot::fromTimer($current), $missing);
            }
            $duration = max(0, $endedAt->getTimestamp() - $current->getStartedAt()->getTimestamp());
            $entry    = new TimeEntry(
                $this->userReferences->reference($user),
                $current->taskIdOrNull(),
                $current->getTaskTitleSnapshot(),
                $current->getBoardIdSnapshot(),
                $current->getStartedAt(),
                $endedAt,
                $duration,
                $this->mapClientTypeToSource($current->getClientType()),
                $extraMetadata === [] ? $this->entryMetadata($this->withoutChannel($current->getMetadata()), $note) : [...($this->entryMetadata($this->withoutChannel($current->getMetadata()), $note) ?? []), ...$extraMetadata],
                $endedAt,
            );
            $entry->assignScopeKey($this->timerScopeKey($user, $current));
            // Since 1.36.0: an entry without a task keeps the timer's free text.
            $entry->describe($current->hasTask() ? null : $current->getDescription());
            $entry->allocate($subject, $activity);
            $this->applyBillableDefault($entry, $activity === null ? null : $types?->find($activity));
            $entry->markOrigin($this->channelOf($current), $serviceAccount);
            foreach ($extraEntries as $extra) {
                $extra->markOrigin($extra->channel(), $serviceAccount);
            }

            return [$entry, $recordOwnEntry ? [$entry, ...$extraEntries] : $extraEntries];
        };

        if ($this->activeTimerRepository instanceof ConditionalActiveTimerRepositoryInterface) {
            $built   = null;
            $entries = $this->timeEntryRepository;
            // 1.9.1 (CL-05): only the call that deletes the timer row records the entries, so concurrent stops record them once.
            // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
            $timers = $this->activeTimerRepository;
            // Since 1.27.0 under the user's write lock first (stores with UserSerialisedTimeEntryRepositoryInterface): one
            // lock order (user, then timer, then entries) for every timer write, so a stop never deadlocks with a start.
            // @igor-ignore - Request-scoped closure passed to a per-request call; nothing is stored on the service.
            // (A closure, not an arrow function: $built is filled by reference.)
            $removed = $this->serialised($user, static function () use ($timers, $timer, $build, &$built, $entries): bool {
                // @igor-ignore - Request-scoped closure passed to a per-request call; nothing is stored on the service.
                return $timers->removeIfPresent($timer, static function (?ActiveTimer $current = null) use ($timer, $build, &$built, $entries): void {
                    $built = $build($current ?? $timer);
                    foreach ($built[1] as $recorded) {
                        $entries->save($recorded);
                    }
                });
            });
            if (!$removed || $built === null) {
                return null;
            }
            [$entry, $entries] = $built;
        } else {
            [$entry, $entries] = $build($timer);
            foreach ($entries as $recorded) {
                $this->timeEntryRepository->save($recorded);
            }
            // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
            $this->activeTimerRepository->remove($timer);
        }

        if ($this->allocations->requiresAllocation() && !$entry->hasTask() && !$entry->subject() instanceof TimeSubjectRef && in_array($entry, $entries, true)) {
            // Since 2.1.0 (Q-TS-2): only a stop the system triggered gets here unallocated (an interactive one was refused).
            $this->eventDispatcher->dispatch(new TimeEntryUnallocatedEvent($entry->getId(), UserIdResolver::getId($user), $entry->getScopeKey(), $entry->closedBy()), TimeTrackEvents::TIME_ENTRY_UNALLOCATED);
        }
        foreach ($entries as $recorded) {
            $this->eventDispatcher->dispatch(new TimerStopEvent($user, $recorded, $recorded === $entry ? $endTime : null), TimeTrackEvents::TIMER_STOP);
            $locked = $this->periodLockGuard?->lockingTimesheet($user, $recorded->getScopeKey(), $recorded->getStartedAt());
            if ($locked instanceof Timesheet) {
                // The period was locked while the timer ran (manual lock): the entry is written, the approver is told.
                $this->eventDispatcher->dispatch(new TimesheetLateEntryEvent($locked->getId(), $recorded->getId()), TimeTrackEvents::TIMESHEET_LATE_ENTRY);
            }
        }
        $this->logger->info('TimeTrack timer stopped.', $this->context('timer_stop', $timer->getClientType()));

        return $entry;
    }

    /**
     * Since 1.25.0 (audit PA-2): the ids of the entries recorded so far.
     *
     * @param list<TimeEntry> $entries
     *
     * @return list<string>
     */
    private function entryIds(array $entries): array
    {
        return array_map(static fn (TimeEntry $entry): string => $entry->getId(), $entries);
    }

    /**
     * Since 1.25.0: the policy's answer for the user, asked per call (never memoised: worker mode).
     *
     * A policy that throws is treated as "a person" and logged (audit PA-3): this runs inside every timer stop, also
     * {@see self::stopForUserDeletion()}, where a policy that reads the account from the database may fail for a user
     * being deleted — and losing the running time there is exactly what that stop exists to prevent.
     */
    private function isServiceAccount(UserInterface $user): bool
    {
        if (!$this->serviceAccounts instanceof ServiceAccountPolicyInterface) {
            return false;
        }

        try {
            return $this->serviceAccounts->isServiceAccount(TimeTrackUserRefs::fromUser($user));
        } catch (Throwable $exception) {
            // REQ-OBS-001: the exception class only, never the account or the task.
            $this->logger->warning('TimeTrack service-account policy failed; the entry is recorded as a person\'s.', ['bundle' => 'time_track', 'action' => 'service_account_policy', 'exception' => $exception::class]);

            return false;
        }
    }

    /**
     * Since 1.25.0: the channel a timer was started through ({@see self::CHANNEL_KEY}), or null. A stored value that is
     * not a valid label (written by hand) is ignored.
     */
    private function channelOf(ActiveTimer $timer): ?string
    {
        $channel = ($timer->getMetadata() ?? [])[self::CHANNEL_KEY] ?? null;

        return is_string($channel) && preg_match(TimerChannel::PATTERN, $channel) === 1 ? $channel : null;
    }

    /**
     * Since 1.25.0: timer metadata without the channel (it moves to the entry's column).
     *
     * @param array<string, mixed>|null $metadata
     *
     * @return array<string, mixed>|null
     */
    private function withoutChannel(?array $metadata): ?array
    {
        if ($metadata === null || !isset($metadata[self::CHANNEL_KEY])) {
            return $metadata;
        }
        unset($metadata[self::CHANNEL_KEY]);

        return $metadata === [] ? null : $metadata;
    }

    /**
     * Since 1.25.0: runs a write of the user's timer in one transaction holding the user's write lock (stores with
     * {@see UserSerialisedTimeEntryRepositoryInterface}; others run it as is).
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function serialised(UserInterface $user, callable $operation): mixed
    {
        return $this->timeEntryRepository instanceof UserSerialisedTimeEntryRepositoryInterface ? $this->timeEntryRepository->inUserTransaction($user, $operation) : $operation();
    }

    /**
     * Since 1.27.0 (Beacon spec 239): appends the note to the timer as committed, read again under its row lock in one
     * unit of work of the bundle's runner, so two notes kept at the same time are both stored (up to 1.26 the second
     * write put back the metadata it had loaded before the first one committed). Stores other than the bundle's
     * Doctrine store keep the 1.26 behaviour.
     *
     * @return ActiveTimer|null The timer with the note, or null when it was stopped meanwhile
     */
    private function appendNote(UserInterface $user, ActiveTimer $timer, string $note): ?ActiveTimer
    {
        $timers = $this->activeTimerRepository;
        if (!$timers instanceof DoctrineOrmActiveTimerRepository) {
            $timers->save($timer->appendNote($note, self::NOTE_MAX_LENGTH));

            return $timer;
        }

        // @igor-ignore - Request-scoped closure passed to a per-request call; nothing is stored on the service.
        $append = static function () use ($timers, $timer, $note): ?ActiveTimer {
            $current = $timers->lockById($timer->getId());
            if ($current instanceof ActiveTimer) {
                // @igor-ignore - $current is a Doctrine entity loaded for this request, not a shared service.
                $timers->save($current->appendNote($note, self::NOTE_MAX_LENGTH));
            }

            return $current;
        };

        // The user's write lock first (one lock order for every timer write), then the timer's own unit of work.
        return $this->serialised($user, static fn (): ?ActiveTimer => $timers->runner()->run($append));
    }

    /**
     * Since 1.27.0 (Beacon spec 239): inserts a new timer while the user has none, checked again under the user's write
     * lock (stores with {@see UserSerialisedTimeEntryRepositoryInterface}), so two concurrent starts never reach the
     * unique `user_id` index: the second one sees the first one's timer and is refused without a failed flush (which
     * closed the entity manager, and up to 1.26 reset the application's manager in the middle of the request). The index
     * stays the backstop for a writer that does not take the lock.
     *
     * @throws ActiveTimerConflictException When the user has a running timer (a concurrent start won)
     */
    private function insertTimer(UserInterface $user, ActiveTimer $timer, string $action, ClientType $clientType): void
    {
        try {
            // @igor-ignore - Request-scoped closure passed to a per-request call; nothing is stored on the service.
            $running = $this->timeEntryRepository instanceof UserSerialisedTimeEntryRepositoryInterface ? $this->timeEntryRepository->inUserTransaction($user, function () use ($user, $timer): ?ActiveTimer {
                $running = $this->getActive($user);
                if (!$running instanceof ActiveTimer) {
                    $this->activeTimerRepository->save($timer);
                }

                return $running;
            }) : $this->saveTimer($timer);
        } catch (UniqueConstraintViolationException $e) {
            // A start that does not take the user's lock inserted its timer first; the unique user_id index wins.
            $this->logger->warning('TimeTrack timer start rejected: concurrent start.', $this->context($action, $clientType));

            throw ActiveTimerConflictException::concurrentStart($e);
        }
        if ($running instanceof ActiveTimer) {
            $this->logger->warning('TimeTrack timer start rejected: concurrent start.', $this->context($action, $clientType));

            throw ActiveTimerConflictException::concurrentStart();
        }
    }

    /**
     * Saves a new timer without a lock (stores without {@see UserSerialisedTimeEntryRepositoryInterface}: the unique
     * index decides); never a running timer.
     */
    private function saveTimer(ActiveTimer $timer): null
    {
        $this->activeTimerRepository->save($timer);

        return null;
    }

    /**
     * Since 1.21: the host default `billable` of a new entry ({@see TimeEntryBillableDefaultProviderInterface}).
     */
    private function applyBillableDefault(TimeEntry $entry, ?ActivityType $activityType = null): void
    {
        // Since 1.36.0: the host default is per task; an entry without a task has none (the flag stays unset). Since 2.1.0
        // the activity type's default comes first (Q-TS-9: explicit, activity type, host provider, none).
        $entry->markBillable(AllocationGuard::billableDefault($activityType, fn (): ?bool => $entry->hasTask() ? $this->billableDefaults?->defaultFor($entry->getScopeKey() ?? '', $entry->getTaskId()) : null));
    }

    /**
     * Metadata of the entry recorded from a timer: the timer's metadata, with a stop / switch note merged into the
     * timer's note (keep notes first, then this one; {@see NoteSanitizer::merge()}, EF2-I4).
     *
     * @param array<string, mixed>|null $metadata
     *
     * @return array<string, mixed>|null
     */
    private function entryMetadata(?array $metadata, ?string $note): ?array
    {
        $note = $note !== null ? NoteSanitizer::cleanAndCut($note) : null;
        if ($note === null) {
            return $metadata;
        }

        $metadata ??= [];
        $existing         = $metadata['note'] ?? null;
        $metadata['note'] = NoteSanitizer::merge(is_string($existing) ? $existing : null, $note);

        return $metadata;
    }

    /**
     * Metadata of a new timer: a `note` is cleaned like every note (also for hosts calling {@see self::start()} /
     * {@see self::startAt()} directly, EF2-I9); a note that is not a string or is blank after cleaning is dropped.
     *
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    private function startMetadata(array $metadata): array
    {
        // Since 1.15: reserved for {@see self::stopForUserDeletion()} (and since 1.25.0 {@see self::startReplacing()}); a
        // caller cannot forge the mark. Since 1.25.0 the channel too: only {@see self::startReplacing()} sets it.
        unset($metadata['closed_by'], $metadata[self::CHANNEL_KEY]);
        if (!isset($metadata['note'])) {
            return $metadata;
        }

        $note = is_string($metadata['note']) ? NoteSanitizer::cleanAndCut($metadata['note']) : null;
        unset($metadata['note']);
        if ($note !== null) {
            $metadata['note'] = $note;
        }

        return $metadata;
    }

    /**
     * Metadata for a follow-up timer: the note stays on the entry already recorded.
     *
     * @param array<string, mixed>|null $metadata
     *
     * @return array<string, mixed>|null
     */
    private function withoutNote(?array $metadata): ?array
    {
        if (isset($metadata['note'])) {
            unset($metadata['note']);
        }

        return $metadata;
    }

    /**
     * Gives an idle span to another task (`reassign`): entry up to the idle start, entry of the span on the target,
     * then the follow-up timer.
     *
     * @return array{entry: TimeEntry|null, timer: ActiveTimer|null, reassigned: TimeEntry}|null
     */
    private function reassignIdle(UserInterface $user, ActiveTimer $timer, DateTimeImmutable $from, DateTimeImmutable $to, ?TaskReference $target, TimeAllocation $allocation, ?string $targetScope, IdleFollowUp $followUp, ClientType $clientType, ?string $note): ?array
    {
        $reassigned = new TimeEntry(
            $this->userReferences->reference($user),
            $target?->id,
            $target->title ?? '',
            $target?->boardId,
            $from,
            $to,
            max(0, $to->getTimestamp() - $from->getTimestamp()),
            $this->mapClientTypeToSource($clientType),
            $note !== null ? ['note' => $note] : null,
            $this->clock->now(),
        );
        $reassigned->assignScopeKey($targetScope);
        $reassigned->allocate($allocation->subject, $allocation->activityTypeKey);
        $reassigned->markOrigin($this->channelOf($timer), false); // the service-account mark is set by closeTimer()
        $this->applyBillableDefault($reassigned, $allocation->activityTypeKey === null ? null : $this->allocations->activityTypes()->listFor($targetScope)->find($allocation->activityTypeKey));
        $hasOwnEntry = $from > $timer->getStartedAt();

        $entry = $this->closeTimer($user, $timer, $from, null, null, [$reassigned], $hasOwnEntry);
        if (!$entry instanceof TimeEntry) {
            return null; // A concurrent call stopped the timer first (CL-05): nothing was written by this call.
        }

        $next = match ($followUp) {
            IdleFollowUp::Continue => $this->continueTimer($user, $timer, $timer->getClientType(), $this->withoutNote($timer->getMetadata()), $to, 'timer_idle_reassign'),
            IdleFollowUp::Switch   => $this->continueTimer($user, $target ?? $allocation, $clientType, null, $to, 'timer_idle_reassign', $allocation, $targetScope),
            IdleFollowUp::Stop     => null,
        };

        $this->logger->info('TimeTrack idle span reassigned.', $this->context('timer_idle_reassign', $clientType) + ['then' => $followUp->value]);
        $this->dispatchIdleResolved($user, IdleResolution::Reassign, $from, $to, $clientType, $target?->id, $followUp, $note);

        return ['entry' => $hasOwnEntry ? $entry : null, 'timer' => $next, 'reassigned' => $reassigned];
    }

    /**
     * Starts the timer that follows an idle decision: the same allocation as the stopped timer (split, continue), or the
     * reassign target (switch). Since 1.36.0 a timer without a task continues without one, with its free text and scope;
     * since 2.1.0 the subject and activity type go with it.
     *
     * @param array<string, mixed>|null $metadata
     *
     * @throws ActiveTimerConflictException When a concurrent start took the timer slot first (the recorded entries stay)
     */
    private function continueTimer(UserInterface $user, TaskReference|ActiveTimer|TimeAllocation $from, ClientType $clientType, ?array $metadata, DateTimeImmutable $startedAt, string $action, ?TimeAllocation $allocation = null, ?string $scopeKey = null): ActiveTimer
    {
        $owner = $this->userReferences->reference($user);
        $next  = match (true) {
            $from instanceof TaskReference  => new ActiveTimer($owner, $from->id, $from->title, $from->boardId, $clientType, $metadata, $startedAt),
            $from instanceof TimeAllocation => ActiveTimer::withoutTask($owner, $clientType, null, $scopeKey, $metadata, $startedAt),
            $from->hasTask()                => new ActiveTimer($owner, $from->getTaskId(), $from->getTaskTitleSnapshot(), $from->getBoardIdSnapshot(), $clientType, $metadata, $startedAt),
            default                         => ActiveTimer::withoutTask($owner, $clientType, $from->getDescription(), $from->getScopeKey(), $metadata, $startedAt),
        };
        if ($from instanceof ActiveTimer) {
            $next->allocate($from->subject(), $from->getActivityTypeKey());
        } elseif ($allocation instanceof TimeAllocation) {
            $next->allocate($allocation->subject, $allocation->activityTypeKey);
        }
        // A concurrent start won between the stop and the continuation (1.9.1): the entries stay, its timer runs.
        $this->insertTimer($user, $next, $action, $clientType);
        if ($next->hasTask()) {
            $task = $from instanceof TaskReference ? $from : new TaskReference($next->getTaskId(), $next->getTaskTitleSnapshot(), $next->getBoardIdSnapshot());
            $this->eventDispatcher->dispatch(new TimerStartEvent($user, $task, $clientType, null, $next->subject(), $next->getActivityTypeKey()), TimeTrackEvents::TIMER_START);
        } else {
            $this->dispatchStartedWithoutTask($user, $next);
        }

        return $next;
    }

    /**
     * Since 1.36.0: the tenant scope of the entry a timer records: the task's (asked again, as since 1.13), or the scope
     * a timer without a task was started in.
     */
    private function timerScopeKey(UserInterface $user, ActiveTimer $timer): ?string
    {
        return $timer->hasTask() ? $this->scopeKeyFor($user, $timer->getTaskId(), $timer->getBoardIdSnapshot()) : $timer->getScopeKey();
    }

    private function dispatchIdleResolved(UserInterface $user, IdleResolution $resolution, DateTimeImmutable $from, DateTimeImmutable $to, ClientType $clientType, ?string $targetTaskId = null, ?IdleFollowUp $followUp = null, ?string $note = null): void
    {
        $this->eventDispatcher->dispatch(new IdleResolvedEvent($user, $resolution, $from, $to, $clientType, $targetTaskId, $followUp, $note), TimeTrackEvents::IDLE_RESOLVED);
    }

    /**
     * Clamps a client-supplied start (see {@see self::startAt()}).
     */
    private function clampStart(UserInterface $user, DateTimeImmutable $requested, BackdateLimits $limits, DateTimeImmutable $now): ClampedTime
    {
        $requested = $this->toSecond($requested);
        if (!$limits->isEnabled()) {
            return new ClampedTime($requested, $now, true, ClampedTime::REASON_DISABLED);
        }

        if ($requested > $now) {
            return $requested->getTimestamp() > $now->getTimestamp() + $limits->clockSkewSeconds
                ? new ClampedTime($requested, $now, true, ClampedTime::REASON_FUTURE)
                : new ClampedTime($requested, $now);
        }

        $lower  = $now->modify(sprintf('-%d seconds', $limits->maxSeconds));
        $reason = ClampedTime::REASON_MAX_BACKDATE;
        // Since 1.25.0: a service account's entries may overlap, so its own latest entry does not clamp the start.
        $lastEnd = $this->isServiceAccount($user) ? null : $this->latestEndedAt(UserIdResolver::getId($user), $lower);
        if ($lastEnd instanceof DateTimeImmutable && $lastEnd >= $lower) {
            $lower  = min($lastEnd, $now);
            $reason = ClampedTime::REASON_OVERLAP;
        }

        return $requested < $lower
            ? new ClampedTime($requested, $lower, true, $reason)
            : new ClampedTime($requested, $requested);
    }

    /**
     * Clamps a client-supplied end (see {@see self::stopAt()}).
     */
    private function clampEnd(ActiveTimer $timer, DateTimeImmutable $requested, BackdateLimits $limits, DateTimeImmutable $now): ClampedTime
    {
        $requested = $this->toSecond($requested);
        if (!$limits->isEnabled()) {
            return new ClampedTime($requested, $now, true, ClampedTime::REASON_DISABLED);
        }

        if ($requested > $now) {
            return $requested->getTimestamp() > $now->getTimestamp() + $limits->clockSkewSeconds
                ? new ClampedTime($requested, $now, true, ClampedTime::REASON_FUTURE)
                : new ClampedTime($requested, $now);
        }

        return $requested < $timer->getStartedAt()
            ? new ClampedTime($requested, $timer->getStartedAt(), true, ClampedTime::REASON_BEFORE_START)
            : new ClampedTime($requested, $requested);
    }

    /**
     * End of the user's latest entry. Stores without {@see LatestTimeEntryRepositoryInterface} are read from one day
     * before `$since` (entries that started before the backdate window and ended inside it).
     */
    private function latestEndedAt(string $userId, DateTimeImmutable $since): ?DateTimeImmutable
    {
        if ($this->timeEntryRepository instanceof LatestTimeEntryRepositoryInterface) {
            return $this->timeEntryRepository->findLatestEndedAt($userId);
        }

        $latest = null;
        foreach ($this->timeEntryRepository->findByUserAndPeriod($userId, $since->modify('-1 day'), $this->clock->now()) as $entry) {
            // Since 1.11: imported entries are history and never clamp a live start.
            if (!$entry->getSource()->isLive()) {
                continue;
            }
            if (!$latest instanceof DateTimeImmutable || $entry->getEndedAt() > $latest) {
                $latest = $entry->getEndedAt();
            }
        }

        return $latest;
    }

    /**
     * The same instant without fractions of a second (storage is to the second), in the server's default time zone:
     * Doctrine stores the wall time without its offset and hydrates it in that zone (EF-M1), so a value kept in another
     * zone would be stored shifted.
     */
    private function toSecond(DateTimeImmutable $time): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $time->getTimestamp()))->setTimezone(new DateTimeZone(date_default_timezone_get()));
    }

    /**
     * Since 1.13: tenant scope of a task for a new entry (null without timesheets). Since 1.24.1 (audit OM TT-L2) a key
     * that is not usable is treated as no scope with a warning: the timer stop still saves the entry.
     */
    private function scopeKeyFor(UserInterface $user, string $taskId, ?string $boardId): ?string
    {
        return ScopeKeys::fromHost($this->scopeProvider?->scopeKeyForTask($user, $taskId, $boardId), $this->logger, 'timer_entry_scope');
    }

    /**
     * Log context without personal data (REQ-OBS-001).
     *
     * @return array{bundle: string, action: string, client: string}
     */
    private function context(string $action, ClientType $clientType): array
    {
        return ['bundle' => 'time_track', 'action' => $action, 'client' => $clientType->value];
    }

    private function mapClientTypeToSource(ClientType $clientType): TimeEntrySource
    {
        return match ($clientType) {
            ClientType::Extension => TimeEntrySource::Extension,
            ClientType::Desktop   => TimeEntrySource::Desktop,
            ClientType::Web       => TimeEntrySource::Web,
        };
    }
}
