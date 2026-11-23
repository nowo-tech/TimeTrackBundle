<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Entry;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Entry\EntryMetadataRules;
use Nowo\TimeTrackBundle\Entry\ManualTimeEntryDraft;
use Nowo\TimeTrackBundle\Entry\TimeEntryCorrection;
use Nowo\TimeTrackBundle\Entry\TimeEntryCorrectionResult;
use Nowo\TimeTrackBundle\Entry\TimeEntryOperations;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Exception\TimeEntryCorrectionException;
use Nowo\TimeTrackBundle\Query\TimeEntryRecord;
use Nowo\TimeTrackBundle\Repository\OverlappingTimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Security\TaskProviderTaskAccessChecker;
use Nowo\TimeTrackBundle\Service\ManualEntryService;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Support\EntryOverlap;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\CorrectableInMemoryTimeEntryRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryActiveTimerRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function array_fill_keys;
use function range;
use function str_repeat;

use const INF;
use const NAN;

/**
 * 1.40.1 (audit ZAN, follow-up of 1.40.0): the draft validates its allowed age and metadata (ZAN-3), the metadata of an
 * entry cannot grow past the limits through a correction (ZAN-4), a new start older than the allowed age is refused
 * (ZAN-2), a note or duration fix of an entry longer than 24 h works (ZAN-I3), and the overlap check reads a store
 * without a lock as before.
 */
final class EntryWritesHardeningTest extends TestCase
{
    private CorrectableInMemoryTimeEntryRepository $entries;

    private InMemoryActiveTimerRepository $timers;

    private MockClock $clock;

    private TestUser $ana;

    protected function setUp(): void
    {
        $this->entries = new CorrectableInMemoryTimeEntryRepository();
        $this->timers  = new InMemoryActiveTimerRepository();
        $this->clock   = new MockClock('2026-11-12 10:00:00 UTC');
        $this->ana     = new TestUser('7', 'ana');
    }

