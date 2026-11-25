<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Timer;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;

use function is_string;
use function preg_match;

/**
 * Since 1.25.0: framework-free snapshot of a running timer, as {@see HostTimerApi} returns it. Built per call and never
 * kept by the bundle (worker mode).
 */
final readonly class TimerSnapshot
{
    /**
     * Creates the snapshot.
     */
    public function __construct(
        public string $timerId,
        public string $taskId,
        public string $taskTitle,
        public ?string $boardId,
        public DateTimeImmutable $startedAt,
        public ClientType $clientType,
        public ?string $channel,
        public ?string $note,
        // Since 1.36.0: a timer without a task (taskId and taskTitle are ''), its free text and its tenant scope.
        public bool $withoutTask = false,
        public ?string $description = null,
        public ?string $scopeKey = null,
        // Since 2.1.0 (Beacon spec 256): the subject and the activity type key of the timer.
        public ?TimeSubjectRef $subject = null,
        public ?string $activityTypeKey = null,
    ) {
    }

    /**
     * Since 1.36.0: the task id, null for a timer without a task.
     */
    public function taskIdOrNull(): ?string
    {
        return $this->withoutTask ? null : $this->taskId;
    }

    /**
     * Snapshot of a running timer.
     */
    public static function fromTimer(ActiveTimer $timer): self
    {
        $metadata = $timer->getMetadata() ?? [];
        $channel  = $metadata[TimerService::CHANNEL_KEY] ?? null;
        $note     = $metadata['note'] ?? null;

        return new self(
            $timer->getId(),
            $timer->getTaskId(),
            $timer->getTaskTitleSnapshot(),
            $timer->getBoardIdSnapshot(),
            $timer->getStartedAt(),
            $timer->getClientType(),
            is_string($channel) && preg_match(TimerChannel::PATTERN, $channel) === 1 ? $channel : null,
            is_string($note) ? $note : null,
            !$timer->hasTask(),
            $timer->getDescription(),
            $timer->getScopeKey(),
            $timer->subject(),
            $timer->getActivityTypeKey(),
        );
    }
}
