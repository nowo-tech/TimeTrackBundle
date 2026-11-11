<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use Generator;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\Calendar\CalendarAttendee;
use Nowo\TimeTrackBundle\Calendar\CalendarCredential;
use Nowo\TimeTrackBundle\Calendar\CalendarDescriptor;
use Nowo\TimeTrackBundle\Calendar\CalendarHttpClient;
use Nowo\TimeTrackBundle\Calendar\GoogleCalendarEventSource;
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
 * Since 1.19 (Beacon request 2026-11-09, spec 210): the Google reader lists calendars, reads any calendar and maps
 * meetings from hand-written fake payloads (no real calendar data).
 */
final class GoogleCalendarReaderTest extends TestCase
{
    /** @var list<string> */
    private array $urls = [];

    public function testListsCalendarsOverPages(): void
    {
        $reader = $this->reader([
            ['items' => [
                ['id' => 'primary-id@example.test', 'summary' => 'Me', 'primary' => true],
                ['id' => 'team@group.example.test', 'summary' => 'Team', 'summaryOverride' => 'My team'],
                ['summary' => 'no id'],
                'not a calendar',
            ], 'nextPageToken' => 'p2'],
            ['items' => [['id' => 'unnamed@group.example.test']]],
        ]);

        self::assertEquals([
            new CalendarDescriptor('primary-id@example.test', 'Me', true),
            new CalendarDescriptor('team@group.example.test', 'My team', false),
            new CalendarDescriptor('unnamed@group.example.test', 'unnamed@group.example.test', false),
        ], $reader->calendars(new CalendarCredential('t')));
        self::assertCount(2, $this->urls);
        $query = $this->query(0);
        self::assertStringStartsWith(GoogleCalendarEventSource::CALENDAR_LIST_URI . '?', $this->urls[0]);
        self::assertSame(['minAccessRole' => 'reader', 'maxResults' => '250', 'fields' => GoogleCalendarEventSource::CALENDAR_FIELDS], $query);
        self::assertSame('p2', $this->query(1)['pageToken'] ?? null);
    }

    public function testReadsEventsOfAnotherCalendar(): void
    {
        $reader = $this->reader([['items' => [['id' => 'e1', 'start' => ['dateTime' => '2026-11-12T09:00:00Z'], 'end' => ['dateTime' => '2026-11-12T10:00:00Z']]]]]);

        $events = $reader->events(new CalendarCredential('t'), new DateTimeImmutable('2026-11-12T00:00:00Z'), new DateTimeImmutable('2026-11-13T00:00:00Z'), 'team@group.example.test');
        self::assertInstanceOf(Generator::class, $events);
        self::assertCount(1, iterator_to_array($events, false));
        self::assertNull($events->getReturn(), 'read in full');
        self::assertStringStartsWith(GoogleCalendarEventSource::API_BASE . 'calendars/team%40group.example.test/events?', $this->urls[0]);
        self::assertSame(GoogleCalendarEventSource::FIELDS, $this->query(0)['fields'] ?? null);
    }

