<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Timer;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;

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
        // Why the bundle closed the timer (`replaced`, `user_deletion`) or null ({@see TimeEntry::closedBy()}).
        public ?string $closedBy = null,
        // Since 1.36.0: an entry without a task (taskId and taskTitle are ''), its free text and its tenant scope.
        public bool $withoutTask = false,
        public ?string $description = null,
        public ?string $scopeKey = null,
        // Since 2.1.0 (Beacon spec 256): the subject and the activity type key of the entry.
        public ?TimeSubjectRef $subject = null,
        public ?string $activityTypeKey = null,
    ) {
    }

    /**
     * Since 1.36.0: the task id, null for an entry without a task.
     */
    public function taskIdOrNull(): ?string
    {
        return $this->withoutTask ? null : $this->taskId;
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
            $entry->closedBy(),
            !$entry->hasTask(),
            $entry->getDescription(),
            $entry->getScopeKey(),
            $entry->subject(),
            $entry->getActivityTypeKey(),
        );
    }
}
