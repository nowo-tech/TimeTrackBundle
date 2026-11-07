<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Import;

/**
 * Since 1.11: what an upsert did.
 */
enum ImportedTimeEntryOutcome: string
{
    case Created   = 'created';
    case Updated   = 'updated';
    case Unchanged = 'unchanged';
    /**
     * Since 1.13: skipped, nothing written: the entry (its current or its new start) is in a locked or submitted
     * timesheet period (contract Q-O13). The result's entry is the stored one, or for a new identity an unsaved entry
     * built from the data (never persisted).
     */
    case Locked = 'locked';
    /**
     * Since 1.16, only with `upsertMany($rows, skipMismatches: true)`: skipped, nothing written: the row names another owner
     * than the stored entry's. The result's entry is the stored one.
     */
    case Mismatch = 'mismatch';
}