    public function testMapsMeetingsAndSkipsNonMeetings(): void
    {
        $reader = $this->reader([['items' => [
            [
                'id'                => 'occ-1',
                'status'            => 'confirmed',
                'summary'           => 'Fake planning #TASK-1',
                'description'       => 'Agenda TASK-2',
                'location'          => 'Room 1',
                'start'             => ['dateTime' => '2026-11-12T09:00:00+01:00', 'timeZone' => 'Europe/Madrid'],
                'end'               => ['dateTime' => '2026-11-12T10:00:00+01:00', 'timeZone' => 'Europe/Madrid'],
                'recurringEventId'  => 'series-1',
                'originalStartTime' => ['dateTime' => '2026-11-12T08:00:00+01:00'],
                'visibility'        => 'confidential',
                'transparency'      => 'transparent',
                'eventType'         => 'default',
                'organizer'         => ['email' => 'Boss@Example.TEST', 'self' => false],
                'attendees'         => [
                    ['email' => 'Me@Example.test', 'self' => true, 'responseStatus' => 'declined'],
                    ['email' => 'room@resource.example.test', 'resource' => true, 'responseStatus' => 'accepted'],
                    ['responseStatus' => 'needsAction'],
                    'not an attendee',
                ],
                'hangoutLink'    => 'https://meet.google.com/aaa-bbbb-ccc',
                'conferenceData' => ['entryPoints' => [
                    ['entryPointType' => 'video', 'uri' => 'https://meet.google.com/aaa-bbbb-ccc'],
                    ['entryPointType' => 'phone', 'uri' => 'tel:+34-000'],
                    ['entryPointType' => 'video', 'uri' => 'https://zoom.us/j/1'],
                    'not an entry point',
                ]],
            ],
            ['id' => 'all-day', 'start' => ['date' => '2026-11-12'], 'end' => ['date' => '2026-11-13'], 'originalStartTime' => ['date' => '2026-11-12'], 'status' => 'cancelled', 'organizer' => ['email' => 'me@example.test', 'self' => true], 'start_zone' => 'x'],
            ['id' => 'focus', 'eventType' => 'focusTime', 'start' => ['dateTime' => '2026-11-12T11:00:00Z'], 'end' => ['dateTime' => '2026-11-12T12:00:00Z']],
            ['id' => 'ooo', 'eventType' => 'outOfOffice', 'start' => ['dateTime' => '2026-11-12T11:00:00Z'], 'end' => ['dateTime' => '2026-11-12T12:00:00Z']],
            ['id' => 'broken', 'start' => ['dateTime' => 'later'], 'end' => ['dateTime' => '2026-11-12T12:00:00Z']],
            ['id' => '', 'start' => ['dateTime' => '2026-11-12T11:00:00Z'], 'end' => ['dateTime' => '2026-11-12T12:00:00Z']],
        ]]]);

        $batch = $reader->meetings(new CalendarCredential('t'), new DateTimeImmutable('2026-11-12T00:00:00Z'), new DateTimeImmutable('2026-11-13T00:00:00Z'));

        self::assertStringStartsWith(GoogleCalendarEventSource::BASE_URI . '?', $this->urls[0]);
        self::assertSame(GoogleCalendarEventSource::MEETING_FIELDS, $this->query(0)['fields'] ?? null);
        self::assertTrue($batch->complete);
        self::assertCount(2, $batch->meetings);
        [$meeting, $allDay] = $batch->meetings;
        self::assertSame(['occ-1', 'series-1', '2026-11-12T08:00:00+01:00'], [$meeting->eventId, $meeting->seriesId, $meeting->originalStart]);
        self::assertSame(['Fake planning #TASK-1', 'Agenda TASK-2', 'Room 1', 'Europe/Madrid'], [$meeting->title, $meeting->description, $meeting->location, $meeting->timeZone]);
        self::assertSame('2026-11-12T08:00:00+00:00', $meeting->start->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM));
        self::assertSame([false, false, true, true, true], [$meeting->allDay, $meeting->cancelled, $meeting->declined, $meeting->free, $meeting->private]);
        self::assertSame(['boss@example.test', false], [$meeting->organizerEmail, $meeting->organizerSelf]);
        self::assertEquals([
            new CalendarAttendee('me@example.test', true, true, false),
            new CalendarAttendee('room@resource.example.test', false, false, true),
            new CalendarAttendee('', false, false, false),
        ], $meeting->attendees);
        self::assertSame(['https://meet.google.com/aaa-bbbb-ccc', 'https://zoom.us/j/1'], $meeting->joinCandidates);

        self::assertSame(['all-day', null, '2026-11-12', true, true, false, false], [$allDay->eventId, $allDay->seriesId, $allDay->originalStart, $allDay->allDay, $allDay->cancelled, $allDay->private, $allDay->declined]);
        self::assertSame(['me@example.test', true, null, [], [], '', ''], [$allDay->organizerEmail, $allDay->organizerSelf, $allDay->timeZone, $allDay->attendees, $allDay->joinCandidates, $allDay->title, $allDay->description]);
    }

    public function testAMeetingReadStoppedAtThePageCapIsIncomplete(): void
    {
        $reader = $this->reader([['items' => [], 'nextPageToken' => 'more'], ['items' => [], 'nextPageToken' => 'more']], 2);

        $batch = $reader->meetings(new CalendarCredential('t'), new DateTimeImmutable('-1 day'), new DateTimeImmutable(), 'team@group.example.test');

        self::assertFalse($batch->complete);
        self::assertSame([], $batch->meetings);
        self::assertCount(2, $this->urls);
    }

    public function testRefusesABadCalendarId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->reader([])->meetings(new CalendarCredential('t'), new DateTimeImmutable('-1 day'), new DateTimeImmutable(), '');
    }

    /**
     * @param list<array<mixed>> $pages
     */
    private function reader(array $pages, int $maxPages = 10): GoogleCalendarEventSource
    {
        $this->urls = [];
        $http       = new MockHttpClient(function (string $method, string $url) use ($pages): MockResponse {
            $this->urls[] = $url;

            return new MockResponse(json_encode($pages[count($this->urls) - 1] ?? ['items' => []], JSON_THROW_ON_ERROR));
        });

        return new GoogleCalendarEventSource(new CalendarHttpClient($http), $maxPages);
    }

    /**
     * @return array<mixed>
     */
    private function query(int $call): array
    {
        parse_str((string) parse_url($this->urls[$call], PHP_URL_QUERY), $query);

        return $query;
    }
}
