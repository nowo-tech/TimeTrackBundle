<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Meeting;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use Nowo\TimeTrackBundle\Meeting\MeetingAttendeeView;
use Nowo\TimeTrackBundle\Meeting\MeetingLinkDetector;
use Nowo\TimeTrackBundle\Meeting\MeetingPage;
use Nowo\TimeTrackBundle\Meeting\MeetingQuery;
use Nowo\TimeTrackBundle\Meeting\MeetingResponder;
use Nowo\TimeTrackBundle\Meeting\MeetingSyncState;
use Nowo\TimeTrackBundle\Meeting\MeetingSyncStatus;
use Nowo\TimeTrackBundle\Meeting\MeetingTaskLink;
use Nowo\TimeTrackBundle\Meeting\MeetingView;
use Nowo\TimeTrackBundle\Tests\Stub\RecordingTaskAccessChecker;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\MeetingFixtures;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_fill;
use function array_keys;
use function mb_strlen;
use function print_r;
use function serialize;
use function str_repeat;

/**
 * Since 1.14 (contract §22.4, AC-25, AC-26): the payload whitelist, private events, cleaning, ordering and bounds,
 * whatever the host provider returns.
 */
final class MeetingResponderTest extends TestCase
{
    public function testTheWhitelistInItsOrder(): void
    {
        $result = $this->respond([MeetingFixtures::meeting('mtg_aaaaaaaaaaaaaaaa', '2026-11-05T09:00:00Z')]);
        $first  = $result['meetings'][0];

        self::assertSame(['id', 'start', 'end', 'timeZone', 'allDay', 'date', 'busy', 'private', 'title', 'joinUrl', 'joinProvider', 'organizerIsMe', 'attendees', 'externalCount', 'taskLinks', 'updatedAt'], array_keys($first));
        self::assertSame(['2026-11-05T09:00:00Z', '2026-11-05T09:45:00Z', 'Europe/Madrid', null, 'meet', [['id' => 'u-luis', 'name' => 'Luis ' . MeetingFixtures::NAME_MARKER]], 2, [['taskId' => 'task-1', 'key' => '#task-1']], '2026-11-05T06:58:41Z'], [
            $first['start'], $first['end'], $first['timeZone'], $first['date'], $first['joinProvider'], $first['attendees'], $first['externalCount'], $first['taskLinks'], $first['updatedAt'],
        ]);
        self::assertSame(['truncated' => false, 'dropped' => 0], ['truncated' => $result['truncated'], 'dropped' => $result['dropped']]);
    }

    public function testPrivateEventsAreClearedEvenWhenTheHostLeaksThem(): void
    {
        $first = $this->respond([MeetingFixtures::meeting('mtg_aaaaaaaaaaaaaaaa', '2026-11-05T09:00:00Z', 45, ['private' => true])])['meetings'][0];

        self::assertSame([true, null, [], null, [], MeetingFixtures::LINK, 'meet'], [$first['private'], $first['title'], $first['attendees'], $first['externalCount'], $first['taskLinks'], $first['joinUrl'], $first['joinProvider']], 'Q-B16: the owner keeps the join link');
    }

    public function testCleaningAndBounds(): void
    {
        $attendees   = array_fill(0, 60, new MeetingAttendeeView('u-x', 'X'));
        $attendees[] = new MeetingAttendeeView('7', 'Me');
        $meeting     = MeetingFixtures::meeting('mtg_aaaaaaaaaaaaaaaa', '2026-11-05T09:00:00Z', 45, [
            'title'         => "  Daily\u{202E}\n stand-up\u{200B} " . str_repeat('a', 300),
            'timeZone'      => 'Mars/Base',
            'joinUrl'       => 'http://meet.google.com/abc',
            'externalCount' => -3,
            'attendees'     => [new MeetingAttendeeView('7', 'Me'), new MeetingAttendeeView('', 'Nobody'), new MeetingAttendeeView(str_repeat('i', 65), 'Long id'), new MeetingAttendeeView('u-blank', " \u{202E} "), ...$attendees],
            'taskLinks'     => [new MeetingTaskLink('task-1', '#task-1'), new MeetingTaskLink('task-1', '#again'), new MeetingTaskLink('task-9', '#task-9'), new MeetingTaskLink('', '#x'), new MeetingTaskLink('task-2', ''), new MeetingTaskLink('task-2', str_repeat('k', 33)), new MeetingTaskLink(str_repeat('t', 37), '#t'), new MeetingTaskLink('task-2', '#task-2')],
        ]);
        $first = $this->respond([$meeting, MeetingFixtures::meeting('mtg_bbbbbbbbbbbbbbbb', '2026-11-05T10:00:00Z', 30, ['title' => " \u{0007} ", 'taskLinks' => [new MeetingTaskLink('task-9', '#task-9')]])])['meetings'];

        self::assertSame(200, mb_strlen((string) $first[0]['title']));
        self::assertStringStartsWith('Daily stand-up a', (string) $first[0]['title']);
        self::assertSame([null, null, null, null], [$first[0]['timeZone'], $first[0]['joinUrl'], $first[0]['joinProvider'], $first[0]['externalCount'] === 0 ? null : 'x']);
        self::assertCount(50, $first[0]['attendees']);
        self::assertNotContains('7', array_column($first[0]['attendees'], 'id'), 'the caller is not listed');
        self::assertSame([['taskId' => 'task-1', 'key' => '#task-1'], ['taskId' => 'task-2', 'key' => '#task-2']], $first[0]['taskLinks'], 'untrackable tasks are left out');
        self::assertNull($first[1]['title']);
        self::assertNull($this->respond([MeetingFixtures::meeting('mtg_aaaaaaaaaaaaaaaa', '2026-11-05T09:00:00Z', 45, ['title' => null])])['meetings'][0]['title']);
        self::assertSame([], $first[1]['taskLinks']);
    }

