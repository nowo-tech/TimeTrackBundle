<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

use Nowo\TimeTrackBundle\Service\TeamAccessGuard;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 1.37.0 (DDD-host checklist B8, REQ-ARCH-001 rule 3): the ids-only twin of {@see TimeEntryAccessCheckEvent}.
 * It carries scalars only (the actor's id, the entry's id, owner, task and tenant scope), so a listener of a host with a
 * private entity manager decides without holding a bundle entity or a user object.
 *
 * {@see TeamAccessGuard} dispatches it as `nowo_time_track.time_entry.id_access_check`
 * right after {@see TimeEntryAccessCheckEvent}, starting from that event's decision. Whatever a listener says, an entry
 * without a task outside the actor's tenant scopes stays refused (checked again after the listeners), and an edit of an
 * entry in a locked or submitted period stays refused (the period lock is checked last).
 */
final class TimeEntryIdAccessCheckEvent extends Event
{
    /**
     * @param string $userId id of the actor asking (not the owner: see {@see self::getOwnerId()})
     * @param string $entryId id of the entry
     * @param string|null $ownerId id of the entry's owner (null: ownerless or anonymised)
     * @param string|null $taskId task of the entry (null: an entry without a task)
     * @param string|null $scopeKey tenant scope of the entry (null: none)
     * @param string $action `view`, `edit`, `edit_billable` or `delete_imported`
     * @param bool $granted the decision so far
     */
    public function __construct(
        private readonly string $userId,
        private readonly string $entryId,
        private readonly ?string $ownerId,
        private readonly ?string $taskId,
        private readonly ?string $scopeKey,
        private readonly string $action,
        private bool $granted,
    ) {
    }

    /**
     * Id of the actor asking for the action.
     */
    public function getUserId(): string
    {
        return $this->userId;
    }

    /**
     * Id of the entry.
     */
    public function getEntryId(): string
    {
        return $this->entryId;
    }

    /**
     * Id of the entry's owner, null for an ownerless or anonymised entry.
     */
    public function getOwnerId(): ?string
    {
        return $this->ownerId;
    }

    /**
     * Task of the entry, null for an entry without a task.
     */
    public function getTaskId(): ?string
    {
        return $this->taskId;
    }

    /**
     * Tenant scope of the entry, null when it has none.
     */
    public function getScopeKey(): ?string
    {
        return $this->scopeKey;
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
     * Grants the action (never across tenants for an entry without a task, never past a period lock).
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
