<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Timer;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;

/**
 * Since 1.25.0: framework-free snapshot of the entry a stopped timer recorded, as {@see HostTimerApi} returns it (start,
 * end, duration, the channel and the service-account mark). Built per call and never kept by the bundle (worker mode).
 */
final readonly class TimerEntrySnapshot
{
    /**
     * Creates the snapshot.
     */
    public function __construct(
        public string $entryId,
        public string $taskId,
        public string $taskTitle,
        public ?string $boardId,
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $endedAt,
        public int $durationSeconds,
        public TimeEntrySource $source,
        public ?string $channel,
        public bool $serviceAccount,
    ) {
    }

    /**
     * Snapshot of a recorded entry.
     */
    public static function fromEntry(TimeEntry $entry): self
    {
        return new self(
            $entry->getId(),
            $entry->getTaskId(),
            $entry->getTaskTitleSnapshot(),
            $entry->getBoardIdSnapshot(),
            $entry->getStartedAt(),
            $entry->getEndedAt(),
            $entry->getDurationSeconds(),
            $entry->getSource(),
            $entry->channel(),
            $entry->isServiceAccount(),
        );
    }
}
