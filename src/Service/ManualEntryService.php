<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Service;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Dto\TaskReference;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Enum\WriteKind;
use Nowo\TimeTrackBundle\Event\TimeEntryCreatedEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Exception\ManualEntryException;
use Nowo\TimeTrackBundle\Exception\PeriodWriteRefusedException;
use Nowo\TimeTrackBundle\Integration\PassThroughUserReferenceResolver;
use Nowo\TimeTrackBundle\Integration\TimeEntryBillableDefaultProviderInterface;
use Nowo\TimeTrackBundle\Integration\UserReferenceResolverInterface;
use Nowo\TimeTrackBundle\Repository\ActiveTimerRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\OverlappingTimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\UserSerialisedTimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Security\TaskAccessCheckerInterface;
use Nowo\TimeTrackBundle\Support\NoteSanitizer;
use Nowo\TimeTrackBundle\Support\StorageTime;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScopeProviderInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function in_array;

/**
 * Since 1.14 (contract §6.5, §10.3, Q-O7): entries without a timer — a past time range the user enters by hand
 * (`manual`) or an accepted calendar event (`calendar`). Both are live and editable. Refused when: the end is not after
 * the start, longer than 24 h, in the future (beyond a 2-minute clock skew), older than the allowed age, the user may not
 * track the task, it overlaps one of the user's live entries or the running timer, or its period is locked or submitted
 * ({@see PeriodLockGuard}, 409). Dispatches {@see TimeEntryCreatedEvent}. Logs carry the action and source only.
 */
