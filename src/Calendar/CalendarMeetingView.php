<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use DateTimeImmutable;
use JsonSerializable;
use LogicException;

/**
 * Since 1.19 (Beacon spec 210 FR-004): one meeting occurrence read by {@see CalendarReaderInterface::meetings()}, with
 * the fields a host meeting read model needs. It lives **only in memory** during one sync: the host keeps the minimal
 * fields of its spec, matches the attendees and drops them, and reads the description and the location once (task
 * keys, join link) without keeping them.
 *
 * Provider ids and personal data (title, description, location, organizer, attendees, links) are read through
 * methods only: debug output, `json_encode()`, casts, `var_export()`, Serializer and log normalisers see `[redacted]`
 * (audit LZ-L1), and serialization throws. Description, location and links are raw provider text (a Google description
 * may hold HTML): escape them before display and validate links before use.
 *
 * Non-meeting entries (Google focus time, out of office, working location, birthdays…) are never returned.
 */
final readonly class CalendarMeetingView implements JsonSerializable
{
    private SensitiveValue $personal;

    /**
     * @param string $eventId Provider event (occurrence) id
     * @param string|null $seriesId Provider recurring series id (null for a single event)
     * @param string|null $originalStart Original start of a recurring occurrence as the provider gives it (Google
     *                                   `originalStartTime`, Graph `originalStart`), for an occurrence id that survives
     *                                   a reschedule; null for a single event
     * @param string $title Title (`summary` / `subject`)
     * @param string $description Description / body as text (empty with Graph `Calendars.ReadBasic`)
     * @param string $location Location display text
     * @param string|null $timeZone The event's IANA time zone, null when the provider gives none or a non-IANA name
     * @param bool $declined Declined by the connected user
     * @param bool $free Marked free / transparent
     * @param bool $private Private or confidential (Google `visibility`, Graph `sensitivity`)
     * @param string|null $organizerEmail Lower-case organizer e-mail
     * @param bool $organizerSelf The connected user organizes it
     * @param list<CalendarAttendee> $attendees Attendees as listed by the provider
     * @param list<string> $joinCandidates Links the provider marks as the meeting's (Google `hangoutLink` and video
     *                                     entry points, Graph `onlineMeeting.joinUrl`); raw, validate before use
     */
    public function __construct(
        string $eventId,
        ?string $seriesId,
        ?string $originalStart,
        string $title,
        string $description,
        string $location,
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
        public ?string $timeZone = null,
        public bool $allDay = false,
        public bool $cancelled = false,
        public bool $declined = false,
        public bool $free = false,
        public bool $private = false,
        ?string $organizerEmail = null,
        public bool $organizerSelf = false,
        array $attendees = [],
        array $joinCandidates = [],
    ) {
        $this->personal = new SensitiveValue(new CalendarMeetingPersonalData($eventId, $seriesId, $originalStart, $title, $description, $location, $organizerEmail, $attendees, $joinCandidates));
    }

    /** Provider event (occurrence) id. */
    public function eventId(): string
    {
        return $this->data()->eventId;
    }

    /** Provider recurring series id (null for a single event). */
    public function seriesId(): ?string
    {
        return $this->data()->seriesId;
    }

    /** Original start of a recurring occurrence as the provider gives it (null for a single event). */
    public function originalStart(): ?string
    {
        return $this->data()->originalStart;
    }

    /** Title (raw provider text). */
    public function title(): string
    {
        return $this->data()->title;
    }

    /** Description / body as raw provider text (escape before display; read once, never keep). */
    public function description(): string
    {
        return $this->data()->description;
    }

    /** Location as raw provider text (escape before display; read once, never keep). */
    public function location(): string
    {
        return $this->data()->location;
    }

    /** Lower-case organizer e-mail, or null. */
    public function organizerEmail(): ?string
    {
        return $this->data()->organizerEmail;
    }

    /**
     * Attendees as listed by the provider (match, then drop).
     *
     * @return list<CalendarAttendee>
     */
    public function attendees(): array
    {
        return $this->data()->attendees;
    }

    /**
     * Raw join links (validate before use).
     *
     * @return list<string>
     */
    public function joinCandidates(): array
    {
        return $this->data()->joinCandidates;
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
        return ['eventId' => SensitiveValue::REDACTED, 'title' => SensitiveValue::REDACTED, 'description' => SensitiveValue::REDACTED, 'start' => $this->start, 'end' => $this->end];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A calendar meeting view cannot be serialized.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A calendar meeting view cannot be unserialized.');
    }

    private function data(): CalendarMeetingPersonalData
    {
        /** @var CalendarMeetingPersonalData $data set by the constructor only */
        $data = $this->personal->reveal();

        return $data;
    }
}
