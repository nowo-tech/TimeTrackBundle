<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use JsonSerializable;
use LogicException;

use function is_string;

/**
 * Since 1.19 (Beacon spec 210): one attendee of a {@see CalendarMeetingView}, **in memory only**. The host matches the
 * e-mail ({@see self::email()}) against its own users and drops it. Debug output, `json_encode()`, casts,
 * `var_export()`, Serializer and log normalisers never show the e-mail (audit LZ-L1); serialization throws.
 */
final readonly class CalendarAttendee implements JsonSerializable
{
    private SensitiveValue $address;

    /**
     * @param string $email Lower-case e-mail ('' when the provider gives none)
     * @param bool $self The connected user (Google only; Microsoft Graph has no such flag, always false)
     * @param bool $declined The attendee declined
     * @param bool $resource A room or equipment, not a person
     */
    public function __construct(
        string $email,
        public bool $self = false,
        public bool $declined = false,
        public bool $resource = false,
    ) {
        $this->address = new SensitiveValue($email);
    }

    /**
     * The lower-case e-mail ('' when the provider gives none): match it, then drop it.
     */
    public function email(): string
    {
        $email = $this->address->reveal();

        return is_string($email) ? $email : '';
    }

    /**
     * Redacted.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['email' => SensitiveValue::REDACTED, 'self' => $this->self, 'declined' => $this->declined, 'resource' => $this->resource];
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
