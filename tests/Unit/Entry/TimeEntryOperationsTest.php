<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Entry;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use LogicException;
use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Entry\ManualTimeEntryDraft;
use Nowo\TimeTrackBundle\Entry\TimeEntryCorrection;
use Nowo\TimeTrackBundle\Entry\TimeEntryCorrectionResult;
use Nowo\TimeTrackBundle\Entry\TimeEntryOperations;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Event\TimeEntryCorrectedEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Exception\ManualEntryException;
use Nowo\TimeTrackBundle\Exception\PeriodLockedException;
use Nowo\TimeTrackBundle\Exception\PeriodSubmittedException;
use Nowo\TimeTrackBundle\Exception\TimeEntryCorrectionException;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryData;
use Nowo\TimeTrackBundle\Query\TimeEntryRecord;
use Nowo\TimeTrackBundle\Repository\FindableTimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Security\TaskProviderTaskAccessChecker;
use Nowo\TimeTrackBundle\Service\ManualEntryService;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Tests\Stub\StubServiceAccountPolicy;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\CorrectableInMemoryTimeEntryRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryActiveTimerRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimesheetRepository;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use Nowo\TimeTrackBundle\Tests\Support\TimesheetFixtures;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Throwable;

use function str_repeat;
use function strtoupper;

/**
 * 1.40.0 (Beacon): {@see TimeEntryOperations} on in-memory stores — corrections scoped to the owner with the old values
 * returned, the version check, every refusal, the period lock of the old and the new start, overlaps, the event after
 * the unit, and manual entries for an owner given by reference.
 */
final class TimeEntryOperationsTest extends TestCase
{
    private CorrectableInMemoryTimeEntryRepository $entries;

    private InMemoryActiveTimerRepository $timers;

    private InMemoryTimesheetRepository $timesheets;

    private EventDispatcher $dispatcher;

    private RecordingLogger $logger;

    private MockClock $clock;

    private TestUser $ana;

