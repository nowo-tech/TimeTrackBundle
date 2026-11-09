<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 1.13 (owner decision 2026-11-05, audit GB-M3): whether a user may see the **all-users** total of logged time on
 * a task (`loggedSeconds` of the client API). Denied by default: everybody sees only their own total; the host grants
 * the all-users total to the users who can **manage** the task (e.g. project managers), event
 * {@see TimeTrackEvents::TASK_EFFORT_ACCESS_CHECK}.
 */
final class TaskEffortAccessCheckEvent extends Event
{
    /**
     * Creates the check for a user and a task (not granted).
     */
    public function __construct(
        private readonly UserInterface $user,
        private readonly string $taskId,
        private bool $granted = false,
    ) {
    }

    /**
     * User asking.
     */
    public function getUser(): UserInterface
    {
        return $this->user;
    }

    /**
     * Task id.
     */
    public function getTaskId(): string
    {
        return $this->taskId;
    }

    /**
     * Current decision.
     */
    public function isGranted(): bool
    {
        return $this->granted;
    }

    /**
     * Grants the all-users total (the user can manage the task).
     */
    public function grant(): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->granted = true;
    }

    /**
     * Denies it (own total only).
     */
    public function deny(): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->granted = false;
    }

    /** Since 1.18: id of {@see self::getUser()} as a string (`getId()`), for listeners that keep only ids. */
    public function getUserId(): string
    {
        return UserIdResolver::getId($this->user);
    }
}
