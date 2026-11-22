<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Dto\ClampedTime;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 1.36.0 (Beacon spec 250, {@see TimeTrackEvents::TIMER_START_WITHOUT_TASK}): a timer without a task was started
 * (`entries.allow_without_task`). {@see TimeTrackEvents::TIMER_START} is not dispatched for it: its {@see TimerStartEvent}
 * carries a task. Ids and scalars only (REQ-ARCH-001 rule 4): never the user, the timer entity or the free text, so a
 * listener of a private entity manager host holds nothing of the bundle's unit of work. The stop of such a timer
 * dispatches {@see TimeTrackEvents::TIMER_STOP} like every stop ({@see TimerStopEvent::getTaskId()} is null).
 */
final class TimerStartedWithoutTaskEvent extends Event
{
    /**
     * @param string $userId Id of the timer owner (the acting user)
     * @param string $timerId Id of the running timer
     * @param string|null $scopeKey Tenant scope the timer's entry will belong to (null = none)
     * @param ClientType $clientType Client that started it
     * @param DateTimeImmutable $startedAt Applied start
     * @param ClampedTime|null $startTime The requested and the applied start (null when no start was requested)
     */
    public function __construct(
        public readonly string $userId,
        public readonly string $timerId,
        public readonly ?string $scopeKey,
        public readonly ClientType $clientType,
        public readonly DateTimeImmutable $startedAt,
        public readonly ?ClampedTime $startTime = null,
    ) {
    }

    /**
     * Always null: the timer has no task (the same accessor as on the stop and created events).
     */
    public function getTaskId(): null
    {
        return null;
    }
}
