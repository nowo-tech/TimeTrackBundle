<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Nowo\TimeTrackBundle\Enum\CalendarProvider;

use function http_build_query;
use function is_array;
use function is_string;
use function preg_match;

use const PHP_QUERY_RFC3986;

/**
 * Since 1.14 (contract §6.2): Google Calendar v3 reader of the **primary** calendar, read-only
 * (`calendar.events.readonly`). It asks for these fields only: `items(id,summary,start,end,status,recurringEventId,
 * transparency,eventType,attendees(self,responseStatus)),nextPageToken` — `attendees(self,responseStatus)` carries no
 * identity of anyone; only the user's own answer is read.
 */
final readonly class GoogleCalendarEventSource implements CalendarEventSourceInterface
{
    /** The only base URI ever called. */
    public const BASE_URI = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';

    /** The `fields` mask. */
    public const FIELDS = 'items(id,summary,start,end,status,recurringEventId,transparency,eventType,attendees(self,responseStatus)),nextPageToken';

    /** Events per page. */
    public const PAGE_SIZE = 250;

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
     * Occurrences overlapping `[from, to)` (`singleEvents=true`).
     */
    public function fetch(CalendarCredential $credential, DateTimeImmutable $from, DateTimeImmutable $to): iterable
    {
        $pageToken = null;
        for ($page = 0; $page < $this->maxPages; ++$page) {
            $query = [
                'timeMin'      => $this->utc($from),
                'timeMax'      => $this->utc($to),
                'singleEvents' => 'true',
                'orderBy'      => 'startTime',
                'maxResults'   => (string) self::PAGE_SIZE,
                'fields'       => self::FIELDS,
            ];
            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }
            $data  = $this->http->getJson(self::BASE_URI, self::BASE_URI . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986), $credential);
            $items = $data['items'] ?? [];
            if (!is_array($items)) {
                throw new CalendarSyncException(CalendarSyncException::INVALID_RESPONSE);
            }
            foreach ($items as $item) {
                $event = is_array($item) ? $this->event($item) : null;
                if ($event instanceof CalendarEventView) {
                    yield $event;
                }
            }
            $next = $data['nextPageToken'] ?? null;
            if (!is_string($next) || $next === '') {
                return;
            }
            $pageToken = $next;
        }
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
