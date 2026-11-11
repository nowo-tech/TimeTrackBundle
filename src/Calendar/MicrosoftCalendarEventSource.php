<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Generator;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\Enum\CalendarProvider;

use function http_build_query;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use function rawurlencode;
use function sprintf;
use function str_starts_with;
use function substr;

use const PHP_QUERY_RFC3986;

/**
 * Since 1.14 (contract §6.2): Microsoft Graph reader, read-only. {@see self::fetch()} reads the user's default
 * calendar view (`/me/calendarView`) for suggestions and selects
 * `subject,start,end,isAllDay,isCancelled,showAs,type,seriesMasterId,responseStatus` only (`id` always comes back) with
 * UTC times (`Prefer: outlook.timezone="UTC"`). Next pages are followed only under the same base URI.
 *
 * Since 1.19 ({@see CalendarReaderInterface}, Beacon spec 210): the calendar list (`/me/calendars`), events of any
 * calendar and meetings. Scopes: `Calendars.ReadBasic` + `offline_access` (default) reads everything but the body, so
 * task keys come from titles only; `Calendars.Read` (`calendar.microsoft_scope`) also reads the body as text, for task
 * keys in the description. Graph gives no per-attendee "self" flag.
 */
final readonly class MicrosoftCalendarEventSource implements CalendarEventSourceInterface, CalendarReaderInterface
{
    /** Since 1.19: the API base every URL starts with. */
    public const API_BASE = 'https://graph.microsoft.com/v1.0/me/';

    /** The calendar view of the default calendar (the base URI of {@see self::fetch()}). */
    public const BASE_URI = self::API_BASE . 'calendarView';

    /** Since 1.19: the calendar list URI. */
    public const CALENDARS_URI = self::API_BASE . 'calendars';

    /** The `$select` list of {@see self::fetch()} / {@see self::events()}. */
    public const SELECT = 'subject,start,end,isAllDay,isCancelled,showAs,type,seriesMasterId,responseStatus';

    /** Since 1.19: the `$select` list of {@see self::meetings()} (`body` is added with `Calendars.Read`). */
    public const MEETING_SELECT = 'subject,location,start,end,isAllDay,isCancelled,sensitivity,showAs,type,seriesMasterId,responseStatus,organizer,isOrganizer,attendees,onlineMeeting,originalStart,originalStartTimeZone';

    /** Since 1.19: least-privilege delegated scope (no body). */
    public const SCOPE_READ_BASIC = 'Calendars.ReadBasic';

    /** Since 1.19: delegated scope that also reads the body. */
    public const SCOPE_READ = 'Calendars.Read';

    /** Events per page. */
    public const PAGE_SIZE = 100;

    /** Since 1.19: private / confidential `sensitivity` values. */
    private const PRIVATE_SENSITIVITY = ['private', 'confidential'];

    /**
     * @param int $maxPages Pages read per sync at most (`calendar.max_pages`); later pages are not read
     * @param string $scope Since 1.19: the granted calendar scope (`calendar.microsoft_scope`): {@see self::SCOPE_READ}
     *                      lets {@see self::meetings()} read the body
     */
    public function __construct(
        private CalendarHttpClient $http,
        private int $maxPages = 10,
        private string $scope = self::SCOPE_READ_BASIC,
    ) {
        if (!in_array($scope, [self::SCOPE_READ_BASIC, self::SCOPE_READ], true)) {
            throw new InvalidArgumentException(sprintf('The Microsoft calendar scope must be "%s" or "%s".', self::SCOPE_READ_BASIC, self::SCOPE_READ));
        }
    }

    /**
     * Microsoft 365.
     */
    public function provider(): CalendarProvider
    {
        return CalendarProvider::Microsoft;
    }

    /**
     * Occurrences of the default calendar overlapping `[from, to)` (a calendar view expands series).
     */
    public function fetch(CalendarCredential $credential, DateTimeImmutable $from, DateTimeImmutable $to): iterable
    {
        return $this->events($credential, $from, $to);
    }

    /**
     * Since 1.19: the user's calendars.
     */
    public function calendars(CalendarCredential $credential): array
    {
        $url       = self::CALENDARS_URI . '?' . http_build_query(['$select' => 'id,name,isDefaultCalendar', '$top' => '50'], '', '&', PHP_QUERY_RFC3986);
        $calendars = [];
        foreach ($this->items($credential, self::API_BASE, $url, self::CALENDARS_URI . '?', []) as $item) {
            $id = CalendarFields::text($item['id'] ?? null);
            if ($id !== '') {
                $name        = CalendarFields::text($item['name'] ?? null);
                $calendars[] = new CalendarDescriptor($id, $name !== '' ? $name : $id, ($item['isDefaultCalendar'] ?? false) === true);
            }
        }

        return $calendars;
    }

    /**
     * Since 1.19: occurrences of one calendar overlapping `[from, to)`; returns `true` when stopped at the page cap.
     */
    public function events(CalendarCredential $credential, DateTimeImmutable $from, DateTimeImmutable $to, string $calendarId = self::PRIMARY): iterable
    {
        $items = $this->viewItems($credential, $from, $to, $calendarId, self::SELECT, ['Prefer' => 'outlook.timezone="UTC"']);
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
     * Since 1.19: meetings of one calendar overlapping `[from, to)` (series masters skipped; the body as text with
     * `Calendars.Read`).
     */
    public function meetings(CalendarCredential $credential, DateTimeImmutable $from, DateTimeImmutable $to, string $calendarId = self::PRIMARY): CalendarMeetingBatch
    {
        $withBody = $this->scope === self::SCOPE_READ;
        $items    = $this->viewItems(
            $credential,
            $from,
            $to,
            $calendarId,
            self::MEETING_SELECT . ($withBody ? ',body' : ''),
            ['Prefer' => 'outlook.timezone="UTC"' . ($withBody ? ', outlook.body-content-type="text"' : '')],
        );
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
     * Items of the calendar view of one calendar (the default one under {@see self::BASE_URI}).
     *
     * @param array<string, string> $headers
     *
     * @throws CalendarSyncException
     *
     * @return Generator<int, array<mixed>, mixed, bool>
     */
    private function viewItems(CalendarCredential $credential, DateTimeImmutable $from, DateTimeImmutable $to, string $calendarId, string $select, array $headers): Generator
    {
        $query = http_build_query([
            'startDateTime' => $this->utc($from),
            'endDateTime'   => $this->utc($to),
            '$top'          => (string) self::PAGE_SIZE,
            '$select'       => $select,
        ], '', '&', PHP_QUERY_RFC3986);
        if (CalendarFields::calendarId($calendarId) === self::PRIMARY) {
            return yield from $this->items($credential, self::BASE_URI, self::BASE_URI . '?' . $query, self::BASE_URI . '?', $headers);
        }

        // Graph may spell the calendar id of a next link differently: any next link under the API base is followed.
        return yield from $this->items($credential, self::API_BASE, self::API_BASE . 'calendars/' . rawurlencode($calendarId) . '/calendarView?' . $query, self::API_BASE, $headers);
    }

    /**
     * Items of every page; next links are followed only when they start with `$nextPrefix`. Returns true when stopped
     * at the page cap with pages left.
     *
     * @param array<string, string> $headers
     *
     * @throws CalendarSyncException
     *
     * @return Generator<int, array<mixed>, mixed, bool>
     */
    private function items(CalendarCredential $credential, string $baseUri, string $url, string $nextPrefix, array $headers): Generator
    {
        for ($page = 0; $page < $this->maxPages; ++$page) {
            $data  = $this->http->getJson($baseUri, $url, $credential, $headers);
            $items = $data['value'] ?? [];
            if (!is_array($items)) {
                throw new CalendarSyncException(CalendarSyncException::INVALID_RESPONSE);
            }
            foreach ($items as $item) {
                if (is_array($item)) {
                    yield $item;
                }
            }
            $next = $data['@odata.nextLink'] ?? null;
            if (!is_string($next) || !str_starts_with($next, $nextPrefix)) {
                return false;
            }
            $url = $next;
        }

        return true;
    }

    /**
     * @param array<mixed> $item
     */
    private function event(array $item): ?CalendarEventView
    {
        $id    = $item['id'] ?? null;
        $start = $this->time($item['start'] ?? null);
        $end   = $this->time($item['end'] ?? null);
        if (!is_string($id) || $id === '' || !$start instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable) {
            return null;
        }
        $series   = $item['seriesMasterId'] ?? null;
        $title    = $item['subject'] ?? null;
        $response = is_array($item['responseStatus'] ?? null) ? ($item['responseStatus']['response'] ?? null) : null;

        return new CalendarEventView(
            $id,
            is_string($series) && $series !== '' ? $series : null,
            is_string($title) ? $title : '',
            $start,
            $end,
            ($item['isAllDay'] ?? false) === true,
            ($item['isCancelled'] ?? false) === true,
            ($item['showAs'] ?? null) === 'free',
            $response === 'declined',
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
        if ($id === '' || !$start instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable || ($item['type'] ?? null) === 'seriesMaster') {
            return null;
        }
        $attendees = [];
        foreach (is_array($item['attendees'] ?? null) ? $item['attendees'] : [] as $attendee) {
            if (!is_array($attendee)) {
                continue;
            }
            $address     = is_array($attendee['emailAddress'] ?? null) ? $attendee['emailAddress'] : [];
            $status      = is_array($attendee['status'] ?? null) ? $attendee['status'] : [];
            $attendees[] = new CalendarAttendee(CalendarFields::email($address['address'] ?? null) ?? '', false, ($status['response'] ?? null) === 'declined', ($attendee['type'] ?? null) === 'resource');
        }
        $body      = is_array($item['body'] ?? null) ? $item['body'] : [];
        $location  = is_array($item['location'] ?? null) ? $item['location'] : [];
        $online    = is_array($item['onlineMeeting'] ?? null) ? $item['onlineMeeting'] : [];
        $response  = is_array($item['responseStatus'] ?? null) ? $item['responseStatus'] : [];
        $organizer = is_array($item['organizer'] ?? null) && is_array($item['organizer']['emailAddress'] ?? null) ? $item['organizer']['emailAddress'] : [];
        $join      = CalendarFields::text($online['joinUrl'] ?? null);
        $series    = CalendarFields::text($item['seriesMasterId'] ?? null);
        $original  = CalendarFields::text($item['originalStart'] ?? null);

        return new CalendarMeetingView(
            $id,
            $series !== '' ? $series : null,
            $original !== '' ? $original : null,
            CalendarFields::text($item['subject'] ?? null),
            CalendarFields::text($body['content'] ?? null),
            CalendarFields::text($location['displayName'] ?? null),
            $start,
            $end,
            CalendarFields::zone($item['originalStartTimeZone'] ?? null),
            ($item['isAllDay'] ?? false) === true,
            ($item['isCancelled'] ?? false) === true,
            ($response['response'] ?? null) === 'declined',
            ($item['showAs'] ?? null) === 'free',
            in_array($item['sensitivity'] ?? null, self::PRIVATE_SENSITIVITY, true),
            CalendarFields::email($organizer['address'] ?? null),
            ($item['isOrganizer'] ?? false) === true,
            $attendees,
            $join !== '' ? [$join] : [],
        );
    }

    /**
     * Graph `dateTimeTimeZone` in UTC (`2026-11-05T09:00:00.0000000`, zone `UTC`).
     */
    private function time(mixed $value): ?DateTimeImmutable
    {
        $dateTime = is_array($value) ? ($value['dateTime'] ?? null) : null;
        $zone     = is_array($value) ? ($value['timeZone'] ?? 'UTC') : 'UTC';
        if (!is_string($dateTime) || $zone !== 'UTC' || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,7})?$/D', $dateTime) !== 1) {
            return null;
        }
        try {
            return new DateTimeImmutable(substr($dateTime, 0, 19), new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }
    }

    private function utc(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
