<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Service;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use InvalidArgumentException;
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
use Nowo\TimeTrackBundle\Event\TimerStartEvent;
use Nowo\TimeTrackBundle\Event\TimerStopEvent;
use Nowo\TimeTrackBundle\Event\TimesheetLateEntryEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Exception\ActiveTimerConflictException;
use Nowo\TimeTrackBundle\Exception\PeriodLockedException;
use Nowo\TimeTrackBundle\Exception\PeriodSubmittedException;
use Nowo\TimeTrackBundle\Exception\TaskNotTrackableException;
use Nowo\TimeTrackBundle\Integration\BatchTaskProviderInterface;
use Nowo\TimeTrackBundle\Integration\TaskProviderInterface;
use Nowo\TimeTrackBundle\Repository\ActiveTimerRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\ConditionalActiveTimerRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\ImportedTimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\LatestTimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\PaginatedTimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Support\NoteSanitizer;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScopeProviderInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_diff;
use function array_filter;
use function array_keys;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function date_default_timezone_get;
use function is_string;
use function max;
use function mb_strlen;
use function min;
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
 */
final readonly class TimerService
{
    /** Max length of a note on a timer or an entry, in code points ({@see NoteSanitizer::MAX_LENGTH}). */
    public const NOTE_MAX_LENGTH = NoteSanitizer::MAX_LENGTH;

    private ClockInterface $clock;

    private LoggerInterface $logger;

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
    ) {
        $this->clock  = $clock ?? new NativeClock();
        $this->logger = $logger ?? new NullLogger();
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
     * @param string $taskId Task id resolved through the task provider
     * @param ClientType $clientType Client that starts the timer (web, extension, desktop)
     * @param array<string, mixed> $metadata Client metadata stored with the timer
     *
     * @throws ActiveTimerConflictException When the user already has a running timer
     * @throws TaskNotTrackableException When the task is unknown or not trackable by the user
     *
     * @return ActiveTimer The new running timer
     */
    public function start(UserInterface $user, string $taskId, ClientType $clientType, array $metadata = []): ActiveTimer
    {
        return $this->startAt($user, $taskId, $clientType, $metadata)['timer'];
    }

    /**
     * Starts a timer, optionally from a client-supplied start (`startedAt` on `timer/start`, since 1.10). The start is
     * clamped: not after now (beyond the clock-skew allowance it is reported as `future`), not before the end of the
     * user's latest entry (`overlap`) and not older than the backdate limit (`max_backdate`).
     *
     * @param UserInterface $user Timer owner
     * @param string $taskId Task id resolved through the task provider
     * @param ClientType $clientType Client that starts the timer
     * @param array<string, mixed> $metadata Client metadata stored with the timer
     * @param DateTimeImmutable|null $requestedStart Client-supplied start (null = now, 1.9 behaviour)
     * @param BackdateLimits|null $limits Backdate limits (null = the defaults)
     *
     * @throws ActiveTimerConflictException When the user already has a running timer
     * @throws TaskNotTrackableException When the task is unknown or not trackable by the user
     * @throws PeriodLockedException When the (applied) start is in a locked period (since 1.13)
     * @throws PeriodSubmittedException When the (applied) start is in a submitted period (since 1.13)
     *
     * @return array{timer: ActiveTimer, task: TaskReference, startTime: ClampedTime|null} The timer, its task and the clamp result (null without a requested start)
     */
    public function startAt(UserInterface $user, string $taskId, ClientType $clientType, array $metadata = [], ?DateTimeImmutable $requestedStart = null, ?BackdateLimits $limits = null): array
    {
        $existing = $this->getActive($user);
        if ($existing instanceof ActiveTimer) {
            throw ActiveTimerConflictException::alreadyRunning($existing->getTaskId());
        }

        $task      = $this->resolveTrackable($user, $taskId);
        $metadata  = $this->startMetadata($metadata);
        $now       = $this->clock->now();
        $startTime = $requestedStart instanceof DateTimeImmutable ? $this->clampStart($user, $requestedStart, $limits ?? new BackdateLimits(), $now) : null;
        $startedAt = $startTime instanceof ClampedTime ? $startTime->applied : $now;
        $this->periodLockGuard?->assertWritable($user, $this->scopeKeyFor($user, $task->id, $task->boardId), $startedAt, WriteKind::TimerStart);

        $timer = new ActiveTimer(
            $user,
            $task->id,
            $task->title,
            $task->boardId,
            $clientType,
            $metadata,
            $startedAt,
        );

        try {
            $this->activeTimerRepository->save($timer);
        } catch (UniqueConstraintViolationException $e) {
            // Two concurrent starts both passed the getActive() check; the unique user_id index wins.
            $this->logger->warning('TimeTrack timer start rejected: concurrent start.', $this->context('timer_start', $clientType));

            throw ActiveTimerConflictException::concurrentStart($e);
        }

        $this->eventDispatcher->dispatch(new TimerStartEvent($user, $task, $clientType, $startTime), TimeTrackEvents::TIMER_START);
        $this->logger->info('TimeTrack timer started.', $this->context('timer_start', $clientType) + ['backdated' => $startTime instanceof ClampedTime]);
        if ($startTime instanceof ClampedTime && $startTime->clamped) {
            $this->logger->info('TimeTrack timer start clamped.', $this->context('timer_start_clamped', $clientType) + ['reason' => $startTime->reason]);
        }

        return ['timer' => $timer, 'task' => $task, 'startTime' => $startTime];
    }

    /**
     * Stops the running timer of the user and records it as a time entry.
     *
     * @param UserInterface $user Timer owner
     * @param DateTimeImmutable|null $endedAt End of the entry (default: now; used by the idle resolution)
     * @param string|null $note Note of the user stored in the entry metadata (`note`)
     *
     * @return TimeEntry|null The recorded entry, or null when no timer was running (or a concurrent stop recorded it)
     */
    public function stop(UserInterface $user, ?DateTimeImmutable $endedAt = null, ?string $note = null): ?TimeEntry
    {
        $timer = $this->getActive($user);
        if (!$timer instanceof ActiveTimer) {
            return null;
        }

        return $this->closeTimer($user, $timer, $endedAt ?? $this->clock->now(), $note);
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
     *
     * @return array{entry: TimeEntry, endTime: ClampedTime|null}|null The entry and the clamp result, or null when no timer was running
     */
    public function stopAt(UserInterface $user, ?DateTimeImmutable $requestedEnd = null, ?BackdateLimits $limits = null, ?string $note = null): ?array
    {
        $timer = $this->getActive($user);
        if (!$timer instanceof ActiveTimer) {
            return null;
        }

        $now     = $this->clock->now();
        $endTime = $requestedEnd instanceof DateTimeImmutable ? $this->clampEnd($timer, $requestedEnd, $limits ?? new BackdateLimits(), $now) : null;
        $entry   = $this->closeTimer($user, $timer, $endTime instanceof ClampedTime ? $endTime->applied : $now, $note, $endTime);
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
     * @param string $taskId Task to track next
     * @param ClientType $clientType Client that switches
     * @param string|null $note Note stored in the entry of the stopped timer
     *
     * @throws TaskNotTrackableException When the task is unknown or not trackable by the user
     * @throws ActiveTimerConflictException When a concurrent start won the race
     * @throws PeriodLockedException When the current period is locked (since 1.13; the running timer is left untouched)
     * @throws PeriodSubmittedException When the current period is submitted (since 1.13)
     *
     * @return array{entry: TimeEntry|null, timer: ActiveTimer} The entry of the stopped timer and the new timer
     */
    public function switchTo(UserInterface $user, string $taskId, ClientType $clientType, ?string $note = null): array
    {
        $task = $this->resolveTrackable($user, $taskId);
        // Since 1.13: refused before the running timer stops, so a locked period leaves it untouched.
        $this->periodLockGuard?->assertWritable($user, $this->scopeKeyFor($user, $task->id, $task->boardId), $this->clock->now(), WriteKind::TimerStart);

        $entry = $this->stop($user, null, $note);

        return ['entry' => $entry, 'timer' => $this->start($user, $taskId, $clientType)];
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
     * @param string|null $targetTaskId Task the span goes to (`reassign` only; required there)
     * @param IdleFollowUp $followUp What runs after the span (`reassign` only)
     * @param ClientType|null $clientType Client of the decision and source of the reassigned entry (null = the timer's client)
     * @param string|null $note `keep`: added to the running timer's note (1.10.1, see {@see ActiveTimer::appendNote()}); `reassign`: stored on the reassigned entry
     *
     * @throws ActiveTimerConflictException When a concurrent start took the timer slot before the follow-up timer started
     * @throws TaskNotTrackableException When the reassign target is unknown or not trackable by the user
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
        ?string $targetTaskId = null,
        IdleFollowUp $followUp = IdleFollowUp::Continue,
        ?ClientType $clientType = null,
        ?string $note = null,
    ): ?array {
        // Whole seconds in the server zone (EF-M1, EF-I5): what is compared is what is stored.
        $from   = $this->toSecond($from);
        $to     = $this->toSecond($to);
        $target = null;
        if ($resolution === IdleResolution::Reassign) {
            if ($targetTaskId === null || $targetTaskId === '') {
                throw new InvalidArgumentException('taskId is required to reassign an idle span.');
            }

            $target = $this->resolveTrackable($user, $targetTaskId);
        }

        $timer = $this->getActive($user);
        if (!$timer instanceof ActiveTimer) {
            return null;
        }

        if ($target instanceof TaskReference && $target->id === $timer->getTaskId()) {
            throw new InvalidArgumentException('The idle span is already on this task: use keep or split.');
        }

        if ($from < $timer->getStartedAt() || $to <= $from || $to > $this->clock->now()) {
            throw new InvalidArgumentException('The idle span must lie inside the running timer.');
        }

        $clientType ??= $timer->getClientType();
        $note = $note !== null ? NoteSanitizer::cleanAndCut($note) : null;

        if ($resolution !== IdleResolution::Keep && $this->periodLockGuard instanceof PeriodLockGuard) {
            $this->periodLockGuard->assertWritable($user, $this->scopeKeyFor($user, $timer->getTaskId(), $timer->getBoardIdSnapshot()), $from, WriteKind::IdleResolve);
            if ($target instanceof TaskReference) {
                $this->periodLockGuard->assertWritable($user, $this->scopeKeyFor($user, $target->id, $target->boardId), $from, WriteKind::IdleResolve);
            }
        }

        if ($resolution === IdleResolution::Keep) {
            if ($note !== null) {
                // @igor-ignore - $timer is a Doctrine entity loaded for this request, not a shared service.
                $this->activeTimerRepository->save($timer->appendNote($note, self::NOTE_MAX_LENGTH));
            }

            // REQ-OBS-001: the note's length only, never its text.
            $this->logger->info('TimeTrack idle span kept.', $this->context('timer_idle_keep', $clientType) + ['note_length' => $note === null ? 0 : mb_strlen($note)]);
            $this->dispatchIdleResolved($user, $resolution, $from, $to, $clientType, null, null, $note);

            return ['entry' => null, 'timer' => $timer, 'reassigned' => null];
        }

        if ($target instanceof TaskReference) {
            return $this->reassignIdle($user, $timer, $from, $to, $target, $followUp, $clientType, $note);
        }

        $entry = $this->stop($user, $from);
        if (!$entry instanceof TimeEntry) {
            return null; // A concurrent call stopped the timer first (1.9.1, CL-05).
        }

        if ($resolution === IdleResolution::Discard) {
            $this->dispatchIdleResolved($user, $resolution, $from, $to, $clientType);

            return ['entry' => $entry, 'timer' => null, 'reassigned' => null];
        }

        // The note stays on the entry up to the idle start; the follow-up timer starts without it (EF2-I4).
        $next = $this->continueTimer($user, $timer->getTaskId(), $timer->getTaskTitleSnapshot(), $timer->getBoardIdSnapshot(), $timer->getClientType(), $this->withoutNote($timer->getMetadata()), $to, 'timer_split');
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

        $timer->heartbeat($isIdle, $this->clock->now());
        $this->activeTimerRepository->save($timer);

        return $timer;
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
     *
     * @return list<TimeEntry>
     */
    public function listEntries(UserInterface $viewer, DateTimeImmutable $from, DateTimeImmutable $to, ?string $targetUserId = null): array
    {
        $entries = [];
        $query   = $this->resolveEntryQuery($viewer, $from, $to, $targetUserId);
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
     *
     * @return TimeEntryPage The entries of the page and the pagination totals
     */
    public function listEntriesPage(UserInterface $viewer, DateTimeImmutable $from, DateTimeImmutable $to, int $page, int $perPage, ?string $targetUserId = null): TimeEntryPage
    {
        $perPage = max(1, $perPage);

        $repository = $this->timeEntryRepository;
        if ($repository instanceof PaginatedTimeEntryRepositoryInterface) {
            $query   = $this->resolveEntryQuery($viewer, $from, $to, $targetUserId);
            $userIds = $query['userIds'];
            if ($query['includeImported']) {
                $total = $repository->countByUsersAndPeriod($userIds, $from, $to);
                $page  = $this->clampPage($page, $total, $perPage);

                return new TimeEntryPage($total === 0 ? [] : $repository->findPageByUsersAndPeriod($userIds, $from, $to, ($page - 1) * $perPage, $perPage), $page, $perPage, $total);
            }
            if ($repository instanceof ImportedTimeEntryRepositoryInterface) {
                $total = $repository->countLiveByUsersAndPeriod($userIds, $from, $to);
                $page  = $this->clampPage($page, $total, $perPage);

                return new TimeEntryPage($total === 0 ? [] : $repository->findLivePageByUsersAndPeriod($userIds, $from, $to, ($page - 1) * $perPage, $perPage), $page, $perPage, $total);
            }
        }

        $entries = $this->listEntries($viewer, $from, $to, $targetUserId);
        $total   = count($entries);
        $page    = $this->clampPage($page, $total, $perPage);

        return new TimeEntryPage(array_slice($entries, ($page - 1) * $perPage, $perPage), $page, $perPage, $total);
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
     * @param list<TimeEntry> $entries
     *
     * @return array<string, true> entry id => true
     */
    public function hiddenTaskEntryIds(array $entries): array
    {
        $hidden = [];
        /** @var array<string, array{owner: UserInterface, entries: array<string, list<string>>}> $byOwner */
        $byOwner = [];
        foreach ($entries as $entry) {
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
     * @return array{userIds: list<string>, includeImported: bool}
     */
    private function resolveEntryQuery(UserInterface $viewer, DateTimeImmutable $from, DateTimeImmutable $to, ?string $targetUserId): array
    {
        $event = new TimeEntryListQueryEvent($viewer, $from, $to);
        $this->eventDispatcher->dispatch($event, TimeTrackEvents::TIME_ENTRY_LIST_QUERY);

        $userIds = $event->getUserIds();

        return [
            'userIds'         => $userIds === [] ? [$targetUserId ?? UserIdResolver::getId($viewer)] : $userIds,
            'includeImported' => $event->includesImported(),
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
     *
     * @return TimeEntry|null The timer's entry, or null when a concurrent call removed the timer first
     */
    private function closeTimer(UserInterface $user, ActiveTimer $timer, DateTimeImmutable $endedAt, ?string $note = null, ?ClampedTime $endTime = null, array $extraEntries = [], bool $recordOwnEntry = true): ?TimeEntry
    {
        $duration = max(0, $endedAt->getTimestamp() - $timer->getStartedAt()->getTimestamp());

        $entry = new TimeEntry(
            $user,
            $timer->getTaskId(),
            $timer->getTaskTitleSnapshot(),
            $timer->getBoardIdSnapshot(),
            $timer->getStartedAt(),
            $endedAt,
            $duration,
            $this->mapClientTypeToSource($timer->getClientType()),
            $this->entryMetadata($timer->getMetadata(), $note),
            $endedAt,
        );
        $entry->assignScopeKey($this->scopeKeyFor($user, $timer->getTaskId(), $timer->getBoardIdSnapshot()));
        $entries = $recordOwnEntry ? [$entry, ...$extraEntries] : $extraEntries;

        if ($this->activeTimerRepository instanceof ConditionalActiveTimerRepositoryInterface) {
            // 1.9.1 (CL-05): only the call that deletes the timer row records the entries, so concurrent stops record them once.
            // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
            if (!$this->activeTimerRepository->removeIfPresent($timer, function () use ($entries): void {
                foreach ($entries as $recorded) {
                    $this->timeEntryRepository->save($recorded);
                }
            })) {
                return null;
            }
        } else {
            foreach ($entries as $recorded) {
                $this->timeEntryRepository->save($recorded);
            }
            // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
            $this->activeTimerRepository->remove($timer);
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
    private function reassignIdle(UserInterface $user, ActiveTimer $timer, DateTimeImmutable $from, DateTimeImmutable $to, TaskReference $target, IdleFollowUp $followUp, ClientType $clientType, ?string $note): ?array
    {
        $reassigned = new TimeEntry(
            $user,
            $target->id,
            $target->title,
            $target->boardId,
            $from,
            $to,
            max(0, $to->getTimestamp() - $from->getTimestamp()),
            $this->mapClientTypeToSource($clientType),
            $note !== null ? ['note' => $note] : null,
            $this->clock->now(),
        );
        $reassigned->assignScopeKey($this->scopeKeyFor($user, $target->id, $target->boardId));
        $hasOwnEntry = $from > $timer->getStartedAt();

        $entry = $this->closeTimer($user, $timer, $from, null, null, [$reassigned], $hasOwnEntry);
        if (!$entry instanceof TimeEntry) {
            return null; // A concurrent call stopped the timer first (CL-05): nothing was written by this call.
        }

        $next = match ($followUp) {
            IdleFollowUp::Continue => $this->continueTimer($user, $timer->getTaskId(), $timer->getTaskTitleSnapshot(), $timer->getBoardIdSnapshot(), $timer->getClientType(), $this->withoutNote($timer->getMetadata()), $to, 'timer_idle_reassign'),
            IdleFollowUp::Switch   => $this->continueTimer($user, $target->id, $target->title, $target->boardId, $clientType, null, $to, 'timer_idle_reassign', $target),
            IdleFollowUp::Stop     => null,
        };

        $this->logger->info('TimeTrack idle span reassigned.', $this->context('timer_idle_reassign', $clientType) + ['then' => $followUp->value]);
        $this->dispatchIdleResolved($user, IdleResolution::Reassign, $from, $to, $clientType, $target->id, $followUp, $note);

        return ['entry' => $hasOwnEntry ? $entry : null, 'timer' => $next, 'reassigned' => $reassigned];
    }

    /**
     * Starts the follow-up timer of an idle decision at `$startedAt`.
     *
     * @param array<string, mixed>|null $metadata
     *
     * @throws ActiveTimerConflictException When a concurrent start took the timer slot first (the recorded entries stay)
     */
    private function continueTimer(UserInterface $user, string $taskId, string $taskTitle, ?string $boardId, ClientType $clientType, ?array $metadata, DateTimeImmutable $startedAt, string $action, ?TaskReference $task = null): ActiveTimer
    {
        $next = new ActiveTimer($user, $taskId, $taskTitle, $boardId, $clientType, $metadata, $startedAt);
        try {
            $this->activeTimerRepository->save($next);
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent start won between the stop and the continuation (1.9.1): the entries stay, its timer runs.
            $this->logger->warning('TimeTrack idle follow-up timer rejected: concurrent start.', $this->context($action, $clientType));

            throw ActiveTimerConflictException::concurrentStart($e);
        }
        $this->eventDispatcher->dispatch(new TimerStartEvent($user, $task ?? new TaskReference($taskId, $taskTitle, $boardId), $clientType), TimeTrackEvents::TIMER_START);

        return $next;
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

        $lower   = $now->modify(sprintf('-%d seconds', $limits->maxSeconds));
        $reason  = ClampedTime::REASON_MAX_BACKDATE;
        $lastEnd = $this->latestEndedAt(UserIdResolver::getId($user), $lower);
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
     * Since 1.13: tenant scope of a task for a new entry (null without timesheets).
     */
    private function scopeKeyFor(UserInterface $user, string $taskId, ?string $boardId): ?string
    {
        return $this->scopeProvider?->scopeKeyForTask($user, $taskId, $boardId);
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
