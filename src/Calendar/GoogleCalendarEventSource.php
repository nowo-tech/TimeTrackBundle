<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Generator;
use Nowo\TimeTrackBundle\Enum\CalendarProvider;

use function http_build_query;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use function rawurlencode;

use const PHP_QUERY_RFC3986;

/**
 * Since 1.14 (contract §6.2): Google Calendar v3 reader, read-only. {@see self::fetch()} reads the **primary** calendar
 * for suggestions (`calendar.events.readonly`) and asks for these fields only: `items(id,summary,start,end,status,
 * recurringEventId,transparency,eventType,attendees(self,responseStatus)),nextPageToken` — `attendees(self,
 * responseStatus)` carries no identity of anyone; only the user's own answer is read.
 *
 * Since 1.19 ({@see CalendarReaderInterface}, Beacon spec 210): the calendar list (`users/me/calendarList`, needs
 * `calendar.calendarlist.readonly`), events of any calendar, and meetings with {@see self::MEETING_FIELDS}.
 */
final readonly class GoogleCalendarEventSource implements CalendarEventSourceInterface, CalendarReaderInterface
{
    /** Since 1.19: the API base every URL starts with. */
    public const API_BASE = 'https://www.googleapis.com/calendar/v3/';

    /** The events URI of the primary calendar (the base URI of {@see self::fetch()}). */
    public const BASE_URI = self::API_BASE . 'calendars/primary/events';

    /** Since 1.19: the calendar list URI. */
    public const CALENDAR_LIST_URI = self::API_BASE . 'users/me/calendarList';

    /** The `fields` mask of {@see self::fetch()} / {@see self::events()}. */
    public const FIELDS = 'items(id,summary,start,end,status,recurringEventId,transparency,eventType,attendees(self,responseStatus)),nextPageToken';

    /** Since 1.19: the `fields` mask of {@see self::meetings()} (no attachments, no attendee names). */
    public const MEETING_FIELDS = 'items(id,status,summary,description,location,start,end,recurringEventId,originalStartTime,visibility,transparency,eventType,organizer(email,self),attendees(email,self,responseStatus,resource),hangoutLink,conferenceData(entryPoints(entryPointType,uri))),nextPageToken';

    /** Since 1.19: the `fields` mask of {@see self::calendars()}. */
    public const CALENDAR_FIELDS = 'items(id,summary,summaryOverride,primary),nextPageToken';

    /** Events per page. */
    public const PAGE_SIZE = 250;

    /** Since 1.19: private / confidential `visibility` values. */
    private const PRIVATE_VISIBILITY = ['private', 'confidential'];

    /**
     * @param int $maxPages Pages read per sync at most (`calendar.max_pages`); later pages are not read
     */
    public function __construct(
        private CalendarHttpClient $http,
        private int $maxPages = 10,
    ) {
    }

    /**
     * Google.
     */
    public function provider(): CalendarProvider
    {
        return CalendarProvider::Google;
    }

    /**
     * Occurrences of the primary calendar overlapping `[from, to)` (`singleEvents=true`).
     */
    public function fetch(CalendarCredential $credential, DateTimeImmutable $from, DateTimeImmutable $to): iterable
    {
        return $this->events($credential, $from, $to);
    }

    /**
     * Since 1.19: the user's calendars with at least reader access.
     */
    public function calendars(CalendarCredential $credential): array
    {
        $calendars = [];
        foreach ($this->items($credential, self::CALENDAR_LIST_URI, ['minAccessRole' => 'reader', 'maxResults' => (string) self::PAGE_SIZE, 'fields' => self::CALENDAR_FIELDS]) as $item) {
            $id = CalendarFields::text($item['id'] ?? null);
            if ($id === '') {
                continue;
            }
            $name        = CalendarFields::text($item['summaryOverride'] ?? null);
            $name        = $name !== '' ? $name : CalendarFields::text($item['summary'] ?? null);
            $calendars[] = new CalendarDescriptor($id, $name !== '' ? $name : $id, ($item['primary'] ?? false) === true);
        }

        return $calendars;
    }

    /**
     * Since 1.19: occurrences of one calendar overlapping `[from, to)`; returns `true` when stopped at the page cap.
     */
    public function events(CalendarCredential $credential, DateTimeImmutable $from, DateTimeImmutable $to, string $calendarId = self::PRIMARY): iterable
    {
        $items = $this->items($credential, $this->eventsUri($calendarId), $this->query($from, $to, self::FIELDS));
        foreach ($items as $item) {
            $event = $this->event($item);
            if ($event instanceof CalendarEventView) {
                yield $event;
            }
        }

        // Stopped at `max_pages` with more pages left: the window was not read in full (no stale clean-up).
        if ($items->getReturn()) {
            return true;
        }
    }

    /**
     * Since 1.19: meetings of one calendar overlapping `[from, to)`; event types other than `default` (focus time, out
     * of office, working location, birthdays, Gmail reservations) are skipped.
     */
    public function meetings(CalendarCredential $credential, DateTimeImmutable $from, DateTimeImmutable $to, string $calendarId = self::PRIMARY): CalendarMeetingBatch
    {
        $items    = $this->items($credential, $this->eventsUri($calendarId), $this->query($from, $to, self::MEETING_FIELDS));
        $meetings = [];
        foreach ($items as $item) {
            $meeting = $this->meeting($item);
            if ($meeting instanceof CalendarMeetingView) {
                $meetings[] = $meeting;
            }
        }

        return new CalendarMeetingBatch($meetings, !$items->getReturn());
    }

    /**
     * Items of every page under one base URI; returns true when stopped at the page cap with pages left.
     *
     * @param array<string, string> $query
     *
     * @throws CalendarSyncException
     *
     * @return Generator<int, array<mixed>, mixed, bool>
     */
    private function items(CalendarCredential $credential, string $baseUri, array $query): Generator
    {
        for ($page = 0; $page < $this->maxPages; ++$page) {
            $data  = $this->http->getJson($baseUri, $baseUri . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986), $credential);
            $items = $data['items'] ?? [];
            if (!is_array($items)) {
                throw new CalendarSyncException(CalendarSyncException::INVALID_RESPONSE);
            }
            foreach ($items as $item) {
                if (is_array($item)) {
                    yield $item;
                }
            }
            $next = $data['nextPageToken'] ?? null;
            if (!is_string($next) || $next === '') {
                return false;
            }
            $query['pageToken'] = $next;
        }

        return true;
    }

    /**
     * The events URI of a calendar (the primary one is {@see self::BASE_URI}).
     */
    private function eventsUri(string $calendarId): string
    {
        return self::API_BASE . 'calendars/' . rawurlencode(CalendarFields::calendarId($calendarId)) . '/events';
    }

    /**
     * @return array<string, string>
     */
    private function query(DateTimeImmutable $from, DateTimeImmutable $to, string $fields): array
    {
        return [
            'timeMin'      => $this->utc($from),
            'timeMax'      => $this->utc($to),
            'singleEvents' => 'true',
            'orderBy'      => 'startTime',
            'maxResults'   => (string) self::PAGE_SIZE,
            'fields'       => $fields,
        ];
    }

    /**
     * @param array<mixed> $item
     */
    private function event(array $item): ?CalendarEventView
    {
        $id = $item['id'] ?? null;
        if (!is_string($id) || $id === '') {
            return null;
        }
        $start = $this->time($item['start'] ?? null);
        $end   = $this->time($item['end'] ?? null);
        if ($start === null || $end === null) {
            return null;
        }
        $declined = false;
        foreach (is_array($item['attendees'] ?? null) ? $item['attendees'] : [] as $attendee) {
            if (is_array($attendee) && ($attendee['self'] ?? false) === true && ($attendee['responseStatus'] ?? null) === 'declined') {
                $declined = true;
            }
        }
        $series    = $item['recurringEventId'] ?? null;
        $title     = $item['summary'] ?? null;
        $eventType = $item['eventType'] ?? 'default';

        return new CalendarEventView(
            $id,
            is_string($series) && $series !== '' ? $series : null,
            is_string($title) ? $title : '',
            $start[0],
            $end[0],
            $start[1],
            ($item['status'] ?? null) === 'cancelled',
            ($item['transparency'] ?? null) === 'transparent',
            $declined,
            $eventType !== 'default',
        );
    }

    /**
     * @param array<mixed> $item
     */
    private function meeting(array $item): ?CalendarMeetingView
    {
        $id    = CalendarFields::text($item['id'] ?? null);
        $start = $this->time($item['start'] ?? null);
        $end   = $this->time($item['end'] ?? null);
        if ($id === '' || $start === null || $end === null || ($item['eventType'] ?? 'default') !== 'default') {
            return null;
        }
        $attendees = [];
        $declined  = false;
        foreach (is_array($item['attendees'] ?? null) ? $item['attendees'] : [] as $attendee) {
            if (!is_array($attendee)) {
                continue;
            }
            $self        = ($attendee['self'] ?? false) === true;
            $no          = ($attendee['responseStatus'] ?? null) === 'declined';
            $declined    = $declined || ($self && $no);
            $attendees[] = new CalendarAttendee(CalendarFields::email($attendee['email'] ?? null) ?? '', $self, $no, ($attendee['resource'] ?? false) === true);
        }
        $links      = [CalendarFields::text($item['hangoutLink'] ?? null)];
        $conference = is_array($item['conferenceData'] ?? null) ? $item['conferenceData'] : [];
        foreach (is_array($conference['entryPoints'] ?? null) ? $conference['entryPoints'] : [] as $entry) {
            if (is_array($entry) && ($entry['entryPointType'] ?? null) === 'video') {
                $links[] = CalendarFields::text($entry['uri'] ?? null);
            }
        }
        $joinCandidates = [];
        foreach ($links as $link) {
            if ($link !== '' && !in_array($link, $joinCandidates, true)) {
                $joinCandidates[] = $link;
            }
        }
        $original      = is_array($item['originalStartTime'] ?? null) ? $item['originalStartTime'] : [];
        $originalStart = CalendarFields::text($original['dateTime'] ?? null);
        $originalStart = $originalStart !== '' ? $originalStart : CalendarFields::text($original['date'] ?? null);
        $organizer     = is_array($item['organizer'] ?? null) ? $item['organizer'] : [];
        $series        = CalendarFields::text($item['recurringEventId'] ?? null);
        $startData     = is_array($item['start'] ?? null) ? $item['start'] : [];

        return new CalendarMeetingView(
            $id,
            $series !== '' ? $series : null,
            $originalStart !== '' ? $originalStart : null,
            CalendarFields::text($item['summary'] ?? null),
            CalendarFields::text($item['description'] ?? null),
            CalendarFields::text($item['location'] ?? null),
            $start[0],
            $end[0],
            CalendarFields::zone($startData['timeZone'] ?? null),
            $start[1],
            ($item['status'] ?? null) === 'cancelled',
            $declined,
            ($item['transparency'] ?? null) === 'transparent',
            in_array($item['visibility'] ?? null, self::PRIVATE_VISIBILITY, true),
            CalendarFields::email($organizer['email'] ?? null),
            ($organizer['self'] ?? false) === true,
            $attendees,
            $joinCandidates,
        );
    }

    /**
     * @return array{0: DateTimeImmutable, 1: bool}|null instant and all-day flag
     */
    private function time(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $dateTime = $value['dateTime'] ?? null;
        $date     = $value['date'] ?? null;
        try {
            if (is_string($dateTime) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/D', $dateTime) === 1) {
                return [new DateTimeImmutable($dateTime), false];
            }
            if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1) {
                return [new DateTimeImmutable($date . 'T00:00:00', new DateTimeZone('UTC')), true];
            }
        } catch (Exception) {
            return null;
        }

        return null;
    }

    private function utc(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
