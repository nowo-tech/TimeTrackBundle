<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Calendar;

use DateTimeImmutable;
use Generator;
use Nowo\TimeTrackBundle\Calendar\CalendarCredential;
use Nowo\TimeTrackBundle\Calendar\CalendarHttpClient;
use Nowo\TimeTrackBundle\Calendar\CalendarSyncException;
use Nowo\TimeTrackBundle\Calendar\MicrosoftCalendarEventSource;
use Nowo\TimeTrackBundle\Enum\CalendarProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function count;
use function iterator_to_array;
use function json_encode;
use function parse_str;
use function parse_url;

use const JSON_THROW_ON_ERROR;
use const PHP_URL_QUERY;

/**
 * Since 1.14 (contract §6.2, AC-15): the Microsoft Graph adapter selects the documented fields only, in UTC, follows
 * next links only under its base, and maps a hand-written fake payload.
 */
final class MicrosoftCalendarEventSourceTest extends TestCase
{
    public function testRequestsExactlyTheDocumentedFieldsAndMapsEvents(): void
    {
        $requests = [];
        $first    = [
            'value' => [
                ['id' => 'AAMk-1', 'subject' => 'Fake #task-2 planning', 'start' => ['dateTime' => '2026-11-03T09:00:00.0000000', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2026-11-03T10:00:00.0000000', 'timeZone' => 'UTC'], 'isAllDay' => false, 'isCancelled' => false, 'showAs' => 'busy', 'type' => 'singleInstance', 'responseStatus' => ['response' => 'accepted']],
                ['id' => 'AAMk-2', 'subject' => 'Stand-up', 'seriesMasterId' => 'AAMk-S', 'start' => ['dateTime' => '2026-11-04T08:00:00', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2026-11-04T08:15:00', 'timeZone' => 'UTC'], 'isAllDay' => true, 'isCancelled' => true, 'showAs' => 'free', 'responseStatus' => ['response' => 'declined']],
                ['id' => 'AAMk-3', 'start' => ['dateTime' => '2026-11-04T08:00:00', 'timeZone' => 'Europe/Madrid'], 'end' => ['dateTime' => '2026-11-04T09:00:00', 'timeZone' => 'UTC']],
                ['id' => 'AAMk-4', 'start' => ['dateTime' => '2026-99-99T99:99:99', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2026-11-04T09:00:00', 'timeZone' => 'UTC']],
                ['id' => 'AAMk-5', 'start' => 'x', 'end' => ['dateTime' => '2026-11-04T09:00:00']],
                ['subject' => 'no id', 'start' => ['dateTime' => '2026-11-04T08:00:00'], 'end' => ['dateTime' => '2026-11-04T09:00:00']],
                'not an event',
            ],
            '@odata.nextLink' => MicrosoftCalendarEventSource::BASE_URI . '?$skip=100',
        ];
        $second = ['value' => [], '@odata.nextLink' => 'https://evil.example/me/calendarView?$skip=200'];
        $http   = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests, $first, $second): MockResponse {
            $requests[] = [$url, $options['headers']];

            return new MockResponse(json_encode(count($requests) === 1 ? $first : $second, JSON_THROW_ON_ERROR));
        });
        $source = new MicrosoftCalendarEventSource(new CalendarHttpClient($http));

        $events = iterator_to_array($source->fetch(new CalendarCredential('fake'), new DateTimeImmutable('2026-10-22T10:00:00Z'), new DateTimeImmutable('2026-11-05T10:00:00+01:00')), false);

        self::assertSame(CalendarProvider::Microsoft, $source->provider());
        self::assertCount(2, $requests, 'the next link of another host is never followed');
        parse_str((string) parse_url($requests[0][0], PHP_URL_QUERY), $query);
        self::assertSame([
            'startDateTime' => '2026-10-22T10:00:00Z',
            'endDateTime'   => '2026-11-05T09:00:00Z',
            '$top'          => '100',
            '$select'       => 'subject,start,end,isAllDay,isCancelled,showAs,type,seriesMasterId,responseStatus',
        ], $query);
        self::assertContains('Prefer: outlook.timezone="UTC"', $requests[0][1]);
        self::assertSame(MicrosoftCalendarEventSource::BASE_URI . '?$skip=100', $requests[1][0]);

        self::assertCount(2, $events);
        [$single, $daily] = $events;
        self::assertSame(['AAMk-1', null, 'Fake #task-2 planning', false, false, false, false], [$single->eventId, $single->seriesId, $single->title, $single->allDay, $single->cancelled, $single->free, $single->declined]);
        self::assertSame('2026-11-03 09:00:00 UTC', $single->start->format('Y-m-d H:i:s T'));
        self::assertSame(['AAMk-S', 'Stand-up', true, true, true, true], [$daily->seriesId, $daily->title, $daily->allDay, $daily->cancelled, $daily->free, $daily->declined]);
    }

    public function testStopsAfterMaxPages(): void
    {
        $calls = 0;
        $http  = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse(json_encode(['value' => [], '@odata.nextLink' => MicrosoftCalendarEventSource::BASE_URI . '?$skip=1'], JSON_THROW_ON_ERROR));
        });

        $events = (new MicrosoftCalendarEventSource(new CalendarHttpClient($http), 3))->fetch(new CalendarCredential('t'), new DateTimeImmutable('-1 day'), new DateTimeImmutable());
        self::assertInstanceOf(Generator::class, $events);
        iterator_to_array($events);

        self::assertSame(3, $calls);
        self::assertTrue($events->getReturn(), 'the window was not read in full');
    }

    public function testValueMustBeAList(): void
    {
        $source = new MicrosoftCalendarEventSource(new CalendarHttpClient(new MockHttpClient(new MockResponse('{"value":1}'))));

        $this->expectExceptionObject(new CalendarSyncException(CalendarSyncException::INVALID_RESPONSE));
        iterator_to_array($source->fetch(new CalendarCredential('t'), new DateTimeImmutable('-1 day'), new DateTimeImmutable()));
    }
}
