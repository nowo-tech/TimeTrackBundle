<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use LogicException;

/**
 * Since 1.19 (Beacon spec 210 FR-003): the meetings read for one calendar and window. `complete` is false when the
 * page cap stopped the read: the host then keeps the stored meetings it did not see instead of deleting them.
 */
final readonly class CalendarMeetingBatch
{
    /**
     * @param list<CalendarMeetingView> $meetings
     */
    public function __construct(
        public array $meetings,
        public bool $complete = true,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A calendar meeting batch cannot be serialized.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A calendar meeting batch cannot be unserialized.');
    }
}