    /** @var list<TimeEntryCorrectedEvent> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->entries    = new CorrectableInMemoryTimeEntryRepository();
        $this->timers     = new InMemoryActiveTimerRepository();
        $this->timesheets = new InMemoryTimesheetRepository();
        $this->dispatcher = new EventDispatcher();
        $this->logger     = new RecordingLogger();
        $this->clock      = new MockClock('2026-11-12 10:00:00 UTC');
        $this->ana        = new TestUser('7', 'ana');
        $this->dispatcher->addListener(TimeTrackEvents::TIME_ENTRY_CORRECTED, function (TimeEntryCorrectedEvent $event): void {
            $this->events[] = $event;
        });
    }

    public function testTheOwnerCorrectsTimesAndNoteAndGetsTheOldValues(): void
    {
        $entry  = $this->entry($this->ana, '2026-11-10 09:00', '2026-11-10 10:00', ['note' => 'old', 'origin' => 'web']);
        $before = TimeEntryRecord::fromEntry($entry);

        $result = $this->operations()->correct('7', $entry->getId(), $before->version(), TimeEntryCorrection::times(new DateTimeImmutable('2026-11-10 08:30 UTC'), new DateTimeImmutable('2026-11-10 10:15 UTC'))->withNote('  new note  '));

        self::assertTrue($result->changed);
        self::assertSame(['started_at', 'ended_at', 'duration_seconds', 'metadata'], $result->changedFields);
        self::assertSame([3600, 'old'], [$result->before->entry->durationSeconds, $result->before->note]);
        self::assertSame([6300, 'new note', 'web'], [$result->after->entry->durationSeconds, $result->after->note, $result->after->metadata['origin']]);
        self::assertSame($before->version(), $result->before->version(), 'before is exactly what was replaced');
        self::assertNotSame($result->before->version(), $result->after->version());
        self::assertSame($result->after->version(), TimeEntryRecord::fromEntry($entry)->version());
        self::assertSame([1, 1], [$this->entries->saves - 1, $this->entries->transactions], 'one save, in the owner\'s unit');
        self::assertCount(1, $this->events);
        self::assertSame([$entry->getId(), '7', ['started_at', 'ended_at', 'duration_seconds', 'metadata']], [$this->events[0]->entryId, $this->events[0]->ownerId, $this->events[0]->changedFields]);
        self::assertSame('2026-11-12 10:00:00', $this->events[0]->occurredAt->format('Y-m-d H:i:s'));
        self::assertNotContains('7', $this->logger->contextValues(), 'REQ-OBS-001: no user id in logs');
        self::assertNotContains($entry->getId(), $this->logger->contextValues());
        self::assertNotContains('new note', $this->logger->contextValues());
    }

    public function testDurationAndMetadataRules(): void
    {
        $entry = $this->entry($this->ana, '2026-11-10 09:00', '2026-11-10 10:00', ['note' => 'keep', 'auto_stopped' => true]);
        $ops   = $this->operations();

        // Only the duration (at most the span), then only the metadata.
        $result = $ops->correct('7', $entry->getId(), $this->version($entry), new TimeEntryCorrection(durationSeconds: 1800));
        self::assertSame([['duration_seconds'], 1800], [$result->changedFields, $entry->getDurationSeconds()]);
        $this->refused(TimeEntryCorrectionException::INVALID_DURATION, fn (): TimeEntryCorrectionResult => $ops->correct('7', $entry->getId(), $this->version($entry), new TimeEntryCorrection(durationSeconds: 3601)));
        $result = $ops->correct('7', $entry->getId(), $this->version($entry), new TimeEntryCorrection(metadata: ['auto_stopped' => null, 'auto_stop_policy' => 'night']));
        self::assertSame(['metadata'], $result->changedFields);
        self::assertSame(['note' => 'keep', 'auto_stop_policy' => 'night'], $entry->getMetadata());

        // New times with an explicit duration; then a blank note removes it and an empty patch result stores null.
        $ops->correct('7', $entry->getId(), $this->version($entry), new TimeEntryCorrection(new DateTimeImmutable('2026-11-10 09:00 UTC'), new DateTimeImmutable('2026-11-10 11:00 UTC'), 5400));
        self::assertSame([5400, '2026-11-10 11:00'], [$entry->getDurationSeconds(), $entry->getEndedAt()->format('Y-m-d H:i')]);
        $ops->correct('7', $entry->getId(), $this->version($entry), (new TimeEntryCorrection(metadata: ['auto_stop_policy' => null]))->withNote(' '));
        self::assertNull($entry->getMetadata());

        // Nothing changes: nothing written, nothing dispatched.
        $saves  = $this->entries->saves;
        $events = $this->events;
        $result = $ops->correct('7', $entry->getId(), $this->version($entry), new TimeEntryCorrection(new DateTimeImmutable('2026-11-10 09:00 UTC'), metadata: ['note' => null]));
        self::assertFalse($result->changed);
        self::assertSame($result->before, $result->after);
        self::assertSame([$saves, $events], [$this->entries->saves, $this->events]);
    }

    public function testEveryRefusalOfTheValues(): void
    {
        $entry = $this->entry($this->ana, '2026-11-10 09:00', '2026-11-10 10:00');
        $ops   = $this->operations();
        $at    = static fn (string $time): DateTimeImmutable => new DateTimeImmutable($time . ' UTC');

        $this->refused(TimeEntryCorrectionException::INVALID_RANGE, fn (): TimeEntryCorrectionResult => $ops->correct('7', $entry->getId(), $this->version($entry), new TimeEntryCorrection(endedAt: $at('2026-11-10 09:00'))));
        $this->refused(TimeEntryCorrectionException::TOO_LONG, fn (): TimeEntryCorrectionResult => $ops->correct('7', $entry->getId(), $this->version($entry), new TimeEntryCorrection(startedAt: $at('2026-11-09 09:59'))));
        $this->refused(TimeEntryCorrectionException::IN_FUTURE, fn (): TimeEntryCorrectionResult => $ops->correct('7', $entry->getId(), $this->version($entry), new TimeEntryCorrection($at('2026-11-12 09:00'), $at('2026-11-12 10:02:01'))));
        $ops->correct('7', $entry->getId(), $this->version($entry), new TimeEntryCorrection(startedAt: $at('2026-11-12 09:00'), endedAt: $at('2026-11-12 10:02')));
        self::assertSame(3720, $entry->getDurationSeconds(), 'an end within the clock skew is accepted');
        self::assertSame(0, $this->events[0]->before->entry->startedAt->getTimestamp() - $at('2026-11-10 09:00')->getTimestamp());
        self::assertSame(1, $ops->correct('7', $entry->getId(), $this->version($entry), new TimeEntryCorrection(metadata: ['k' => 1]))->after->metadata['k'], 'a stored end within the skew does not block a metadata change');
        self::assertSame([404, 403, 409, 409, 422, 422], [
            (new TimeEntryCorrectionException(TimeEntryCorrectionException::NOT_FOUND))->httpStatus(),
            (new TimeEntryCorrectionException(TimeEntryCorrectionException::READ_ONLY))->httpStatus(),
            (new TimeEntryCorrectionException(TimeEntryCorrectionException::CONFLICT))->httpStatus(),
            (new TimeEntryCorrectionException(TimeEntryCorrectionException::OVERLAP))->httpStatus(),
            (new TimeEntryCorrectionException(TimeEntryCorrectionException::INVALID_DURATION))->httpStatus(),
            (new TimeEntryCorrectionException(TimeEntryCorrectionException::INVALID_RANGE))->httpStatus(),
        ]);
    }

    public function testOnlyTheOwnerReachesTheEntryAndEveryOtherCaseIsNotFound(): void
    {
        $entry    = $this->entry($this->ana, '2026-11-10 09:00', '2026-11-10 10:00');
        $version  = $this->version($entry);
        $ops      = $this->operations();
        $nobody   = $this->entry(null, '2026-11-10 11:00', '2026-11-10 12:00');
        $mismatch = TimeTrackUserRef::of(new TestUser('8', 'bob'), TestUser::class, '7', 'ana');

        foreach ([['8', $entry->getId()], ['07', $entry->getId()], [' 7', $entry->getId()], ['', $entry->getId()], [7_0, $entry->getId()], ['7', strtoupper($entry->getId())], ['7', 'missing'], ['7', $nobody->getId()], [$mismatch, $entry->getId()]] as [$owner, $id]) {
            $this->refused(TimeEntryCorrectionException::NOT_FOUND, static fn (): TimeEntryCorrectionResult => $ops->correct($owner, $id, $version, new TimeEntryCorrection(durationSeconds: 60)));
        }
        self::assertSame(3600, $entry->getDurationSeconds());
        self::assertSame(0, $this->entries->transactions, 'refused before the owner\'s unit');

        // The owner as an integer id and as a reference with its user object.
        self::assertTrue($ops->correct(7, $entry->getId(), $version, new TimeEntryCorrection(durationSeconds: 60))->changed);
        self::assertTrue($ops->correct(TimeTrackUserRef::of($this->ana, TestUser::class, '7', 'ana'), $entry->getId(), $this->version($entry), new TimeEntryCorrection(durationSeconds: 120))->changed);
    }

    public function testImportedEntriesAreReadOnly(): void
    {
        $start    = new DateTimeImmutable('2026-11-10 09:00 UTC');
        $imported = TimeEntry::imported($this->ana, new ImportedTimeEntryData('clickup:dir', 'ext-1', 'task-1', 'Task', 'board-1', $start, $start->modify('+1 hour'), 3600, true));
        $this->entries->save($imported);

        $this->refused(TimeEntryCorrectionException::READ_ONLY, fn (): TimeEntryCorrectionResult => $this->operations()->correct('7', $imported->getId(), $this->version($imported), new TimeEntryCorrection(durationSeconds: 60)));
        $this->expectException(LogicException::class);
        $imported->applyCorrection($start, $start, 1, null);
    }

    public function testAnonymisedEntriesCannotBeCorrected(): void
    {
        $entry = $this->entry($this->ana, '2026-11-10 09:00', '2026-11-10 10:00');
        $entry->anonymiseInPlace(new DateTimeImmutable('2026-11-11'));

        $this->refused(TimeEntryCorrectionException::NOT_FOUND, fn (): TimeEntryCorrectionResult => $this->operations()->correct('7', $entry->getId(), $this->version($entry), new TimeEntryCorrection(durationSeconds: 60)));
        $this->expectException(LogicException::class);
        $entry->applyCorrection($entry->getStartedAt(), $entry->getEndedAt(), 60, null);
    }

    /**
     * REQ-RUNTIME-003: under the owner's lock the row is read again; whatever another request committed meanwhile wins
     * (a changed version is a conflict, a moved or deleted entry is not found) and nothing is written.
     */
    public function testAChangeCommittedMeanwhileIsAConflictNeverALostUpdate(): void
    {
        $entry   = $this->entry($this->ana, '2026-11-10 09:00', '2026-11-10 10:00');
        $version = $this->version($entry);
        $ops     = $this->operations();
        $this->refused(TimeEntryCorrectionException::CONFLICT, static fn (): TimeEntryCorrectionResult => $ops->correct('7', $entry->getId(), str_repeat('0', 64), new TimeEntryCorrection(durationSeconds: 60)));

        $this->entries->meanwhile = static function (TimeEntry $row): TimeEntry {
            $row->markBillable(true);

            return $row;
        };
        $this->refused(TimeEntryCorrectionException::CONFLICT, static fn (): TimeEntryCorrectionResult => $ops->correct('7', $entry->getId(), $version, new TimeEntryCorrection(durationSeconds: 60)));
        self::assertSame([3600, true], [$entry->getDurationSeconds(), $entry->getBillable()], 'the other change stays');

        $bob                      = new TestUser('8', 'bob');
        $this->entries->meanwhile = fn (TimeEntry $row): TimeEntry => $this->entry($bob, '2026-11-10 09:00', '2026-11-10 10:00');
        $this->refused(TimeEntryCorrectionException::NOT_FOUND, fn (): TimeEntryCorrectionResult => $ops->correct('7', $entry->getId(), $this->version($entry), new TimeEntryCorrection(durationSeconds: 60)));
        $this->entries->meanwhile = static fn (): ?TimeEntry => null;
        $this->refused(TimeEntryCorrectionException::NOT_FOUND, fn (): TimeEntryCorrectionResult => $ops->correct('7', $entry->getId(), $this->version($entry), new TimeEntryCorrection(durationSeconds: 60)));
        self::assertSame([], $this->events);
    }

