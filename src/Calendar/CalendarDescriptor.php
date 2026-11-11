<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

/**
 * Since 1.19 (Beacon spec 210 FR-001): one of the user's calendars as the provider lists it
 * ({@see CalendarReaderInterface::calendars()}). The name is the user's own label; keep it only as long as the host's
 * calendars switch needs it.
 */
final readonly class CalendarDescriptor
{
    /**
     * @param string $id Provider calendar id, for the `calendarId` argument of the reader
     * @param string $name Display name (the id when the provider gives none)
     * @param bool $primary The user's primary / default calendar
     */
    public function __construct(
        public string $id,
        public string $name,
        public bool $primary,
    ) {
    }
}
