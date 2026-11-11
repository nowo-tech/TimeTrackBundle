<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

/**
 * Since 1.19: the provider ids and personal data of a {@see CalendarMeetingView}, kept inside a {@see SensitiveValue}.
 *
 * @internal
 */
final readonly class CalendarMeetingPersonalData
{
    /**
     * @param list<CalendarAttendee> $attendees
     * @param list<string> $joinCandidates
     */
    public function __construct(
        public string $eventId,
        public ?string $seriesId,
        public ?string $originalStart,
        public string $title,
        public string $description,
        public string $location,
        public ?string $organizerEmail,
        public array $attendees,
        public array $joinCandidates,
    ) {
    }
}