    /**
     * The period lock of the old start and of the new one (owner decision FR-008: a locked, approved or submitted period
     * never changes).
     */
    public function testLockedAndSubmittedPeriodsAreNeverChanged(): void
    {
        $this->timesheets->save(TimesheetFixtures::locked($this->ana, 'org-a', '2026-W45'));
        $this->timesheets->save(TimesheetFixtures::submitted($this->ana, 'org-a', '2026-W44'));
        $locked = $this->entry($this->ana, '2026-11-03 09:00', '2026-11-03 10:00');
        $open   = $this->entry($this->ana, '2026-11-10 09:00', '2026-11-10 10:00');
        $ops    = $this->operations();

        $this->expectRefusal(PeriodLockedException::class, fn (): TimeEntryCorrectionResult => $ops->correct('7', $locked->getId(), $this->version($locked), new TimeEntryCorrection(durationSeconds: 60)));
        $this->expectRefusal(PeriodLockedException::class, fn (): TimeEntryCorrectionResult => $ops->correct('7', $open->getId(), $this->version($open), TimeEntryCorrection::times(new DateTimeImmutable('2026-11-08 09:00 UTC'), new DateTimeImmutable('2026-11-08 10:00 UTC'))));
        $this->expectRefusal(PeriodSubmittedException::class, fn (): TimeEntryCorrectionResult => $ops->correct('7', $open->getId(), $this->version($open), TimeEntryCorrection::times(new DateTimeImmutable('2026-10-30 09:00 UTC'), new DateTimeImmutable('2026-10-30 10:00 UTC'))));
        self::assertSame([3600, 3600, '2026-11-10'], [$locked->getDurationSeconds(), $open->getDurationSeconds(), $open->getStartedAt()->format('Y-m-d')]);

        // The end moving within an open period does not ask the new start.
        self::assertTrue($ops->correct('7', $open->getId(), $this->version($open), new TimeEntryCorrection(endedAt: new DateTimeImmutable('2026-11-10 10:30 UTC')))->changed);
    }

