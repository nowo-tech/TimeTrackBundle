<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

final class TimerStopEvent extends Event
{
    public function __construct(
        private readonly UserInterface $user,
        private readonly TimeEntry $entry,
    ) {
    }

    public function getUser(): UserInterface
    {
        return $this->user;
    }

    public function getEntry(): TimeEntry
    {
        return $this->entry;
    }
}
