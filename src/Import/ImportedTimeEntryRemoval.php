<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Import;

use function array_unique;
use function array_values;

/**
 * Since 1.11: what a removal deleted — the count, the seconds per task id (for hosts that keep task totals), the
 * owners' ids and, since 1.15, the person references of the ownerless entries.
 */
final readonly class ImportedTimeEntryRemoval
{
    /**
     * @param array<string, int> $secondsByTask task id => removed seconds
     * @param list<string> $userIds owners of the removed entries (each once)
     * @param list<string> $personRefs Since 1.15: person references of the removed ownerless entries (each once)
     */
    public function __construct(
        public int $count = 0,
        public array $secondsByTask = [],
        public array $userIds = [],
        public array $personRefs = [],
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

        return new self($this->count + $other->count, $seconds, array_values(array_unique([...$this->userIds, ...$other->userIds])), array_values(array_unique([...$this->personRefs, ...$other->personRefs])));
    }
}
