<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

use Nowo\TimeTrackBundle\Dto\ClampedTime;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after a timer was stopped and recorded as a time entry. Since 1.10 it is also dispatched for the entry
 * an idle span is reassigned to (`timer/discard-idle` action `reassign`), so listeners that add up time per task
 * (TaskBoardBundle's aggregator) count it.
 */
final class TimerStopEvent extends Event
{
    /**
     * Creates the event for the timer owner and the recorded entry.
     *
     * @param ClampedTime|null $endTime The client's `endedAt` and the applied end (since 1.10; null without one)
     */
    public function __construct(
        private readonly UserInterface $user,
        private readonly TimeEntry $entry,
        private readonly ?ClampedTime $endTime = null,
    ) {
    }

    /**
     * Timer owner.
     */
    public function getUser(): UserInterface
    {
        return $this->user;
    }

    /**
     * Recorded time entry.
     */
    public function getEntry(): TimeEntry
    {
        return $this->entry;
    }

    /**
     * The client-supplied end and the applied one (since 1.10; null when the client sent no `endedAt`).
     */
    public function getEndTime(): ?ClampedTime
    {
        return $this->endTime;
    }
}
