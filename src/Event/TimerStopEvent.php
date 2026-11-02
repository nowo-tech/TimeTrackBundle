<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after a timer was stopped and recorded as a time entry.
 */
final class TimerStopEvent extends Event
{
    /**
     * Creates the event for the timer owner and the recorded entry.
     */
    public function __construct(
        private readonly UserInterface $user,
        private readonly TimeEntry $entry,
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
}
