<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Entry;

use Nowo\TimeTrackBundle\Query\TimeEntryRecord;

/**
 * Since 1.40.0: the outcome of {@see TimeEntryOperationsInterface::correct()}: the entry before and after the change
 * (read under the owner's lock, so `$before` is exactly what the correction replaced) and the names of the columns that
 * changed (`started_at`, `ended_at`, `duration_seconds`, `metadata`). Write your own revision record from it. Immutable.
 */
final readonly class TimeEntryCorrectionResult
{
    /** Whether the correction changed anything (false: nothing was written or dispatched). */
    public bool $changed;

    /**
     * @param TimeEntryRecord $before The entry as it was
     * @param TimeEntryRecord $after The entry as it is now (the same as `$before` when nothing changed)
     * @param list<string> $changedFields The changed columns, in this order: `started_at`, `ended_at`, `duration_seconds`, `metadata`
     */
    public function __construct(
        public TimeEntryRecord $before,
        public TimeEntryRecord $after,
        public array $changedFields,
    ) {
        $this->changed = $changedFields !== [];
    }
}
