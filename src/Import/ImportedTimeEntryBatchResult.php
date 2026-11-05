<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Import;

/**
 * Since 1.11: counts of one {@see ImportedTimeEntryWriter::upsertMany()} call.
 */
final readonly class ImportedTimeEntryBatchResult
{
    /**
     * @param list<ImportedTimeEntryResult> $results in input order
     * @param int $locked Since 1.13: rows skipped by a period lock ({@see ImportedTimeEntryOutcome::Locked})
     */
    public function __construct(
        public array $results,
        public int $created,
        public int $updated,
        public int $unchanged,
        public int $locked = 0,
    ) {
    }
}
