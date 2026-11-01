<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Lets listeners grant or deny an action on a time entry (fine-grained ACL).
 */
final class TimeEntryAccessCheckEvent extends Event
{
    /**
     * Creates the check for a user, an entry and an action (`view`, `edit`, ...).
     */
    public function __construct(
        private readonly UserInterface $user,
        private readonly TimeEntry $entry,
        private readonly string $action,
        private bool $granted,
    ) {
    }

    /**
     * User asking for the action.
     */
    public function getUser(): UserInterface
    {
        return $this->user;
    }

    /**
     * Time entry concerned.
     */
    public function getEntry(): TimeEntry
    {
        return $this->entry;
    }

    /**
     * Requested action.
     */
    public function getAction(): string
    {
        return $this->action;
    }

    /**
     * Current decision.
     */
    public function isGranted(): bool
    {
        return $this->granted;
    }

    /**
     * Grants the action.
     */
    public function grant(): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->granted = true;
    }

    /**
     * Denies the action.
     */
    public function deny(): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->granted = false;
    }
}
