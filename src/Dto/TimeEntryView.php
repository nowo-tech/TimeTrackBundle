<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Dto;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;

/**
 * Since 1.18: read-only snapshot of a time entry with scalar ids, for listeners that translate the bundle's events
 * into their own domain events without holding the Doctrine entity (or the host user behind it).
 */
final readonly class TimeEntryView
{
    /**
     * Creates the snapshot.
     */
    public function __construct(
        public string $id,
        public ?string $userId,
        public string $taskId,
        public string $taskTitle,
        public ?string $boardId,
        public ?string $scopeKey,
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $endedAt,
        public int $durationSeconds,
        public TimeEntrySource $source,
        public ?string $externalSource,
        public ?string $externalId,
        // Since 1.25.0: the channel (`api`, `mcp`, …; null outside HostTimerApi and host manual entries) and the agent mark.
        public ?string $channel = null,
        public bool $serviceAccount = false,
    ) {
    }

    /**
     * Snapshot of the entry's current values.
     */
    public static function fromEntry(TimeEntry $entry): self
    {
        return new self(
            $entry->getId(),
            $entry->getUserId(),
            $entry->getTaskId(),
            $entry->getTaskTitleSnapshot(),
            $entry->getBoardIdSnapshot(),
            $entry->getScopeKey(),
            $entry->getStartedAt(),
            $entry->getEndedAt(),
            $entry->getDurationSeconds(),
            $entry->getSource(),
            $entry->getExternalSource(),
            $entry->getExternalId(),
            $entry->channel(),
            $entry->isServiceAccount(),
        );
    }
}
