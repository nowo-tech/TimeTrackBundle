<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use Generator;
use Nowo\TimeTrackBundle\Calendar\CalendarCredential;
use Nowo\TimeTrackBundle\Calendar\CalendarEventView;
use Nowo\TimeTrackBundle\Calendar\CalendarHttpClient;
use Nowo\TimeTrackBundle\Calendar\CalendarSyncException;
use Nowo\TimeTrackBundle\Calendar\GoogleCalendarEventSource;
use Nowo\TimeTrackBundle\Enum\CalendarProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function count;
use function iterator_to_array;
use function json_encode;
use function parse_str;
use function parse_url;

use const DATE_ATOM;
use const JSON_THROW_ON_ERROR;
use const PHP_URL_QUERY;

/**
 * Since 1.14 (contract §6.2, AC-15): the Google adapter asks for the documented fields only, of the primary calendar,
 * and maps a hand-written fake payload (no real calendar data).
 */
final class GoogleCalendarEventSourceTest extends TestCase
{
    public function testRequestsExactlyTheDocumentedFieldsAndMapsEvents(): void
    {
        $urls = [];
        $page = [
            'items' => [
                ['id' => 'evt-1', 'summary' => 'Fake #task-1 review', 'start' => ['dateTime' => '2026-11-03T09:00:00+01:00'], 'end' => ['dateTime' => '2026-11-03T09:30:00+01:00'], 'status' => 'confirmed', 'eventType' => 'default'],
                ['id' => 'evt-2_20261104', 'summary' => 'Daily', 'recurringEventId' => 'evt-2', 'start' => ['dateTime' => '2026-11-04T08:00:00Z'], 'end' => ['dateTime' => '2026-11-04T08:15:00Z'], 'transparency' => 'transparent'],
                ['id'           => 'evt-3', 'summary' => 'Holiday', 'start' => ['date' => '2026-11-05'], 'end' => ['date' => '2026-11-06'], 'status' => 'cancelled', 'eventType' => 'outOfOffice',
                    'attendees' => [['self' => false, 'responseStatus' => 'declined'], ['self' => true, 'responseStatus' => 'declined'], 'not an attendee']],
                ['id' => 'evt-4', 'start' => ['dateTime' => '2026-11-04T08:00:00Z'], 'end' => ['dateTime' => '2026-11-04T09:00:00Z']],
                ['summary' => 'no id', 'start' => ['dateTime' => '2026-11-04T08:00:00Z'], 'end' => ['dateTime' => '2026-11-04T09:00:00Z']],
                ['id' => 'evt-6', 'start' => 'bad', 'end' => ['dateTime' => '2026-11-04T09:00:00Z']],
                ['id' => 'evt-7', 'start' => ['dateTime' => 'tomorrow'], 'end' => ['dateTime' => '2026-11-04T09:00:00Z']],
                ['id' => 'evt-8', 'start' => ['dateTime' => '2026-99-99T99:99:99Z'], 'end' => ['dateTime' => '2026-11-04T09:00:00Z']],
                'not an event',
            ],
            'nextPageToken' => 'page-2',
        ];
        $http = new MockHttpClient(static function (string $method, string $url) use (&$urls, $page): MockResponse {
            $urls[] = $url;

            return new MockResponse(json_encode(count($urls) === 1 ? $page : ['items' => []], JSON_THROW_ON_ERROR));
        });
        $source = new GoogleCalendarEventSource(new CalendarHttpClient($http));

        $events = iterator_to_array($source->fetch(new CalendarCredential('fake'), new DateTimeImmutable('2026-10-22T10:00:00+02:00'), new DateTimeImmutable('2026-11-05T10:00:00Z')), false);

        self::assertSame(CalendarProvider::Google, $source->provider());
        self::assertCount(2, $urls);
        parse_str((string) parse_url($urls[0], PHP_URL_QUERY), $query);
        self::assertStringStartsWith(GoogleCalendarEventSource::BASE_URI . '?', $urls[0]);
        self::assertSame([
            'timeMin'      => '2026-10-22T08:00:00Z',
            'timeMax'      => '2026-11-05T10:00:00Z',
            'singleEvents' => 'true',
            'orderBy'      => 'startTime',
            'maxResults'   => '250',
            'fields'       => 'items(id,summary,start,end,status,recurringEventId,transparency,eventType,attendees(self,responseStatus)),nextPageToken',
        ], $query);
        parse_str((string) parse_url($urls[1], PHP_URL_QUERY), $second);
        self::assertSame('page-2', $second['pageToken']);

        self::assertCount(4, $events);
        self::assertContainsOnlyInstancesOf(CalendarEventView::class, $events);
        [$single, $daily, $holiday, $untitled] = $events;
        self::assertSame(['evt-1', null, 'Fake #task-1 review', false, false, false, false, false], [$single->eventId, $single->seriesId, $single->title, $single->allDay, $single->cancelled, $single->free, $single->declined, $single->otherKind]);
        self::assertSame('2026-11-03T08:00:00+00:00', $single->start->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM));
        self::assertSame(['evt-2', true], [$daily->seriesId, $daily->free]);
        self::assertSame([true, true, true, true], [$holiday->allDay, $holiday->cancelled, $holiday->declined, $holiday->otherKind]);
        self::assertSame('', $untitled->title);
    }

    public function testStopsAfterMaxPages(): void
    {
        $calls = 0;
        $http  = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('{"items":[],"nextPageToken":"again"}');
        });

        $events = (new GoogleCalendarEventSource(new CalendarHttpClient($http), 2))->fetch(new CalendarCredential('t'), new DateTimeImmutable('-1 day'), new DateTimeImmutable());
        self::assertInstanceOf(Generator::class, $events);
        iterator_to_array($events);

        self::assertSame(2, $calls);
        self::assertTrue($events->getReturn(), 'the window was not read in full');
    }

    public function testItemsMustBeAList(): void
    {
        $source = new GoogleCalendarEventSource(new CalendarHttpClient(new MockHttpClient(new MockResponse('{"items":"x"}'))));

        $this->expectExceptionObject(new CalendarSyncException(CalendarSyncException::INVALID_RESPONSE));
        iterator_to_array($source->fetch(new CalendarCredential('t'), new DateTimeImmutable('-1 day'), new DateTimeImmutable()));
    }
}
