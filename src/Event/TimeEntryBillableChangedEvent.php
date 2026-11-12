<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

use DateTimeImmutable;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 1.21 ({@see TimeTrackEvents::TIME_ENTRY_BILLABLE_CHANGED}): the audit record of a `billable` change — who, when,
 * the old and the new value. Dispatched after the commit, once per real change (setting the value it already has records
 * nothing). Ids and scalars only (REQ-ARCH-001 rule 4); the bundle has no audit table, so hosts store this event.
 */
final class TimeEntryBillableChangedEvent extends Event
{
    /**
     * @param string $entryId Id of the entry
     * @param string|null $ownerId Id of the entry owner
     * @param string $actorId Id of the user who changed it (the owner, a manager or an admin)
     * @param bool|null $oldBillable Value before the change (null = unset)
     * @param bool|null $newBillable Value after the change (null = unset)
     * @param string $via Where the change came from: `api` (client API) or `host` (a host call of the service)
     */
    public function __construct(
        public readonly string $entryId,
        public readonly ?string $ownerId,
        public readonly string $actorId,
        public readonly ?bool $oldBillable,
        public readonly ?bool $newBillable,
        public readonly DateTimeImmutable $occurredAt,
        public readonly string $via = 'host',
    ) {
    }
}
