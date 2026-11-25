<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 2.1.0 (Beacon spec 256, Q-TS-2, {@see TimeTrackEvents::TIME_ENTRY_UNALLOCATED}): a stop the bundle itself
 * triggered (start-replacing, switch, idle resolution, user deletion) stored an entry with neither task nor subject while
 * `entries.require_allocation` is on. The time is never lost (I-4): ask the person to allocate it later
 * (`TimeEntryOperationsInterface::correct()` with an allocation patch). Dispatched after the commit; ids only.
 */
final class TimeEntryUnallocatedEvent extends Event
{
    /**
     * @param string $entryId Id of the stored entry
     * @param string $userId Id of the entry owner
     * @param string|null $scopeKey The entry's tenant scope (null = none)
     * @param string|null $closedBy Why the bundle closed the timer (`replaced`, `user_deletion`) or null (switch, idle)
     */
    public function __construct(
        public readonly string $entryId,
        public readonly string $userId,
        public readonly ?string $scopeKey,
        public readonly ?string $closedBy,
    ) {
    }
}