    public function testOverlapsWithOtherEntriesAndTheRunningTimerExceptForServiceAccounts(): void
    {
        $entry = $this->entry($this->ana, '2026-11-10 09:00', '2026-11-10 10:00');
        $this->entry($this->ana, '2026-11-10 11:00', '2026-11-10 12:00');
        $this->entry(new TestUser('8', 'bob'), '2026-11-10 10:00', '2026-11-10 11:30');
        $ops = $this->operations();

        $this->refused(TimeEntryCorrectionException::OVERLAP, fn (): TimeEntryCorrectionResult => $ops->correct('7', $entry->getId(), $this->version($entry), new TimeEntryCorrection(endedAt: new DateTimeImmutable('2026-11-10 11:30 UTC'))));
        self::assertTrue($ops->correct('7', $entry->getId(), $this->version($entry), new TimeEntryCorrection(endedAt: new DateTimeImmutable('2026-11-10 11:00 UTC')))->changed, 'touching ends, its own span and another user\'s entry do not overlap');

        $this->timers->save(new ActiveTimer($this->ana, 'task-1', 'Task', null, ClientType::Desktop, null, new DateTimeImmutable('2026-11-12 09:00 UTC')));
        $this->refused(TimeEntryCorrectionException::OVERLAP, fn (): TimeEntryCorrectionResult => $ops->correct('7', $entry->getId(), $this->version($entry), TimeEntryCorrection::times(new DateTimeImmutable('2026-11-12 08:00 UTC'), new DateTimeImmutable('2026-11-12 09:30 UTC'))));

        $agents = $this->operations(new StubServiceAccountPolicy(['7']));
        self::assertTrue($agents->correct('7', $entry->getId(), $this->version($entry), TimeEntryCorrection::times(new DateTimeImmutable('2026-11-12 08:00 UTC'), new DateTimeImmutable('2026-11-12 09:30 UTC')))->changed);
    }

