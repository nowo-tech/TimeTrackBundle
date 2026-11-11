<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Calendar;

use DateTimeImmutable;
use Generator;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\Calendar\CalendarAttendee;
use Nowo\TimeTrackBundle\Calendar\CalendarCredential;
use Nowo\TimeTrackBundle\Calendar\CalendarDescriptor;
use Nowo\TimeTrackBundle\Calendar\CalendarHttpClient;
use Nowo\TimeTrackBundle\Calendar\MicrosoftCalendarEventSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function count;
use function iterator_to_array;
use function json_encode;
use function parse_str;
use function parse_url;
use function preg_replace;

use const JSON_THROW_ON_ERROR;
use const PHP_URL_QUERY;

/**
 * Since 1.19 (Beacon request 2026-11-09, spec 210): the Microsoft Graph reader lists calendars, reads any calendar and
 * maps meetings from hand-written fake payloads; the body is read only with `Calendars.Read`.
 */
final class MicrosoftCalendarReaderTest extends TestCase
{
    /** @var list<array{url: string, prefer: string}> */
    private array $calls = [];

    public function testListsCalendarsOverPages(): void
    {
        $reader = $this->reader([
            ['value' => [['id' => 'AAMk-default=', 'name' => 'Calendar', 'isDefaultCalendar' => true], ['name' => 'no id'], 'not a calendar'], '@odata.nextLink' => MicrosoftCalendarEventSource::CALENDARS_URI . '?$skip=50'],
            ['value' => [['id' => 'AAMk-other='], ['id' => 'AAMk-third=', 'name' => 'Holidays']], '@odata.nextLink' => 'https://evil.example.test/me/calendars?$skip=100'],
        ]);

        self::assertEquals([
            new CalendarDescriptor('AAMk-default=', 'Calendar', true),
            new CalendarDescriptor('AAMk-other=', 'AAMk-other=', false),
            new CalendarDescriptor('AAMk-third=', 'Holidays', false),
        ], $reader->calendars(new CalendarCredential('t')));
        self::assertCount(2, $this->calls, 'a next link off the API is not followed');
        self::assertSame(['$select' => 'id,name,isDefaultCalendar', '$top' => '50'], $this->query(0));
    }

