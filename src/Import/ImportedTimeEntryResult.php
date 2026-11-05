<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Import;

use Nowo\TimeTrackBundle\Entity\TimeEntry;

/**
 * Since 1.11: the entry an upsert wrote (or left unchanged) and what it did.
 */
final readonly class ImportedTimeEntryResult
{
    /**
     * Creates the object.
     */
    public function __construct(
        public TimeEntry $entry,
        public ImportedTimeEntryOutcome $outcome,
    ) {
    }
}
