<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

final class TimeEntryAccessCheckEvent extends Event
{
    public function __construct(
        private readonly UserInterface $user,
        private readonly TimeEntry $entry,
        private readonly string $action,
        private bool $granted,
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

    public function getAction(): string
    {
        return $this->action;
    }

    public function isGranted(): bool
    {
        return $this->granted;
    }

    public function grant(): void
    {
        $this->granted = true;
    }

    public function deny(): void
    {
        $this->granted = false;
    }
}