final readonly class ManualEntryService
{
    private UserReferenceResolverInterface $userReferences;

    /** Longest entry, in seconds. */
    public const MAX_SECONDS = 86_400;

    /** Accepted clock skew of a future end, in seconds. */
    public const CLOCK_SKEW_SECONDS = 120;

    private ClockInterface $clock;

    private LoggerInterface $logger;

    /**
     * @param bool $enabled `manual_entries.enabled` (`POST entries`; accepted suggestions do not depend on it)
     * @param int $maxAgeDays `manual_entries.max_age_days`
     */
    public function __construct(
        private TimerService $timerService,
        private TimeEntryRepositoryInterface $entries,
        private ActiveTimerRepositoryInterface $timers,
        private TaskAccessCheckerInterface $taskAccess,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private bool $enabled = false,
        private int $maxAgeDays = 31,
        private ?PeriodLockGuard $periodLockGuard = null,
        private ?TimesheetScopeProviderInterface $scopeProvider = null,
        ?ClockInterface $clock = null,
        ?LoggerInterface $logger = null,
        ?UserReferenceResolverInterface $userReferences = null,
        // Since 1.21: the default `billable` of an entry created without one (null = none, the flag stays unset).
        private ?TimeEntryBillableDefaultProviderInterface $billableDefaults = null,
        // Since 1.21 (audit MV-T-L3): asks `edit_billable` for an explicit `billable` (null = the caller's value is trusted).
        private ?TeamAccessGuard $accessGuard = null,
    ) {
        $this->clock          = $clock ?? new NativeClock();
        $this->logger         = $logger ?? new NullLogger();
        $this->userReferences = $userReferences ?? new PassThroughUserReferenceResolver();
    }

    /**
     * Whether `POST entries` is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Creates a past entry on a task the user may track.
     *
     * The lock check, the overlap check and the insert run in one transaction holding the user's write lock (audit
     * GG-L1), together with the caller's `$prepare` (before the checks) and `$finish` (after the insert, e.g. the
     * suggestion status) hooks: all of it is committed, or nothing. {@see TimeEntryCreatedEvent} is dispatched after the
     * commit; a listener that throws is logged (exception class) and does not undo the entry.
     *
     * @param TimeEntrySource $source `Manual` or `Calendar`
     * @param array<string, scalar> $metadata Extra metadata (e.g. `origin`, `provider` of an accepted suggestion; never event data)
     * @param int|null $maxAgeDays Oldest allowed start in days (null = `manual_entries.max_age_days`)
     * @param (callable(): void)|null $prepare Runs first inside the transaction (may throw to refuse)
     * @param (callable(TimeEntry): void)|null $finish Runs after the insert inside the transaction
     * @param bool|null $billable Since 1.21: the entry's `billable` flag; null = the host default
     *                            ({@see TimeEntryBillableDefaultProviderInterface}), unset when it has none. An explicit
     *                            value needs {@see TeamAccessGuard::isBillableEditGranted()} (`billable_forbidden`)
     *
     * @throws ManualEntryException
     * @throws PeriodWriteRefusedException When the period is locked or submitted
     */
    public function create(UserInterface $user, string $taskId, DateTimeImmutable $start, DateTimeImmutable $end, TimeEntrySource $source, ?string $note = null, array $metadata = [], ?int $maxAgeDays = null, ?callable $prepare = null, ?callable $finish = null, ?bool $billable = null): TimeEntry
    {
        if (!in_array($source, [TimeEntrySource::Manual, TimeEntrySource::Calendar], true)) {
            throw new ManualEntryException(ManualEntryException::INVALID_RANGE);
        }
        $start   = StorageTime::normalize($start);
        $end     = StorageTime::normalize($end);
        $now     = $this->clock->now()->getTimestamp();
        $seconds = $end->getTimestamp() - $start->getTimestamp();
        if ($seconds <= 0) {
            throw new ManualEntryException(ManualEntryException::INVALID_RANGE);
        }
        if ($seconds > self::MAX_SECONDS) {
            throw new ManualEntryException(ManualEntryException::TOO_LONG);
        }
        if ($end->getTimestamp() > $now + self::CLOCK_SKEW_SECONDS) {
            throw new ManualEntryException(ManualEntryException::IN_FUTURE);
        }
        if ($start->getTimestamp() < $now - ($maxAgeDays ?? $this->maxAgeDays) * 86_400) {
            throw new ManualEntryException(ManualEntryException::TOO_OLD);
        }
        $task = $this->taskAccess->canTrack($user, $taskId) ? $this->timerService->findTask($user, $taskId) : null;
        if (!$task instanceof TaskReference) {
            throw new ManualEntryException(ManualEntryException::NOT_TRACKABLE);
        }
        $note = $note !== null ? NoteSanitizer::cleanAndCut($note) : null;
        if ($note !== null) {
            $metadata['note'] = $note;
        }

        // @igor-ignore - Request-scoped closure passed to a per-request call; nothing is stored on the service.
        $entry = $this->serialised($user, function () use ($user, $task, $start, $end, $seconds, $source, $metadata, $prepare, $finish, $billable): TimeEntry {
            if ($prepare !== null) {
                $prepare();
            }
            $scopeKey = $this->scopeProvider?->scopeKeyForTask($user, $task->id, $task->boardId);
            $this->periodLockGuard?->assertWritable($user, $scopeKey, $start, WriteKind::EntryCreate);
            if ($this->overlaps($user, $start, $end)) {
                throw new ManualEntryException(ManualEntryException::OVERLAP);
            }
            $entry = new TimeEntry($this->userReferences->reference($user), $task->id, $task->title, $task->boardId, $start, $end, $seconds, $source, $metadata === [] ? null : $metadata, StorageTime::normalize($this->clock->now()));
            $entry->assignScopeKey($scopeKey);
            if ($billable !== null && $this->accessGuard instanceof TeamAccessGuard && !$this->accessGuard->isBillableEditGranted($user, $entry)) {
                throw new ManualEntryException(ManualEntryException::BILLABLE_FORBIDDEN);
            }
            $entry->markBillable($billable ?? $this->billableDefaults?->defaultFor($scopeKey ?? '', $task->id));
            $this->entries->save($entry);
            if ($finish !== null) {
                $finish($entry);
            }

            return $entry;
        });

        try {
            $this->eventDispatcher?->dispatch(new TimeEntryCreatedEvent($entry, $source->value), TimeTrackEvents::TIME_ENTRY_CREATED);
        } catch (Throwable $exception) {
            $this->logger->warning('TimeTrack entry-created listener failed.', ['bundle' => 'time_track', 'action' => 'entry_create', 'exception' => $exception::class]);
        }
        $this->logger->info('TimeTrack entry created without a timer.', ['bundle' => 'time_track', 'action' => 'entry_create', 'source' => $source->value]);

        return $entry;
    }

    /**
     * Since the GG fixes: runs a write of the user's time in one transaction holding the user's write lock (stores
     * with {@see UserSerialisedTimeEntryRepositoryInterface}; others run it as is).
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function serialised(UserInterface $user, callable $operation): mixed
    {
        return $this->entries instanceof UserSerialisedTimeEntryRepositoryInterface ? $this->entries->inUserTransaction($user, $operation) : $operation();
    }

    private function overlaps(UserInterface $user, DateTimeImmutable $start, DateTimeImmutable $end): bool
    {
        $userId = UserIdResolver::getId($user);
        $timer  = $this->timers->findByUserId($userId);
        if ($timer instanceof ActiveTimer && $timer->getStartedAt() < $end) {
            return true;
        }
        $candidates = $this->entries instanceof OverlappingTimeEntryRepositoryInterface
            ? $this->entries->findLiveOverlapping($userId, $start, $end)
            : $this->entries->findByUserAndPeriod($userId, $start->modify('-2 days'), $end);
        foreach ($candidates as $entry) {
            if ($entry->getSource()->isLive() && $entry->getStartedAt() < $end && $entry->getEndedAt() > $start) {
                return true;
            }
        }

        return false;
    }
}
