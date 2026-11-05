<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Import;

use DateTimeImmutable;

/**
 * Since 1.11: one entry to import, as the host maps it from the source tool. The writer validates it
 * ({@see ImportedTimeEntryWriter::validate()}); the description is kept in full (no length cap).
 */
final readonly class ImportedTimeEntryData
{
    /**
     * @param string $externalSource the importing system and its scope, e.g. "clickup:<directory id>"
     * @param string $externalId the entry's id in that system
     * @param string $taskId the local task id (TaskBoard task UUID)
     * @param string $taskTitle task title snapshot (cut to 255 characters by the writer)
     * @param string|null $boardId the local board id snapshot
     * @param int $durationSeconds tracked seconds (at most the span between start and end, plus one minute)
     * @param bool|null $billable null = unknown
     * @param string|null $description plain text, kept in full
     */
    public function __construct(
        public string $externalSource,
        public string $externalId,
        public string $taskId,
        public string $taskTitle,
        public ?string $boardId,
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $endedAt,
        public int $durationSeconds,
        public ?bool $billable = null,
        public ?string $description = null,
    ) {
    }
}
