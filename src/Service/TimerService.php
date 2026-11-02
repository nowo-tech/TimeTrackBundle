<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\Dto\TaskListQuery;
use Nowo\TimeTrackBundle\Dto\TaskReference;
use Nowo\TimeTrackBundle\Dto\TimeEntryPage;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\IdleResolution;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Event\TimeEntryListQueryEvent;
use Nowo\TimeTrackBundle\Event\TimerStartEvent;
use Nowo\TimeTrackBundle\Event\TimerStopEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Exception\ActiveTimerConflictException;
use Nowo\TimeTrackBundle\Integration\TaskProviderInterface;
use Nowo\TimeTrackBundle\Repository\ActiveTimerRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\PaginatedTimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_slice;
use function count;
use function max;
use function min;
use function sprintf;
use function usort;

/**
 * Timer use cases shared by the manage UI and the client API: one active timer per user, start / stop / heartbeat,
 * trackable tasks and the time entries of a period.
 *
 * Logging (REQ-OBS-001): time entries are personal data (GDPR), so log records carry the action and the client type
 * only — never the user, the task, its title, the board, the duration or the metadata.
 */
final readonly class TimerService
{
    private ClockInterface $clock;

    private LoggerInterface $logger;

    /**
     * @param TaskProviderInterface $taskProvider Trackable tasks of a user
     * @param ActiveTimerRepositoryInterface $activeTimerRepository Running timers (one per user)
     * @param TimeEntryRepositoryInterface $timeEntryRepository Closed time entries
     * @param EventDispatcherInterface $eventDispatcher Dispatches the timer and list-query events
     * @param ClockInterface|null $clock Time source (defaults to the system clock)
     * @param LoggerInterface|null $logger Operational log (defaults to no logging)
     */
    public function __construct(
        private TaskProviderInterface $taskProvider,
        private ActiveTimerRepositoryInterface $activeTimerRepository,
        private TimeEntryRepositoryInterface $timeEntryRepository,
        private EventDispatcherInterface $eventDispatcher,
        ?ClockInterface $clock = null,
        ?LoggerInterface $logger = null,
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
     * @throws InvalidArgumentException When the task is unknown or not trackable by the user
     *
     * @return ActiveTimer The new running timer
     */
    public function start(UserInterface $user, string $taskId, ClientType $clientType, array $metadata = []): ActiveTimer
    {
        $existing = $this->getActive($user);
        if ($existing instanceof ActiveTimer) {
            throw ActiveTimerConflictException::alreadyRunning($existing->getTaskId());
        }

        if (!$this->taskProvider->canUserTrack($user, $taskId)) {
            throw new InvalidArgumentException(sprintf('User cannot track task %s.', $taskId));
        }

        $task = $this->taskProvider->findForUser($taskId, $user);
        if (!$task instanceof TaskReference) {
            throw new InvalidArgumentException(sprintf('Task %s not found.', $taskId));
        }

        $timer = new ActiveTimer(
            $user,
            $task->id,
            $task->title,
            $task->boardId,
            $clientType,
            $metadata,
            $this->clock->now(),
        );

        try {
            $this->activeTimerRepository->save($timer);
        } catch (UniqueConstraintViolationException $e) {
            // Two concurrent starts both passed the getActive() check; the unique user_id index wins.
            $this->logger->warning('TimeTrack timer start rejected: concurrent start.', $this->context('timer_start', $clientType));

            throw ActiveTimerConflictException::concurrentStart($e);
        }

        $this->eventDispatcher->dispatch(new TimerStartEvent($user, $task, $clientType), TimeTrackEvents::TIMER_START);
        $this->logger->info('TimeTrack timer started.', $this->context('timer_start', $clientType));

        return $timer;
    }

    /**
     * Stops the running timer of the user and records it as a time entry.
     *
     * @param UserInterface $user Timer owner
     * @param DateTimeImmutable|null $endedAt End of the entry (default: now; used by the idle resolution)
     * @param string|null $note Note of the user stored in the entry metadata (`note`)
     *
     * @return TimeEntry|null The recorded entry, or null when no timer was running
     */
    public function stop(UserInterface $user, ?DateTimeImmutable $endedAt = null, ?string $note = null): ?TimeEntry
    {
        $timer = $this->getActive($user);
        if (!$timer instanceof ActiveTimer) {
            return null;
        }

        $endedAt ??= $this->clock->now();
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
            $note !== null ? ['note' => $note] + ($timer->getMetadata() ?? []) : $timer->getMetadata(),
            $endedAt,
        );

        $this->timeEntryRepository->save($entry);
        // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
        $this->activeTimerRepository->remove($timer);
        $this->eventDispatcher->dispatch(new TimerStopEvent($user, $entry), TimeTrackEvents::TIMER_STOP);
        $this->logger->info('TimeTrack timer stopped.', $this->context('timer_stop', $timer->getClientType()));

        return $entry;
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
     * @throws InvalidArgumentException When the task is unknown or not trackable by the user
     * @throws ActiveTimerConflictException When a concurrent start won the race
     *
     * @return array{entry: TimeEntry|null, timer: ActiveTimer} The entry of the stopped timer and the new timer
     */
    public function switchTo(UserInterface $user, string $taskId, ClientType $clientType, ?string $note = null): array
    {
        if (!$this->taskProvider->canUserTrack($user, $taskId) || !$this->taskProvider->findForUser($taskId, $user) instanceof TaskReference) {
            throw new InvalidArgumentException(sprintf('User cannot track task %s.', $taskId));
        }

        $entry = $this->stop($user, null, $note);

        return ['entry' => $entry, 'timer' => $this->start($user, $taskId, $clientType)];
    }

    /**
     * Applies the user's decision about an idle span of the running timer (`POST timer/discard-idle`): keep it,
     * discard it and stop at its start, or discard it and continue on the same task from its end (split).
     *
     * @param UserInterface $user Timer owner
     * @param DateTimeImmutable $from Idle start (not before the timer start)
     * @param DateTimeImmutable $to Idle end (after `from`, not in the future)
     * @param IdleResolution $resolution The user's decision
     *
     * @throws InvalidArgumentException When the span is not inside the running timer
     *
     * @return array{entry: TimeEntry|null, timer: ActiveTimer|null}|null Recorded entry and running timer, or null when no timer runs
     */
    public function resolveIdle(UserInterface $user, DateTimeImmutable $from, DateTimeImmutable $to, IdleResolution $resolution): ?array
    {
        $timer = $this->getActive($user);
        if (!$timer instanceof ActiveTimer) {
            return null;
        }

        if ($from < $timer->getStartedAt() || $to <= $from || $to > $this->clock->now()) {
            throw new InvalidArgumentException('The idle span must lie inside the running timer.');
        }

        if ($resolution === IdleResolution::Keep) {
            return ['entry' => null, 'timer' => $timer];
        }

        $entry = $this->stop($user, $from);
        if ($resolution === IdleResolution::Discard) {
            return ['entry' => $entry, 'timer' => null];
        }

        $next = new ActiveTimer($user, $timer->getTaskId(), $timer->getTaskTitleSnapshot(), $timer->getBoardIdSnapshot(), $timer->getClientType(), $timer->getMetadata(), $to);
        $this->activeTimerRepository->save($next);
        $this->eventDispatcher->dispatch(new TimerStartEvent($user, new TaskReference($timer->getTaskId(), $timer->getTaskTitleSnapshot(), $timer->getBoardIdSnapshot()), $timer->getClientType()), TimeTrackEvents::TIMER_START);
        $this->logger->info('TimeTrack idle span discarded, timer continued.', $this->context('timer_split', $timer->getClientType()));

        return ['entry' => $entry, 'timer' => $next];
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
        foreach ($this->resolveEntryUserIds($viewer, $from, $to, $targetUserId) as $userId) {
            foreach ($this->timeEntryRepository->findByUserAndPeriod($userId, $from, $to) as $entry) {
                $entries[] = $entry;
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

        if ($this->timeEntryRepository instanceof PaginatedTimeEntryRepositoryInterface) {
            $userIds = $this->resolveEntryUserIds($viewer, $from, $to, $targetUserId);
            $total   = $this->timeEntryRepository->countByUsersAndPeriod($userIds, $from, $to);
            $page    = $this->clampPage($page, $total, $perPage);

            return new TimeEntryPage(
                $total === 0 ? [] : $this->timeEntryRepository->findPageByUsersAndPeriod($userIds, $from, $to, ($page - 1) * $perPage, $perPage),
                $page,
                $perPage,
                $total,
            );
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
     * @return list<string>
     */
    private function resolveEntryUserIds(UserInterface $viewer, DateTimeImmutable $from, DateTimeImmutable $to, ?string $targetUserId): array
    {
        $event = new TimeEntryListQueryEvent($viewer, $from, $to);
        $this->eventDispatcher->dispatch($event, TimeTrackEvents::TIME_ENTRY_LIST_QUERY);

        $userIds = $event->getUserIds();

        return $userIds === [] ? [$targetUserId ?? UserIdResolver::getId($viewer)] : $userIds;
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