    public function testDropsOrdersAndCuts(): void
    {
        $meetings = [
            MeetingFixtures::meeting('mtg_cccccccccccccccc', '2026-11-05T11:00:00Z'),
            MeetingFixtures::meeting('mtg_bbbbbbbbbbbbbbbb', '2026-11-05T09:00:00Z'),
            MeetingFixtures::meeting('mtg_aaaaaaaaaaaaaaaa', '2026-11-05T09:00:00Z'),
            MeetingFixtures::meeting('bad id', '2026-11-05T09:00:00Z'),
            MeetingFixtures::meeting('mtg_short', '2026-11-05T09:00:00Z'),
            MeetingFixtures::meeting('mtg_dddddddddddddddd', '2026-11-05T09:00:00Z', 0),
            MeetingFixtures::meeting('mtg_eeeeeeeeeeeeeeee', '2026-11-05T09:00:00Z', 24 * 60 + 1),
            MeetingFixtures::meeting('mtg_ffffffffffffffff', '2026-11-06T08:00:00Z'),
            MeetingFixtures::meeting('mtg_gggggggggggggggg', '2026-11-05T06:00:00Z', 60),
        ];
        $result = $this->respond($meetings, 2);

        self::assertSame(['mtg_aaaaaaaaaaaaaaaa', 'mtg_bbbbbbbbbbbbbbbb'], array_column($result['meetings'], 'id'));
        self::assertTrue($result['truncated']);
        self::assertSame(6, $result['dropped'], 'bad ids, empty, longer than 24 h and outside the window');
        self::assertTrue($this->respond([], 10, true)['truncated'], 'the provider stopped early');
    }

    public function testAllDayMeetingsAreLocalDays(): void
    {
        $meeting = MeetingFixtures::meeting('mtg_aaaaaaaaaaaaaaaa', '2026-11-05T00:00:00Z', 24 * 60, ['allDay' => true]);
        $first   = $this->respond([$meeting], 10, false, 'Europe/Madrid')['meetings'][0];

        self::assertSame([true, '2026-11-05', '2026-11-04T23:00:00Z', '2026-11-06T23:00:00Z'], [$first['allDay'], $first['date'], $first['start'], $first['end']]);
        $midnight = MeetingFixtures::meeting('mtg_aaaaaaaaaaaaaaaa', '2026-11-04T23:00:00Z', 24 * 60, ['allDay' => true]);
        $second   = $this->respond([$midnight], 10, false, 'Europe/Madrid')['meetings'][0];
        self::assertSame(['2026-11-05', '2026-11-04T23:00:00Z', '2026-11-05T23:00:00Z'], [$second['date'], $second['start'], $second['end']]);
    }

    public function testTheViewNeverLeaks(): void
    {
        $view = MeetingFixtures::meeting('mtg_aaaaaaaaaaaaaaaa', '2026-11-05T09:00:00Z');

        $debug = print_r($view, true);
        foreach ([MeetingFixtures::TITLE_MARKER, MeetingFixtures::NAME_MARKER, 'ZZPASSCODEMARKER'] as $marker) {
            self::assertStringNotContainsString($marker, $debug);
        }
        foreach ([static fn (): string => serialize($view), static function () use ($view): void {
            $view->__unserialize([]);
        }] as $operation) {
            try {
                $operation();
                self::fail('expected a LogicException');
            } catch (LogicException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /**
     * @param list<MeetingView> $meetings
     *
     * @return array{meetings: list<array<string, mixed>>, truncated: bool, dropped: int}
     */
    private function respond(array $meetings, int $limit = 100, bool $truncated = false, string $tz = 'UTC'): array
    {
        $query = new MeetingQuery(new DateTimeImmutable('2026-11-05T07:00:00Z'), new DateTimeImmutable('2026-11-06T07:00:00Z'), new DateTimeZone($tz), $limit);
        $page  = new MeetingPage($meetings, new MeetingSyncState(MeetingSyncStatus::Connected), $truncated);

        return (new MeetingResponder(new MeetingLinkDetector(), new RecordingTaskAccessChecker(['task-9'])))->respond(new TestUser('7', 'me@example.com'), $query, $page);
    }
}
