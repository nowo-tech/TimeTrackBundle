<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Meeting;

/**
 * Since 1.14 (contract §22.4, Q-B18): an **internal** attendee — a host user who shares an organization with the caller
 * — as `{id, name}` only; never an e-mail, avatar or response status.
 */
final readonly class MeetingAttendeeView
{
    /**
     * Creates the attendee.
     */
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}
