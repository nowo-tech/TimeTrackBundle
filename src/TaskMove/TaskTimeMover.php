<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\TaskMove;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Nowo\TimeTrackBundle\Event\TaskTimeMovedEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Repository\ActiveTimerRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TaskTimeMoveRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TaskTimerMoveRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_chunk;
use function array_keys;
use function array_map;
use function count;
use function in_array;
use function is_string;
use function strlen;

/**
 * Since 1.17 (Beacon spec 217 FR-008): the time of tasks moved to another board follows them, also into another tenant. The
 * entries of the tasks get the target `board_id_snapshot` and `scope_key`, so reports and billing count them where the task now
 * is; running timers get the target board (the entry takes the board's scope when the timer stops). The host calls it after the
 * move is committed, for example from a listener on TaskBoard's `TASK_BOARD_CHANGED`, and authorises it: there is no route.
 *
 * Only those two columns change. Duration, times, user, person, task, source, read-only state and every other field stay, and
 * no entry is created or deleted (owner rule: tracked time is never deleted). Each batch of {@see self::BATCH} entries is one
 * transaction (read, lock, check, bulk update, loaded entries detached), so a call that fails half way leaves the rest with the
 * old snapshot, and calling again with the same arguments finishes it: an entry already at the target is counted, not written.
 *
 * With timesheets on, an entry whose period is locked, approved or submitted, in its current scope or in the target scope, is
 * kept where it is and reported ({@see TaskTimeKeptReason}): its timesheet's entries hash and totals were taken over the
 * entries of its scope, and moving it would change what that period bills. An audited reopen of the period and a new call
 * moves it. Anonymised entries are never written again and are kept too. The balance ledger is not touched.
 */
