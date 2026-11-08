<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\TaskMove;

use function array_keys;
use function array_map;
use function count;

/**
 * Since 1.17: what {@see TaskTimeMover::moveTasks()} did. A move never deletes or creates time: every entry of the tasks is
 * either moved, already at the target, or kept (with its reason).
 */
final readonly class TaskTimeMoveResult
{
    /**
     * @param int $moved entries whose board snapshot and scope were set to the target
     * @param int $alreadyAtTarget entries that already had the target board snapshot and scope (a retry, or never moved)
     * @param int $secondsMoved tracked seconds of the moved entries
     * @param array<array-key, TaskTimeKeptReason> $kept entries left as they were, by id, with the reason (PHP makes a numeric id an int key: use {@see self::keptEntryIds()})
     * @param int $secondsKept tracked seconds of the kept entries
     * @param int $timersMoved running timers whose board snapshot was set to the target
     */
    public function __construct(
        public int $moved = 0,
        public int $alreadyAtTarget = 0,
        public int $secondsMoved = 0,
        public array $kept = [],
        public int $secondsKept = 0,
        public int $timersMoved = 0,
    ) {
    }

    /**
     * Entries kept where they were.
     */
    public function keptCount(): int
    {
        return count($this->kept);
    }

    /**
     * Ids of the entries kept for this reason.
     *
     * @return list<string>
     */
    public function keptIds(TaskTimeKeptReason $reason): array
    {
        $ids = [];
        foreach ($this->kept as $id => $keptFor) {
            if ($keptFor === $reason) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    /**
     * Ids of every kept entry.
     *
     * @return list<string>
     */
    public function keptEntryIds(): array
    {
        return array_map(strval(...), array_keys($this->kept));
    }

    /**
     * Whether every entry of the tasks now has the target board and scope (nothing kept).
     */
    public function isComplete(): bool
    {
        return $this->kept === [];
    }

    /**
     * Whether the call changed or kept anything (false: nothing to do, as on a repeated call that already completed).
     */
    public function hasChanges(): bool
    {
        return $this->moved > 0 || $this->timersMoved > 0 || $this->kept !== [];
    }

    /**
     * Both results added up (a move reads in batches).
     */
    public function merge(self $other): self
    {
        return new self(
            $this->moved + $other->moved,
            $this->alreadyAtTarget + $other->alreadyAtTarget,
            $this->secondsMoved + $other->secondsMoved,
            $this->kept + $other->kept,
            $this->secondsKept + $other->secondsKept,
            $this->timersMoved + $other->timersMoved,
        );
    }
}