    public function testReadsEventsOfAnotherCalendarAndFollowsNextLinksUnderTheApi(): void
    {
        $item   = ['id' => 'e1', 'start' => ['dateTime' => '2026-11-12T09:00:00.0000000', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2026-11-12T10:00:00.0000000', 'timeZone' => 'UTC']];
        $reader = $this->reader([
            ['value' => [$item], '@odata.nextLink' => MicrosoftCalendarEventSource::API_BASE . 'calendars(\'AAMk-other=\')/calendarView?$skip=100'],
            ['value' => [$item]],
        ]);

        $events = $reader->events(new CalendarCredential('t'), new DateTimeImmutable('2026-11-12T00:00:00Z'), new DateTimeImmutable('2026-11-13T00:00:00Z'), 'AAMk-other=');
        self::assertInstanceOf(Generator::class, $events);
        self::assertCount(2, iterator_to_array($events, false));
        self::assertNull($events->getReturn());
        self::assertStringStartsWith(MicrosoftCalendarEventSource::API_BASE . 'calendars/AAMk-other%3D/calendarView?', $this->calls[0]['url']);
        self::assertSame(MicrosoftCalendarEventSource::SELECT, $this->query(0)['$select'] ?? null);
        self::assertSame('outlook.timezone="UTC"', $this->calls[0]['prefer']);
    }

    public function testMeetingsWithReadBasicLeaveTheBodyOut(): void
    {
        $reader = $this->reader([['value' => [[
            'id'                    => 'occ-1',
            'subject'               => 'Fake review',
            'body'                  => ['content' => 'never asked for'],
            'location'              => ['displayName' => 'Room 2'],
            'start'                 => ['dateTime' => '2026-11-12T09:00:00.0000000', 'timeZone' => 'UTC'],
            'end'                   => ['dateTime' => '2026-11-12T09:30:00.0000000', 'timeZone' => 'UTC'],
            'isAllDay'              => false,
            'isCancelled'           => false,
            'sensitivity'           => 'private',
            'showAs'                => 'busy',
            'type'                  => 'occurrence',
            'seriesMasterId'        => 'master-1',
            'originalStart'         => '2026-11-12T09:00:00Z',
            'originalStartTimeZone' => 'Romance Standard Time',
            'responseStatus'        => ['response' => 'accepted'],
            'organizer'             => ['emailAddress' => ['address' => 'Boss@Example.TEST']],
            'isOrganizer'           => false,
            'attendees'             => [
                ['emailAddress' => ['address' => 'Peer@Example.test'], 'status' => ['response' => 'declined'], 'type' => 'required'],
                ['emailAddress' => ['address' => 'room@example.test'], 'type' => 'resource'],
                'not an attendee',
            ],
            'onlineMeeting' => ['joinUrl' => 'https://teams.microsoft.com/l/meetup-join/fake'],
        ]]]]);

        $batch = $reader->meetings(new CalendarCredential('t'), new DateTimeImmutable('2026-11-12T00:00:00Z'), new DateTimeImmutable('2026-11-13T00:00:00Z'));

        self::assertStringStartsWith(MicrosoftCalendarEventSource::BASE_URI . '?', $this->calls[0]['url']);
        self::assertSame(MicrosoftCalendarEventSource::MEETING_SELECT, $this->query(0)['$select'] ?? null);
        self::assertSame('outlook.timezone="UTC"', $this->calls[0]['prefer']);
        self::assertTrue($batch->complete);
        self::assertCount(1, $batch->meetings);
        $meeting = $batch->meetings[0];
        self::assertSame(['occ-1', 'master-1', '2026-11-12T09:00:00Z', 'Fake review', 'Room 2', null], [$meeting->eventId, $meeting->seriesId, $meeting->originalStart, $meeting->title, $meeting->location, $meeting->timeZone]);
        self::assertSame('never asked for', $meeting->description, 'a body the provider sends anyway is mapped as given');
        self::assertSame([false, false, false, false, true], [$meeting->allDay, $meeting->cancelled, $meeting->declined, $meeting->free, $meeting->private]);
        self::assertSame(['boss@example.test', false], [$meeting->organizerEmail, $meeting->organizerSelf]);
        self::assertEquals([new CalendarAttendee('peer@example.test', false, true, false), new CalendarAttendee('room@example.test', false, false, true)], $meeting->attendees);
        self::assertSame(['https://teams.microsoft.com/l/meetup-join/fake'], $meeting->joinCandidates);
    }

    public function testMeetingsWithReadAskForTheBodyAsText(): void
    {
        $reader = $this->reader([['value' => [
            ['id' => 'master', 'type' => 'seriesMaster', 'start' => ['dateTime' => '2026-11-12T09:00:00', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2026-11-12T10:00:00', 'timeZone' => 'UTC']],
            ['id' => 'single', 'subject' => 'Sync', 'body' => ['content' => 'TASK-9'], 'originalStartTimeZone' => 'Europe/Madrid', 'isAllDay' => true, 'isCancelled' => true, 'showAs' => 'free', 'isOrganizer' => true, 'responseStatus' => ['response' => 'declined'], 'start' => ['dateTime' => '2026-11-12T00:00:00', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2026-11-13T00:00:00', 'timeZone' => 'UTC']],
            ['id' => 'bad-zone', 'start' => ['dateTime' => '2026-11-12T09:00:00', 'timeZone' => 'Pacific Standard Time'], 'end' => ['dateTime' => '2026-11-12T10:00:00', 'timeZone' => 'UTC']],
        ], '@odata.nextLink' => MicrosoftCalendarEventSource::BASE_URI . '?$skip=100'], ['value' => [], '@odata.nextLink' => MicrosoftCalendarEventSource::BASE_URI . '?$skip=200']], 2, MicrosoftCalendarEventSource::SCOPE_READ);

        $batch = $reader->meetings(new CalendarCredential('t'), new DateTimeImmutable('2026-11-12T00:00:00Z'), new DateTimeImmutable('2026-11-13T00:00:00Z'));

        self::assertSame(MicrosoftCalendarEventSource::MEETING_SELECT . ',body', $this->query(0)['$select'] ?? null);
        self::assertSame('outlook.timezone="UTC", outlook.body-content-type="text"', $this->calls[0]['prefer']);
        self::assertFalse($batch->complete, 'stopped at the page cap');
        self::assertCount(1, $batch->meetings);
        $meeting = $batch->meetings[0];
        self::assertSame(['single', null, null, 'TASK-9', '', 'Europe/Madrid'], [$meeting->eventId, $meeting->seriesId, $meeting->originalStart, $meeting->description, $meeting->location, $meeting->timeZone]);
        self::assertSame([true, true, true, true, false, true, null, []], [$meeting->allDay, $meeting->cancelled, $meeting->declined, $meeting->free, $meeting->private, $meeting->organizerSelf, $meeting->organizerEmail, $meeting->joinCandidates]);
    }

    public function testRefusesAnUnknownScope(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MicrosoftCalendarEventSource(new CalendarHttpClient(new MockHttpClient()), 10, 'Calendars.ReadWrite');
    }

    /**
     * @param list<array<mixed>> $pages
     */
    private function reader(array $pages, int $maxPages = 10, string $scope = MicrosoftCalendarEventSource::SCOPE_READ_BASIC): MicrosoftCalendarEventSource
    {
        $this->calls = [];
        $http        = new MockHttpClient(function (string $method, string $url, array $options) use ($pages): MockResponse {
            $prefer = '';
            foreach ($options['normalized_headers']['prefer'] ?? [] as $header) {
                $prefer = (string) preg_replace('/^Prefer: /i', '', (string) $header);
            }
            $this->calls[] = ['url' => $url, 'prefer' => $prefer];

            return new MockResponse(json_encode($pages[count($this->calls) - 1] ?? ['value' => []], JSON_THROW_ON_ERROR));
        });

        return new MicrosoftCalendarEventSource(new CalendarHttpClient($http), $maxPages, $scope);
    }

    /**
     * @return array<mixed>
     */
    private function query(int $call): array
    {
        parse_str((string) parse_url($this->calls[$call]['url'], PHP_URL_QUERY), $query);

        return $query;
    }
}
