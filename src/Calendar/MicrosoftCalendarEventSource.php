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
use function str_starts_with;
use function substr;

use const PHP_QUERY_RFC3986;

/**
 * Since 1.14 (contract §6.2): Microsoft Graph reader of the user's default calendar view (`/me/calendarView`), read-only
 * (`Calendars.ReadBasic` + `offline_access`: no body, attachments or extensions). It selects
 * `subject,start,end,isAllDay,isCancelled,showAs,type,seriesMasterId,responseStatus` only (`id` always comes back) and
 * asks for UTC times (`Prefer: outlook.timezone="UTC"`). Next pages are followed only under the same base URI.
 */
final readonly class MicrosoftCalendarEventSource implements CalendarEventSourceInterface
{
    /** The only base URI ever called. */
    public const BASE_URI = 'https://graph.microsoft.com/v1.0/me/calendarView';

    /** The `$select` list. */
    public const SELECT = 'subject,start,end,isAllDay,isCancelled,showAs,type,seriesMasterId,responseStatus';

    /** Events per page. */
    public const PAGE_SIZE = 100;

    /**
     * @param int $maxPages Pages read per sync at most (`calendar.max_pages`); later pages are not read
     */
    public function __construct(
        private CalendarHttpClient $http,
        private int $maxPages = 10,
    ) {
    }

    /**
     * Microsoft 365.
     */
    public function provider(): CalendarProvider
    {
        return CalendarProvider::Microsoft;
    }

    /**
     * Occurrences overlapping `[from, to)` (a calendar view expands series).
     */
    public function fetch(CalendarCredential $credential, DateTimeImmutable $from, DateTimeImmutable $to): iterable
    {
        $url = self::BASE_URI . '?' . http_build_query([
            'startDateTime' => $this->utc($from),
            'endDateTime'   => $this->utc($to),
            '$top'          => (string) self::PAGE_SIZE,
            '$select'       => self::SELECT,
        ], '', '&', PHP_QUERY_RFC3986);
        for ($page = 0; $page < $this->maxPages; ++$page) {
            $data  = $this->http->getJson(self::BASE_URI, $url, $credential, ['Prefer' => 'outlook.timezone="UTC"']);
            $items = $data['value'] ?? [];
            if (!is_array($items)) {
                throw new CalendarSyncException(CalendarSyncException::INVALID_RESPONSE);
            }
            foreach ($items as $item) {
                $event = is_array($item) ? $this->event($item) : null;
                if ($event instanceof CalendarEventView) {
                    yield $event;
                }
            }
            $next = $data['@odata.nextLink'] ?? null;
            if (!is_string($next) || !str_starts_with($next, self::BASE_URI . '?')) {
                return;
            }
            $url = $next;
        }

        // Stopped at `max_pages` with more pages left: the window was not read in full (no stale clean-up).
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
