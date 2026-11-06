<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Calendar;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Calendar\CalendarEventMatcher;
use Nowo\TimeTrackBundle\Calendar\CalendarEventView;
use Nowo\TimeTrackBundle\Calendar\CalendarMatch;
use Nowo\TimeTrackBundle\Calendar\CalendarRefHasher;
use Nowo\TimeTrackBundle\Calendar\CalendarSettings;
use Nowo\TimeTrackBundle\Entity\CalendarConnection;
use Nowo\TimeTrackBundle\Entity\CalendarRule;
use Nowo\TimeTrackBundle\Enum\CalendarConnectionMode;
use Nowo\TimeTrackBundle\Enum\CalendarMatchKind;
use Nowo\TimeTrackBundle\Enum\CalendarProvider;
use Nowo\TimeTrackBundle\Tests\Stub\RecordingTaskAccessChecker;
use Nowo\TimeTrackBundle\Tests\Stub\StubTaskKeyResolver;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

use function array_map;
use function str_repeat;

/**
 * Since 1.14 (contract §6.3, Q-O6, AC-14): drop rules, task keys, user rules and the track check; unmatched events are
 * only counted.
 */
final class CalendarEventMatcherTest extends TestCase
{
    private const NOW = '2026-11-05T12:00:00Z';

    private StubTaskKeyResolver $resolver;

    private RecordingTaskAccessChecker $checker;

    protected function setUp(): void
    {
        $this->resolver = new StubTaskKeyResolver();
        $this->checker  = new RecordingTaskAccessChecker(['task-9']);
    }

    public function testDropsTheEventsThatAreNeverSuggested(): void
    {
        $at     = static fn (string $start, string $end): array => [new DateTimeImmutable($start), new DateTimeImmutable($end)];
        $events = [
            new CalendarEventView('cancelled', null, '#task-1', ...$at('2026-11-04T09:00Z', '2026-11-04T10:00Z'), cancelled: true),
            new CalendarEventView('all-day', null, '#task-1', ...$at('2026-11-04T00:00Z', '2026-11-05T00:00Z'), allDay: true),
            new CalendarEventView('free', null, '#task-1', ...$at('2026-11-04T09:00Z', '2026-11-04T10:00Z'), free: true),
            new CalendarEventView('declined', null, '#task-1', ...$at('2026-11-04T09:00Z', '2026-11-04T10:00Z'), declined: true),
            new CalendarEventView('focus', null, '#task-1', ...$at('2026-11-04T09:00Z', '2026-11-04T10:00Z'), otherKind: true),
            new CalendarEventView('empty', null, '#task-1', ...$at('2026-11-04T09:00Z', '2026-11-04T09:00Z')),
            new CalendarEventView('too-long', null, '#task-1', ...$at('2026-11-04T08:00Z', '2026-11-04T16:00:01Z')),
            new CalendarEventView('too-old', null, '#task-1', ...$at('2026-10-22T11:59Z', '2026-10-22T12:30Z')),
            new CalendarEventView('running', null, '#task-1', ...$at('2026-11-05T11:30Z', '2026-11-05T12:30Z')),
            new CalendarEventView('no-key', null, 'Lunch', ...$at('2026-11-04T12:00Z', '2026-11-04T13:00Z')),
            new CalendarEventView('kept', null, '#task-1', ...$at('2026-11-04T08:00Z', '2026-11-04T16:00Z')),
        ];

        $result = $this->matcher()->match($this->user(), $this->connection(), $events, [], new DateTimeImmutable(self::NOW));

        self::assertSame(11, $result['fetched']);
        self::assertSame(10, $result['ignored']);
        self::assertCount(1, $result['matches']);
        self::assertSame([['#task-1']], $this->resolver->resolved, 'one resolver call for every key');
    }

    public function testKeysWinInTitleOrderAndRulesCatchTheRest(): void
    {
        $day   = static fn (int $hour): array => [new DateTimeImmutable('2026-11-04T' . $hour . ':00:00Z'), new DateTimeImmutable('2026-11-04T' . $hour . ':30:00Z')];
        $user  = $this->user();
        $rules = [
            new CalendarRule($user, 'org-a', 'stand-up', 'task-2', true, new DateTimeImmutable()),
            new CalendarRule($user, 'org-a', 'sync', 'task-9', false, new DateTimeImmutable()),
            new CalendarRule($user, 'org-a', 'retro', 'task-3', false, new DateTimeImmutable()),
        ];
        $events = [
            new CalendarEventView('a', null, 'Fix #task-9 then #task-3 and #task-1', ...$day(9)),
            new CalendarEventView('b', 'series-1', 'Team STAND-UP #deadbeef', ...$day(10)),
            new CalendarEventView('c', null, 'Weekly sync', ...$day(11)),
            new CalendarEventView('d', null, 'Unknown #cafebabe', ...$day(12)),
            new CalendarEventView('e', 'series-2', 'Retro #task-1', ...$day(13)),
            new CalendarEventView('f', null, 'Sprint RETRO', ...$day(14)),
        ];

        $result = $this->matcher()->match($user, $this->connection(), $events, $rules, new DateTimeImmutable(self::NOW));

        self::assertSame(6, $result['fetched']);
        self::assertSame(2, $result['ignored'], 'task-9 is not trackable (sync rule) and #cafebabe is unknown');
        $summary = array_map(static fn (CalendarMatch $m): array => [$m->taskId, $m->kind, $m->ruleId !== null, $m->grouped, $m->seriesRef !== null], $result['matches']);
        self::assertSame([
            ['task-3', CalendarMatchKind::Key, false, false, false],
            ['task-2', CalendarMatchKind::Rule, true, true, true],
            ['task-1', CalendarMatchKind::Key, false, true, true],
            ['task-3', CalendarMatchKind::Rule, true, false, false],
        ], $summary);
        self::assertSame(['task-9', 'task-3', 'task-2', 'task-1'], $this->checker->checked, 'each task is checked once');
    }

    public function testLongTitlesAreCutBeforeTheHostSeesThem(): void
    {
        $events = [new CalendarEventView('long', null, str_repeat('x', 5000) . ' #task-1', new DateTimeImmutable('2026-11-04T09:00Z'), new DateTimeImmutable('2026-11-04T10:00Z'))];
        $user   = $this->user();
        $rules  = [new CalendarRule($user, 'org-a', '#task-1', 'task-2', false, new DateTimeImmutable())];

        $result = $this->matcher()->match($user, $this->connection(), $events, $rules, new DateTimeImmutable(self::NOW));

        self::assertSame(1, $result['ignored'], 'a key or pattern after 1024 characters is not seen');
        self::assertSame([1024], $this->resolver->titleLengths);
    }

    public function testKeyListsAreBounded(): void
    {
        $this->resolver->extraKeys = ['', str_repeat('k', 33), 42, '#k1', '#k1', '#k2', '#k3', '#k4', '#k5', '#k6'];

        self::assertSame(['#task-1', '#k1', '#k2', '#k3', '#k4'], $this->matcher()->keys('#task-1'));
    }

    private function matcher(): CalendarEventMatcher
    {
        return new CalendarEventMatcher($this->resolver, $this->checker, new CalendarRefHasher('secret'), new CalendarSettings(true));
    }

    private function user(): TestUser
    {
        return new TestUser('7', 'person@example.com');
    }

    private function connection(): CalendarConnection
    {
        return new CalendarConnection($this->user(), 'org-a', CalendarProvider::Google, CalendarConnectionMode::Server, 'cred-1', 'v1', new DateTimeImmutable(self::NOW));
    }
}