    public function testAThrowingListenerIsLoggedAndDoesNotUndoTheCorrection(): void
    {
        $entry = $this->entry($this->ana, '2026-11-10 09:00', '2026-11-10 10:00');
        $this->dispatcher->addListener(TimeTrackEvents::TIME_ENTRY_CORRECTED, static function (): never {
            throw new RuntimeException('listener');
        });

        self::assertTrue($this->operations()->correct('7', $entry->getId(), $this->version($entry), new TimeEntryCorrection(durationSeconds: 60))->changed);
        self::assertSame(60, $entry->getDurationSeconds());
        self::assertContains('TimeTrack entry-corrected listener failed.', $this->logger->messages());
    }

    /**
     * A store that can find entries but has no row lock and no owner transaction: the entry is only read again.
     */
    public function testAStoreWithoutLocksReadsTheEntryAgain(): void
    {
        $store = new class implements TimeEntryRepositoryInterface, FindableTimeEntryRepositoryInterface {
            /** @var array<string, TimeEntry> */
            public array $rows = [];

            public function save(TimeEntry $entry): void
            {
                $this->rows[$entry->getId()] = $entry;
            }

            public function findByUserAndPeriod(string $userId, DateTimeImmutable $from, DateTimeImmutable $to): array
            {
                return [];
            }

            public function findById(string $id): ?TimeEntry
            {
                return $this->rows[$id] ?? null;
            }
        };
        $entry = new TimeEntry($this->ana, 'task-1', 'Task', null, new DateTimeImmutable('2026-11-10 09:00 UTC'), new DateTimeImmutable('2026-11-10 10:00 UTC'), 3600, TimeEntrySource::Web);
        $store->save($entry);

        $ops = new TimeEntryOperations($store, $this->timers, $this->manual($store), $this->createStub(EntityManagerInterface::class), TestUser::class);
        self::assertSame(60, $ops->correct('7', $entry->getId(), $this->version($entry), new TimeEntryCorrection(durationSeconds: 60))->after->entry->durationSeconds);
    }

