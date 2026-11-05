<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Import;

use function array_unique;
use function array_values;

/**
 * Since 1.11: what a removal deleted — the count, the seconds per task id (for hosts that keep task totals) and the
 * owners' ids.
 */
final readonly class ImportedTimeEntryRemoval
{
    /**
     * @param array<string, int> $secondsByTask task id => removed seconds
     * @param list<string> $userIds owners of the removed entries (each once)
     */
    public function __construct(
        public int $count = 0,
        public array $secondsByTask = [],
        public array $userIds = [],
    ) {
    }

    /**
     * Both removals added up (batches of one removal).
     */
    public function merge(self $other): self
    {
        $seconds = $this->secondsByTask;
        foreach ($other->secondsByTask as $taskId => $value) {
            $seconds[$taskId] = ($seconds[$taskId] ?? 0) + $value;
        }

        return new self($this->count + $other->count, $seconds, array_values(array_unique([...$this->userIds, ...$other->userIds])));
    }
}