final readonly class TaskTimeMover
{
    /** Rows one batch (one transaction) handles. */
    public const BATCH = 500;

    /** Task ids per read (the length of the `IN` list). */
    public const TASK_CHUNK = 200;

    /** Longest task and board id (the columns). */
    public const MAX_ID_LENGTH = 36;

    /** Longest scope key (the column). */
    public const MAX_SCOPE_LENGTH = 64;

    /**
     * Receives the stores, and the period lock guard (timesheets).
     */
    public function __construct(
        private TimeEntryRepositoryInterface $entries,
        private ActiveTimerRepositoryInterface $timers,
        private ?PeriodLockGuard $periodLockGuard = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Moves the time of these tasks to the target board and scope (tenant). Safe to call again with the same arguments.
     *
     * @param list<string> $taskIds the moved tasks (with their moved subtasks), as TaskBoard's `TaskBoardChangedEvent::$taskIds`
     * @param string|null $targetBoardId the board the tasks are on now
     * @param string|null $targetScopeKey the scope of that board's tenant: what the scope provider answers for those tasks now
     *                                    (null only on hosts without tenant scopes)
     * @param string|null $actorId the user who moved the tasks, for the event
     *
     * @throws InvalidArgumentException when an argument breaks a rule (nothing written)
     * @throws LogicException when a store lacks the capability (nothing written)
     */
    public function moveTasks(array $taskIds, ?string $targetBoardId, ?string $targetScopeKey, ?string $actorId = null): TaskTimeMoveResult
    {
        $taskIds = $this->validate($taskIds, $targetBoardId, $targetScopeKey);
        if (!$this->entries instanceof TaskTimeMoveRepositoryInterface || !$this->timers instanceof TaskTimerMoveRepositoryInterface) {
            throw new LogicException('Moving the time of tasks requires stores implementing TaskTimeMoveRepositoryInterface and TaskTimerMoveRepositoryInterface.');
        }
        $entries = $this->entries;
        $timers  = $this->timers;
        $total   = new TaskTimeMoveResult();
        if ($taskIds === []) {
            return $total;
        }

        foreach (array_chunk($taskIds, self::TASK_CHUNK) as $chunk) {
            // Timers first: a timer that stops after this writes its entry with the new board.
            $total = $total->merge(new TaskTimeMoveResult(timersMoved: $timers->moveTimersToBoard($chunk, $targetBoardId)));
            $after = '';
            do {
                // @igor-ignore - I/O delegate (repository call); no worker state.
                [$batch, $after, $read] = $entries->inTaskMoveTransaction(fn (): array => $this->batch($entries, $chunk, $after, $targetBoardId, $targetScopeKey));
                $total                  = $total->merge($batch);
            } while ($read);
        }

        if ($total->hasChanges()) {
            $this->eventDispatcher?->dispatch(new TaskTimeMovedEvent($taskIds, $targetBoardId, $targetScopeKey, $total, $actorId), TimeTrackEvents::TASK_TIME_MOVED);
            $this->logger->info('TimeTrack task time moved.', ['bundle' => 'nowo_time_track', 'tasks' => count($taskIds), 'moved' => $total->moved, 'already' => $total->alreadyAtTarget, 'kept' => $total->keptCount(), 'timers' => $total->timersMoved, 'has_actor' => $actorId !== null]);
        }

        return $total;
    }

    /**
     * One batch, inside its transaction.
     *
     * @param list<string> $chunk
     *
     * @return array{0: TaskTimeMoveResult, 1: string, 2: bool} the batch's result, the cursor, whether rows were read
     */
    private function batch(TaskTimeMoveRepositoryInterface $entries, array $chunk, string $after, ?string $boardId, ?string $scopeKey): array
    {
        $rows    = $entries->findForTaskMove($chunk, $after, self::BATCH);
        $move    = [];
        $seconds = 0;
        $already = 0;
        $kept    = [];
        $keptSec = 0;
        foreach ($rows as $row) {
            $after = $row['id'];
            // A MySQL collation may fold case: the task is compared here too.
            if (!in_array($row['taskId'], $chunk, true)) {
                // @codeCoverageIgnoreStart
                continue;
                // @codeCoverageIgnoreEnd
            }
            if ($row['boardId'] === $boardId && $row['scopeKey'] === $scopeKey) {
                ++$already;
                continue;
            }
            $reason = $this->keptReason($row, $scopeKey);
            if ($reason instanceof TaskTimeKeptReason) {
                $kept[$row['id']] = $reason;
                $keptSec += (int) $row['seconds'];
                continue;
            }
            $move[] = $row['id'];
            $seconds += (int) $row['seconds'];
        }
        $moved = $move === [] ? 0 : $entries->applyTaskMove($move, $boardId, $scopeKey);

        return [new TaskTimeMoveResult($moved, $already, $seconds, $kept, $keptSec), $after, $rows !== []];
    }

    /**
     * Why the entry must stay where it is, or null when it may move.
     *
     * @param array{id: string, taskId: string, boardId: string|null, scopeKey: string|null, seconds: int|string, startedAt: DateTimeImmutable, personRef: string|null, userId: int|string|null, anonymisedAt: DateTimeImmutable|null} $row
     */
    private function keptReason(array $row, ?string $targetScope): ?TaskTimeKeptReason
    {
        if ($row['anonymisedAt'] !== null) {
            return TaskTimeKeptReason::Anonymised;
        }
        $guard = $this->periodLockGuard;
        if (!$guard instanceof PeriodLockGuard || !$guard->isEnabled()) {
            return null;
        }
        $userId = $row['userId'] === null ? null : (string) $row['userId'];
        if ($userId === null && $row['personRef'] === null) {
            return TaskTimeKeptReason::OwnerUnknown;
        }
        if ($guard->isMoveBlocked($userId, $row['personRef'], $row['scopeKey'], $row['startedAt'])) {
            return TaskTimeKeptReason::SourcePeriodLocked;
        }
        if ($targetScope !== $row['scopeKey'] && $guard->isMoveBlocked($userId, $row['personRef'], $targetScope, $row['startedAt'])) {
            return TaskTimeKeptReason::TargetPeriodLocked;
        }

        return null;
    }

    /**
     * @param array<mixed> $taskIds
     *
     * @return list<string> the task ids, each once
     */
    private function validate(array $taskIds, ?string $boardId, ?string $scopeKey): array
    {
        $unique = [];
        foreach ($taskIds as $taskId) {
            if (!is_string($taskId) || $taskId === '' || strlen($taskId) > self::MAX_ID_LENGTH) {
                throw new InvalidArgumentException('Each task id must be a non-empty string of at most 36 bytes.');
            }
            $unique[$taskId] = true;
        }
        if ($boardId !== null && ($boardId === '' || strlen($boardId) > self::MAX_ID_LENGTH)) {
            throw new InvalidArgumentException('The target board id must be null or a non-empty string of at most 36 bytes.');
        }
        if ($scopeKey !== null && strlen($scopeKey) > self::MAX_SCOPE_LENGTH) {
            throw new InvalidArgumentException('The target scope key must be null or a string of at most 64 bytes.');
        }

        return array_map(strval(...), array_keys($unique));
    }
}