    public function testTheCorrectionValueObjectRefusesBadInput(): void
    {
        foreach ([[0, []], [null, ['closed_by' => 'x']], [null, ['Bad' => 'x']], [null, ['ok' => str_repeat('a', 2001)]], [null, [0 => 'x']], [null, ['ok' => ['nested']]]] as [$duration, $metadata]) {
            try {
                // @phpstan-ignore argument.type (bad input on purpose)
                new TimeEntryCorrection(durationSeconds: $duration, metadata: $metadata);
                self::fail('accepted: ' . $duration);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $correction = (new TimeEntryCorrection(metadata: ['note' => 'a', 'k' => 1]))->withNote('b');
        self::assertSame(['note' => 'b', 'k' => 1], $correction->metadata);
    }

    public function testAManualEntryForAnOwnerGivenByReference(): void
    {
        $ops    = $this->operations();
        $record = $ops->createManual(TimeTrackUserRef::of($this->ana, TestUser::class, '7', 'ana'), new ManualTimeEntryDraft('task-1', new DateTimeImmutable('2026-11-11 09:00 UTC'), new DateTimeImmutable('2026-11-11 09:45 UTC'), 'demo', ['origin' => 'store_review']));

        self::assertSame(['7', 'task-1', 2700, 'demo', 'store_review', TimeEntrySource::Manual], [$record->entry->userId, $record->entry->taskId, $record->entry->durationSeconds, $record->note, $record->metadata['origin'], $record->entry->source]);
        self::assertSame($record->version(), TimeEntryRecord::fromEntry($this->entries->findById($record->entry->id) ?? self::fail())->version());

        foreach (['', ' 7', TimeTrackUserRef::of(new TestUser('8', 'bob'), TestUser::class, '7', 'ana')] as $owner) {
            try {
                $ops->createManual($owner, new ManualTimeEntryDraft('task-1', new DateTimeImmutable('2026-11-11 11:00 UTC'), new DateTimeImmutable('2026-11-11 11:30 UTC')));
                self::fail('created for an unknown owner');
            } catch (ManualEntryException $exception) {
                self::assertSame([ManualEntryException::UNKNOWN_USER, 404], [$exception->errorCode, $exception->httpStatus()]);
            }
        }
        $this->expectExceptionObject(new ManualEntryException(ManualEntryException::OVERLAP));
        $ops->createManual(TimeTrackUserRef::of($this->ana, TestUser::class, '7', 'ana'), new ManualTimeEntryDraft('task-1', new DateTimeImmutable('2026-11-11 09:30 UTC'), new DateTimeImmutable('2026-11-11 10:00 UTC')));
    }

    private function operations(?StubServiceAccountPolicy $serviceAccounts = null): TimeEntryOperations
    {
        return new TimeEntryOperations(
            $this->entries,
            $this->timers,
            $this->manual($this->entries),
            $this->createStub(EntityManagerInterface::class),
            TestUser::class,
            periodLockGuard: new PeriodLockGuard($this->timesheets),
            serviceAccounts: $serviceAccounts,
            eventDispatcher: $this->dispatcher,
            clock: $this->clock,
            logger: $this->logger,
        );
    }

    private function manual(TimeEntryRepositoryInterface $entries): ManualEntryService
    {
        $tasks = new StubTaskProvider();

        return new ManualEntryService(new TimerService($tasks, $this->timers, $entries, new EventDispatcher(), $this->clock), $entries, $this->timers, new TaskProviderTaskAccessChecker($tasks), clock: $this->clock);
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    private function entry(?TestUser $user, string $start, string $end, ?array $metadata = null): TimeEntry
    {
        $startedAt = new DateTimeImmutable($start . ' UTC');
        $endedAt   = new DateTimeImmutable($end . ' UTC');
        $entry     = new TimeEntry($user, 'task-1', 'Task', 'board-1', $startedAt, $endedAt, $endedAt->getTimestamp() - $startedAt->getTimestamp(), TimeEntrySource::Web, $metadata);
        $entry->assignScopeKey('org-a');
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

    /**
     * @param class-string<Throwable> $class
     */
    private function expectRefusal(string $class, callable $call): void
    {
        try {
            $call();
            self::fail('not refused: ' . $class);
        } catch (Throwable $exception) {
            self::assertInstanceOf($class, $exception);
        }
    }
}