    /**
     * ZAN-3: the draft refuses an allowed age out of 0..3650 and the metadata the corrections refuse.
     */
    public function testTheDraftValidatesItsAgeAndMetadata(): void
    {
        $start = new DateTimeImmutable('2026-11-11 09:00 UTC');
        $end   = $start->modify('+1 hour');
        foreach ([
            [-1, []], [3651, []], [null, ['closed_by' => 'x']], [null, ['Bad' => 'x']], [null, ['k' => ['nested']]],
            [null, ['k' => null]], [null, ['k'        => NAN]], [null, ['k' => INF]], [null, ['k' => "\xC3\x28"]],
            [null, ['k' => str_repeat('a', 2001)]], [null, array_fill_keys($this->keys(33), 1)],
            [null, array_fill_keys($this->keys(9), str_repeat('a', 2000))],
        ] as $i => [$age, $metadata]) {
            try {
                // @phpstan-ignore argument.type (bad input on purpose)
                new ManualTimeEntryDraft('task-1', $start, $end, metadata: $metadata, maxAgeDays: $age);
                self::fail('accepted case ' . $i);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $draft = new ManualTimeEntryDraft('task-1', $start, $end, metadata: array_fill_keys($this->keys(32), 1.5), maxAgeDays: 3650);
        self::assertSame(3650, $draft->maxAgeDays);
        self::assertSame(0, (new ManualTimeEntryDraft('task-1', $start, $end, maxAgeDays: 0))->maxAgeDays);
    }

    /**
     * ZAN-4: a patch holds at most 32 keys; the stored metadata may not grow past 32 keys or 16 KB through a correction,
     * but an entry stored larger before the limits may still shrink.
     */
    public function testTheMetadataCannotGrowPastTheLimits(): void
    {
        try {
            new TimeEntryCorrection(metadata: array_fill_keys($this->keys(33), 1));
            self::fail('33 keys accepted');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $many = $this->entry('2026-11-10 09:00', '2026-11-10 10:00', array_fill_keys($this->keys(30), true));
        $this->refused(TimeEntryCorrectionException::METADATA_TOO_LARGE, fn (): TimeEntryCorrectionResult => $this->ops()->correct('7', $many->getId(), $this->version($many), new TimeEntryCorrection(metadata: ['x1' => 1, 'x2' => 2, 'x3' => 3])));
        self::assertTrue($this->ops()->correct('7', $many->getId(), $this->version($many), new TimeEntryCorrection(metadata: ['x1' => 1, 'x2' => 2]))->changed, 'exactly 32 keys');

        $big = $this->entry('2026-11-10 11:00', '2026-11-10 12:00', array_fill_keys($this->keys(9), str_repeat('b', 2000)));
        $this->refused(TimeEntryCorrectionException::METADATA_TOO_LARGE, fn (): TimeEntryCorrectionResult => $this->ops()->correct('7', $big->getId(), $this->version($big), new TimeEntryCorrection(metadata: ['extra' => 'x'])));
        self::assertTrue($this->ops()->correct('7', $big->getId(), $this->version($big), new TimeEntryCorrection(metadata: ['k_1' => null]))->changed, 'a legacy entry may shrink');
        self::assertTrue($this->ops()->correct('7', $big->getId(), $this->version($big), new TimeEntryCorrection(metadata: ['k_2' => 'short']))->changed, 'or get a smaller value');
        self::assertSame(422, (new TimeEntryCorrectionException(TimeEntryCorrectionException::METADATA_TOO_LARGE))->httpStatus());
    }

    /**
     * ZAN-2: a new start older than the allowed age (default `manual_entries.max_age_days`) is refused; an explicit age
     * widens or narrows it; an old entry keeps getting note fixes.
     */
    public function testANewStartOlderThanTheAllowedAgeIsRefused(): void
    {
        $entry = $this->entry('2026-11-10 09:00', '2026-11-10 10:00');
        $back  = TimeEntryCorrection::times(new DateTimeImmutable('2026-10-01 09:00 UTC'), new DateTimeImmutable('2026-10-01 10:00 UTC'));

        $this->refused(TimeEntryCorrectionException::TOO_OLD, fn (): TimeEntryCorrectionResult => $this->ops()->correct('7', $entry->getId(), $this->version($entry), $back));
        $this->refused(TimeEntryCorrectionException::TOO_OLD, fn (): TimeEntryCorrectionResult => $this->ops(maxAgeDays: 60)->correct('7', $entry->getId(), $this->version($entry), new TimeEntryCorrection(new DateTimeImmutable('2026-11-10 08:00 UTC'), maxAgeDays: 1)));
        self::assertTrue($this->ops(maxAgeDays: 60)->correct('7', $entry->getId(), $this->version($entry), $back)->changed, 'the configured age');
        self::assertTrue($this->ops()->correct('7', $entry->getId(), $this->version($entry), (new TimeEntryCorrection(metadata: [], maxAgeDays: 31))->withNote('old but fixable'))->changed, 'no start change: no age check');
        self::assertSame(31, (new TimeEntryCorrection(maxAgeDays: 31))->withNote('x')->maxAgeDays);

        $this->expectException(InvalidArgumentException::class);
        new TimeEntryCorrection(maxAgeDays: -1);
    }

    /**
     * ZAN-I3: an entry longer than 24 h (a forgotten timer) still gets a note or a duration fix; changing its times is
     * checked as usual.
     */
    public function testALongForgottenTimerEntryGetsANoteAndADurationFix(): void
    {
        $entry = $this->entry('2026-11-09 08:00', '2026-11-10 12:00');

        self::assertTrue($this->ops()->correct('7', $entry->getId(), $this->version($entry), (new TimeEntryCorrection())->withNote('forgot to stop'))->changed);
        self::assertSame(8 * 3600, $this->ops()->correct('7', $entry->getId(), $this->version($entry), new TimeEntryCorrection(durationSeconds: 8 * 3600))->after->entry->durationSeconds);
        $this->refused(TimeEntryCorrectionException::TOO_LONG, fn (): TimeEntryCorrectionResult => $this->ops()->correct('7', $entry->getId(), $this->version($entry), new TimeEntryCorrection(endedAt: new DateTimeImmutable('2026-11-10 13:00 UTC'))));
    }

    public function testMetadataRulesHelpers(): void
    {
        self::assertTrue(EntryMetadataRules::fits([]));
        self::assertFalse(EntryMetadataRules::fits(array_fill_keys($this->keys(33), 1)));
        self::assertFalse(EntryMetadataRules::grewBeyondLimits([], ['a' => 1]));
        self::assertTrue(EntryMetadataRules::grewBeyondLimits(array_fill_keys($this->keys(32), 1), array_fill_keys($this->keys(33), 1)));
        self::assertFalse(EntryMetadataRules::grewBeyondLimits(array_fill_keys($this->keys(34), 1), array_fill_keys($this->keys(33), 1)));
    }

    /**
     * A store with the overlap query but no shared-lock variant is read as before.
     */
    public function testAnOverlapStoreWithoutLocksIsReadAsBefore(): void
    {
        $other = new TimeEntry($this->ana, 'task-1', 'Task', null, new DateTimeImmutable('2026-11-10 09:00 UTC'), new DateTimeImmutable('2026-11-10 10:00 UTC'), 3600, TimeEntrySource::Web);
        $store = new class([$other]) implements TimeEntryRepositoryInterface, OverlappingTimeEntryRepositoryInterface {
            /**
             * @param list<TimeEntry> $rows
             */
            public function __construct(public array $rows)
            {
            }

            public function save(TimeEntry $entry): void
            {
            }

            public function findByUserAndPeriod(string $userId, DateTimeImmutable $from, DateTimeImmutable $to): array
            {
                return [];
            }

            public function findLiveOverlapping(string $userId, DateTimeImmutable $from, DateTimeImmutable $to): array
            {
                return $this->rows;
            }
        };

        self::assertTrue(EntryOverlap::exists($store, $this->timers, '7', new DateTimeImmutable('2026-11-10 09:30 UTC'), new DateTimeImmutable('2026-11-10 11:00 UTC')));
        self::assertFalse(EntryOverlap::exists($store, $this->timers, '7', new DateTimeImmutable('2026-11-10 09:30 UTC'), new DateTimeImmutable('2026-11-10 11:00 UTC'), $other->getId()));
    }

    /**
     * @return list<string>
     */
    private function keys(int $count): array
    {
        $keys = [];
        foreach (range(1, $count) as $i) {
            $keys[] = 'k_' . $i;
        }

        return $keys;
    }

    private function ops(int $maxAgeDays = 31): TimeEntryOperations
    {
        $tasks  = new StubTaskProvider();
        $manual = new ManualEntryService(new TimerService($tasks, $this->timers, $this->entries, new EventDispatcher(), $this->clock), $this->entries, $this->timers, new TaskProviderTaskAccessChecker($tasks), clock: $this->clock);

        return new TimeEntryOperations($this->entries, $this->timers, $manual, $this->createStub(EntityManagerInterface::class), TestUser::class, clock: $this->clock, maxAgeDays: $maxAgeDays);
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    private function entry(string $start, string $end, ?array $metadata = null): TimeEntry
    {
        $startedAt = new DateTimeImmutable($start . ' UTC');
        $endedAt   = new DateTimeImmutable($end . ' UTC');
        $entry     = new TimeEntry($this->ana, 'task-1', 'Task', 'board-1', $startedAt, $endedAt, $endedAt->getTimestamp() - $startedAt->getTimestamp(), TimeEntrySource::Web, $metadata);
        $this->entries->save($entry);

        return $entry;
    }

    private function version(TimeEntry $entry): string
    {
        return TimeEntryRecord::fromEntry($entry)->version();
    }

    private function refused(string $code, callable $call): void
    {
        try {
            $call();
            self::fail('not refused: ' . $code);
        } catch (TimeEntryCorrectionException $exception) {
            self::assertSame($code, $exception->errorCode);
        }
    }
}
