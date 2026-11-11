<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use LogicException;

/**
 * Since 1.19 (Beacon spec 210): one attendee of a {@see CalendarMeetingView}, **in memory only**. The host matches the
 * e-mail against its own users and drops it; debug output is redacted and serialization throws.
 */
final readonly class CalendarAttendee
{
    /**
     * @param string $email Lower-case e-mail ('' when the provider gives none)
     * @param bool $self The connected user (Google only; Microsoft Graph has no such flag, always false)
     * @param bool $declined The attendee declined
     * @param bool $resource A room or equipment, not a person
     */
    public function __construct(
        public string $email,
        public bool $self = false,
        public bool $declined = false,
        public bool $resource = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['email' => '[redacted]', 'self' => $this->self, 'declined' => $this->declined, 'resource' => $this->resource];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A calendar attendee cannot be serialized.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A calendar attendee cannot be unserialized.');
    }
}
