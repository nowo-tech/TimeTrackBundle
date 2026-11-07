<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

use Nowo\TimeTrackBundle\Import\ImportedTimeEntryMoveResult;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 1.16 ({@see TimeTrackEvents::TIME_ENTRIES_MOVED}): imported entries moved between a user and a person, or the period
 * lock or the scope rule kept them. Fired after the move's own commit, so before the commit of a transaction of the host's.
 * Carries ids and counts only; the bundle has no audit table, so hosts store this event. Task totals do not change.
 */
final class TimeEntriesMovedEvent extends Event
{
    /**
     * Creates the object.
     */
    public function __construct(
        private readonly string $externalSource,
        private readonly ?string $fromUserId,
        private readonly ?string $fromPersonRef,
        private readonly ?string $toUserId,
        private readonly ?string $toPersonRef,
        private readonly ImportedTimeEntryMoveResult $result,
        private readonly ?string $actorId = null,
    ) {
    }

    /**
     * The exact source moved.
     */
    public function getExternalSource(): string
    {
        return $this->externalSource;
    }

    /**
     * The user the entries left (null when they left a person).
     */
    public function getFromUserId(): ?string
    {
        return $this->fromUserId;
    }

    /**
     * The person reference the entries left (null when they left a user).
     */
    public function getFromPersonRef(): ?string
    {
        return $this->fromPersonRef;
    }

    /**
     * The user the entries went to (null when they went to a person).
     */
    public function getToUserId(): ?string
    {
        return $this->toUserId;
    }

    /**
     * The person reference the entries went to (null when they went to a user).
     */
    public function getToPersonRef(): ?string
    {
        return $this->toPersonRef;
    }

    /**
     * Entries moved.
     */
    public function getMovedCount(): int
    {
        return $this->result->moved;
    }

    /**
     * Entries the period lock kept.
     */
    public function getKeptCount(): int
    {
        return $this->result->keptLocked;
    }

    /**
     * Entries the scope rule kept.
     */
    public function getRefusedCount(): int
    {
        return $this->result->refusedScope;
    }

    /**
     * Tracked seconds of the moved entries.
     */
    public function getSecondsMoved(): int
    {
        return $this->result->secondsMoved;
    }

    /**
     * The user who moved them, when the host says.
     */
    public function getActorId(): ?string
    {
        return $this->actorId;
    }
}
