<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Import;

/**
 * Since 1.16: what a move of imported entries between owners did. A move never deletes time.
 */
final readonly class ImportedTimeEntryMoveResult
{
    /**
     * @param int $moved entries now with the new owner
     * @param int $keptLocked entries that stayed: their period is locked or submitted, for the current owner or the new user
     * @param int $refusedScope entries that stayed: their scope is not exactly the target's, the rule of {@see ImportedTimeEntryClaim}
     * @param int $secondsMoved tracked seconds of the moved entries
     */
    public function __construct(
        public int $moved = 0,
        public int $keptLocked = 0,
        public int $refusedScope = 0,
        public int $secondsMoved = 0,
    ) {
    }

    /**
     * Both results added up (one move reads in batches).
     */
    public function merge(self $other): self
    {
        return new self($this->moved + $other->moved, $this->keptLocked + $other->keptLocked, $this->refusedScope + $other->refusedScope, $this->secondsMoved + $other->secondsMoved);
    }
}
