<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use Nowo\TimeTrackBundle\TaskMove\TaskTimeMover;

/**
 * Since 1.17: optional store capability for {@see TaskTimeMover}: the running timers of tasks that
 * moved to another board get the target board snapshot. A timer has no scope: the entry it becomes takes the scope of its board
 * when it stops. The Doctrine store implements it.
 */
interface TaskTimerMoveRepositoryInterface
{
    /**
     * Sets the board snapshot of the running timers of these tasks that do not have it yet, and nothing else; returns the timers
     * changed. Loaded timers are detached. With `$fromBoardId`, only timers on that board move.
     *
     * @param list<string> $taskIds
     */
    public function moveTimersToBoard(array $taskIds, ?string $boardId, ?string $fromBoardId = null): int;
}
