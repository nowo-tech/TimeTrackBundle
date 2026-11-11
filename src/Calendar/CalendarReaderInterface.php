<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Enum\CalendarProvider;

/**
 * Since 1.19 (Beacon request 2026-11-09, spec 210): a read-only reader of **any** of the user's calendars, for a host
 * meeting read model. {@see GoogleCalendarEventSource} and {@see MicrosoftCalendarEventSource} implement it next to
 * {@see CalendarEventSourceInterface} (the suggestions reader of the primary calendar, unchanged). Same HTTP rules:
 * constant base URIs, `GET` only, no redirects, bounded pages and size, errors as {@see CalendarSyncException} codes.
 *
 * Inject the concrete reader you need (`GoogleCalendarEventSource`, `MicrosoftCalendarEventSource`); both are
 * registered whether or not calendar suggestions are on.
 */
interface CalendarReaderInterface
{
    /** The `calendarId` of the user's primary / default calendar. */
    public const PRIMARY = 'primary';

    /**
     * The provider this reader reads.
     */
    public function provider(): CalendarProvider;

    /**
     * The user's calendars (Google `calendarList`, readable ones; Graph `me/calendars`).
     *
     * @throws CalendarSyncException
     *
     * @return list<CalendarDescriptor>
     */
    public function calendars(CalendarCredential $credential): array;

    /**
     * Occurrences of one calendar overlapping `[from, to)` with the suggestion fields only, as
     * {@see CalendarEventSourceInterface::fetch()} (a generator that stopped at the page cap returns `true`).
     *
     * @throws CalendarSyncException
     *
     * @return iterable<CalendarEventView>
     */
    public function events(CalendarCredential $credential, DateTimeImmutable $from, DateTimeImmutable $to, string $calendarId = self::PRIMARY): iterable;

    /**
     * Meeting occurrences of one calendar overlapping `[from, to)`, with attendees, join candidates, privacy and time
     * zone. Non-meeting entries are skipped.
     *
     * @throws CalendarSyncException
     */
    public function meetings(CalendarCredential $credential, DateTimeImmutable $from, DateTimeImmutable $to, string $calendarId = self::PRIMARY): CalendarMeetingBatch;
}
