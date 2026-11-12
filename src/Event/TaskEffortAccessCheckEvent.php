<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

use Nowo\TimeTrackBundle\Port\TimeTrackUserRefs;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
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
     *
     * @deprecated since 1.23.0, use {@see self::userRef()} (framework-free, REQ-ARCH-001 rule 3); removed in 2.0.
     */
    public function getUser(): UserInterface
    {
        return $this->user;
    }

    /**
     * Since 1.23.0: framework-free reference of {@see self::getUser()} (REQ-ARCH-001 rule 3): class, id, identifier and
     * roles; the object itself through `userRef()->user()`. Built per call: compare with `isSameUserAs()`.
     */
    public function userRef(): TimeTrackUserRef
    {
        return TimeTrackUserRefs::fromUser($this->user);
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

    /** Since 1.18: id of {@see self::getUser()}, the user asking for access (the actor), as a string (`getId()`). */
    public function getUserId(): string
    {
        return UserIdResolver::getId($this->user);
    }
}
