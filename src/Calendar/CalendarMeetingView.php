<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use DateTimeImmutable;
use LogicException;

/**
 * Since 1.19 (Beacon spec 210 FR-004): one meeting occurrence read by {@see CalendarReaderInterface::meetings()}, with
 * the fields a host meeting read model needs. It lives **only in memory** during one sync: the host keeps the minimal
 * fields of its spec, matches the attendees and drops them, and reads the description and the location once (task
 * keys, join link) without keeping them. Debug output is redacted and serialization throws, like
 * {@see CalendarEventView}.
 *
 * Non-meeting entries (Google focus time, out of office, working location, birthdays…) are never returned.
 */
final readonly class CalendarMeetingView
{
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
        public string $eventId,
        public ?string $seriesId,
        public ?string $originalStart,
        public string $title,
        public string $description,
        public string $location,
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
        public ?string $timeZone = null,
        public bool $allDay = false,
        public bool $cancelled = false,
        public bool $declined = false,
        public bool $free = false,
        public bool $private = false,
        public ?string $organizerEmail = null,
        public bool $organizerSelf = false,
        public array $attendees = [],
        public array $joinCandidates = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['eventId' => '[redacted]', 'title' => '[redacted]', 'description' => '[redacted]', 'start' => $this->start, 'end' => $this->end];
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
}
