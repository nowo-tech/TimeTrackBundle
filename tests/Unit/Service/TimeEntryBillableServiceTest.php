<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Enum\WriteKind;
use Nowo\TimeTrackBundle\Event\TimeEntryAccessCheckEvent;
use Nowo\TimeTrackBundle\Event\TimeEntryBillableChangedEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Exception\BillableChangeRefusedException;
use Nowo\TimeTrackBundle\Exception\PeriodLockedException;
use Nowo\TimeTrackBundle\Exception\PeriodSubmittedException;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryData;
use Nowo\TimeTrackBundle\Repository\FindableTimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\UserSerialisedTimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Service\TeamAccessGuard;
use Nowo\TimeTrackBundle\Service\TimeEntryBillableService;
use Nowo\TimeTrackBundle\Tests\Stub\StubTeamContextProvider;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\FindableInMemoryTimeEntryRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimeEntryRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimesheetRepository;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use Nowo\TimeTrackBundle\Tests\Support\TimesheetFixtures;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Since 1.21 (Beacon D-20261111-12): the audited `billable` setter — who may change it, the period lock, the audit event.
 */
final class TimeEntryBillableServiceTest extends TestCase
{
    private TestUser $owner;

    private FindableInMemoryTimeEntryRepository $entries;

    private InMemoryTimesheetRepository $timesheets;

    private EventDispatcher $dispatcher;

    /** @var list<TimeEntryBillableChangedEvent> */
    private array $audit = [];

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->owner      = new TestUser('7', 'ana@example.com');
        $this->entries    = new FindableInMemoryTimeEntryRepository();
        $this->timesheets = new InMemoryTimesheetRepository();
        $this->dispatcher = new EventDispatcher();
        $this->logger     = new RecordingLogger();
        $this->audit      = [];
        $this->dispatcher->addListener(TimeTrackEvents::TIME_ENTRY_BILLABLE_CHANGED, function (TimeEntryBillableChangedEvent $event): void {
            $this->audit[] = $event;
        });
    }

    public function testTheOwnerChangesTheFlagAndEachChangeIsAudited(): void
    {
        $entry   = $this->entry('2026-11-10 09:00:00Z');
        $service = $this->service();

        self::assertSame($entry, $service->changeById($this->owner, $entry->getId(), true, 'api'));
        self::assertTrue($entry->getBillable());
        $service->change($this->owner, $entry, false);
        self::assertFalse($entry->getBillable());

        self::assertCount(2, $this->audit);
        self::assertSame([$entry->getId(), '7', '7', null, true, 'api', '2026-11-12'], [$this->audit[0]->entryId, $this->audit[0]->ownerId, $this->audit[0]->actorId, $this->audit[0]->oldBillable, $this->audit[0]->newBillable, $this->audit[0]->via, $this->audit[0]->occurredAt->format('Y-m-d')]);
        self::assertSame([true, false, 'host'], [$this->audit[1]->oldBillable, $this->audit[1]->newBillable, $this->audit[1]->via]);
        self::assertSame(3, $this->entries->saves, 'the fixture save and the two changes');
        self::assertSame(2, $this->entries->transactions);
        self::assertSame(['entry_billable', 'entry_billable'], array_column(array_column($this->logger->records, 'context'), 'action'));
        self::assertNotContains('7', $this->logger->contextValues(), 'REQ-OBS-001: no user ids in logs');
    }

    public function testSettingTheSameValueRecordsNothing(): void
    {
        $entry = $this->entry('2026-11-10 09:00:00Z');
        $this->service()->change($this->owner, $entry, null);

        self::assertSame([], $this->audit);
        self::assertSame(1, $this->entries->saves);
    }

    public function testAManagerOrAnAdminMayChangeItAndAnotherUserMayNot(): void
    {
        $entry    = $this->entry('2026-11-10 09:00:00Z');
        $stranger = new TestUser('8', 'bob@example.com');
        $this->expectRefusal(BillableChangeRefusedException::FORBIDDEN, fn (): TimeEntry => $this->service()->change($stranger, $entry, true));
        self::assertNull($entry->getBillable());
        self::assertSame([], $this->audit);

        $this->service(manager: true)->change($stranger, $entry, true);
        $this->service()->change(new TestUser('1', 'admin@example.com', ['ROLE_ADMIN']), $entry, false);
        self::assertSame(['8', '1'], array_map(static fn (TimeEntryBillableChangedEvent $event): string => $event->actorId, $this->audit));
    }

    public function testAHostListenerCanDenyTheOwner(): void
    {
        $this->dispatcher->addListener(TimeTrackEvents::TIME_ENTRY_ACCESS_CHECK, static function (TimeEntryAccessCheckEvent $event): void {
            $event->deny();
        });
        $entry = $this->entry('2026-11-10 09:00:00Z');

        $this->expectRefusal(BillableChangeRefusedException::FORBIDDEN, fn (): TimeEntry => $this->service()->change($this->owner, $entry, true));
    }

    public function testALockedOrSubmittedPeriodIsRefusedWhateverTheRole(): void
    {
        $this->timesheets->save(TimesheetFixtures::locked($this->owner, 'org-a', '2026-W45'));
        $this->timesheets->save(TimesheetFixtures::submitted($this->owner, 'org-a', '2026-W44'));
        $this->dispatcher->addListener(TimeTrackEvents::TIME_ENTRY_ACCESS_CHECK, static function (TimeEntryAccessCheckEvent $event): void {
            $event->grant();
        });
        $locked    = $this->entry('2026-11-03 09:00:00Z');
        $submitted = $this->entry('2026-10-27 09:00:00Z');
        $admin     = new TestUser('1', 'admin@example.com', ['ROLE_ADMIN']);

        try {
            $this->service()->change($admin, $locked, true);
            self::fail('locked');
        } catch (PeriodLockedException $exception) {
            self::assertSame(WriteKind::Edit, $exception->getWriteKind());
        }
        $this->expectException(PeriodSubmittedException::class);
        try {
            $this->service()->change($this->owner, $submitted, true);
        } finally {
            self::assertSame([null, null], [$locked->getBillable(), $submitted->getBillable()]);
            self::assertSame([], $this->audit);
        }
    }

    public function testImportedAnonymisedAndOwnerlessEntriesNeverChange(): void
    {
        $imported = TimeEntry::imported($this->owner, new ImportedTimeEntryData('clickup:1', 'e-1', 'task-1', 'T', null, new DateTimeImmutable('2026-11-10 09:00:00Z'), new DateTimeImmutable('2026-11-10 10:00:00Z'), 3600, true));
        $this->expectRefusal(BillableChangeRefusedException::READ_ONLY, fn (): TimeEntry => $this->service()->change($this->owner, $imported, false));

        $anonymised = $this->entry('2026-11-10 09:00:00Z');
        $anonymised->anonymiseInPlace(new DateTimeImmutable());
        $this->expectRefusal(BillableChangeRefusedException::ANONYMISED, fn (): TimeEntry => $this->service()->change($this->owner, $anonymised, true));

        $ownerless = new TimeEntry(null, 'task-1', 'T', null, new DateTimeImmutable('2026-11-10 09:00:00Z'), new DateTimeImmutable('2026-11-10 10:00:00Z'), 3600, TimeEntrySource::Web);
        $this->expectRefusal(BillableChangeRefusedException::ANONYMISED, fn (): TimeEntry => $this->service()->change($this->owner, $ownerless, true));
        self::assertSame([404, 403, 409, 409], array_map(static fn (string $code): int => (new BillableChangeRefusedException($code))->httpStatus(), [BillableChangeRefusedException::NOT_FOUND, BillableChangeRefusedException::FORBIDDEN, BillableChangeRefusedException::READ_ONLY, BillableChangeRefusedException::ANONYMISED]));
    }

    public function testUnknownEntriesAndStoresThatCannotFind(): void
    {
        $this->expectRefusal(BillableChangeRefusedException::NOT_FOUND, fn (): TimeEntry => $this->service()->changeById($this->owner, 'missing', true));

        $plain   = new InMemoryTimeEntryRepository();
        $service = new TimeEntryBillableService($plain, $this->guard());
        $entry   = new TimeEntry($this->owner, 'task-1', 'T', null, new DateTimeImmutable('2026-11-10 09:00:00Z'), new DateTimeImmutable('2026-11-10 10:00:00Z'), 3600, TimeEntrySource::Web);
        $plain->save($entry);
        self::assertNull($service->find($entry->getId()));
        $this->expectRefusal(BillableChangeRefusedException::NOT_FOUND, static fn (): TimeEntry => $service->changeById(new TestUser('7', 'ana@example.com'), $entry->getId(), true));
        // Without a transaction-capable store, a dispatcher, a lock guard or a logger it still writes.
        self::assertTrue($service->change($this->owner, $entry, true)->getBillable());
    }

    public function testTheEntryIsCheckedAgainUnderTheLock(): void
    {
        // Audit MV-T-L1: anonymised, replaced by an import, moved to another owner or deleted after it was loaded.
        $cases = [
            BillableChangeRefusedException::ANONYMISED => static function (TimeEntry $entry): TimeEntry {
                $entry->anonymiseInPlace(new DateTimeImmutable());

                return $entry;
            },
            BillableChangeRefusedException::READ_ONLY => fn (TimeEntry $entry): TimeEntry => TimeEntry::imported($this->owner, new ImportedTimeEntryData('clickup:1', 'e-9', 'task-1', 'T', null, $entry->getStartedAt(), $entry->getEndedAt(), 3600)),
            BillableChangeRefusedException::FORBIDDEN => static fn (TimeEntry $entry): TimeEntry => new TimeEntry(new TestUser('9', 'zoe@example.com'), 'task-1', 'T', null, $entry->getStartedAt(), $entry->getEndedAt(), 3600, TimeEntrySource::Web),
            BillableChangeRefusedException::NOT_FOUND => static fn (TimeEntry $entry): ?TimeEntry => null,
        ];
        foreach ($cases as $code => $meanwhile) {
            $entry                    = $this->entry('2026-11-10 09:00:00Z');
            $this->entries->meanwhile = $meanwhile;
            $this->expectRefusal($code, fn (): TimeEntry => $this->service()->change($this->owner, $entry, true));
        }
        self::assertSame([], $this->audit);
        self::assertSame(4, $this->entries->saves, 'only the fixtures');

        // A fresh copy of the row is what gets written.
        $loaded                   = $this->entry('2026-11-10 09:00:00Z');
        $fresh                    = clone $loaded;
        $this->entries->meanwhile = static fn (): TimeEntry => $fresh;
        self::assertSame($fresh, $this->service()->change($this->owner, $loaded, true));
        self::assertSame([true, null], [$fresh->getBillable(), $loaded->getBillable()]);
    }

    /** REQ-BC-001: a store with only FindableTimeEntryRepositoryInterface is re-read with findById() (no row lock). */
    public function testAStoreWithoutTheLockingReadIsReReadByIdAndStillChecked(): void
    {
        $store = new class implements TimeEntryRepositoryInterface, FindableTimeEntryRepositoryInterface, UserSerialisedTimeEntryRepositoryInterface {
            /** @var array<string, TimeEntry> */
            public array $rows = [];

            public int $finds = 0;

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
                ++$this->finds;

                return $this->rows[$id] ?? null;
            }

            public function inUserTransaction(object $user, callable $operation): mixed
            {
                return $operation();
            }
        };
        $service = new TimeEntryBillableService($store, $this->guard(), new PeriodLockGuard($this->timesheets), $this->dispatcher);
        $entry   = new TimeEntry($this->owner, 'task-1', 'T', null, new DateTimeImmutable('2026-11-10 09:00:00Z'), new DateTimeImmutable('2026-11-10 10:00:00Z'), 3600, TimeEntrySource::Web);
        $store->save($entry);

        self::assertTrue($service->changeById($this->owner, $entry->getId(), true)->getBillable());
        self::assertSame(2, $store->finds, 'the lookup, then the re-read inside the transaction');
        self::assertCount(1, $this->audit);

        $entry->anonymiseInPlace(new DateTimeImmutable());
        $loaded                        = new TimeEntry($this->owner, 'task-1', 'T', null, new DateTimeImmutable('2026-11-10 09:00:00Z'), new DateTimeImmutable('2026-11-10 10:00:00Z'), 3600, TimeEntrySource::Web);
        $store->rows[$loaded->getId()] = $entry;
        $this->expectRefusal(BillableChangeRefusedException::ANONYMISED, static fn (): TimeEntry => $service->change(new TestUser('7', 'ana@example.com'), $loaded, false));
    }

    public function testTheEditBillableActionDecidesOnItsOwn(): void
    {
        $entry    = $this->entry('2026-11-10 09:00:00Z');
        $stranger = new TestUser('8', 'bob@example.com');
        $this->dispatcher->addListener(TimeTrackEvents::TIME_ENTRY_ACCESS_CHECK, static function (TimeEntryAccessCheckEvent $event) use ($stranger): void {
            if ($event->getAction() === TeamAccessGuard::ACTION_EDIT_BILLABLE) {
                $event->getUserId() === $stranger->getId() ? $event->grant() : $event->deny();
            }
        });

        $this->expectRefusal(BillableChangeRefusedException::FORBIDDEN, fn (): TimeEntry => $this->service()->change($this->owner, $entry, true));
        self::assertTrue($this->service()->change($stranger, $entry, true)->getBillable(), 'a host grants it without the edit right');
        self::assertFalse($this->guard()->isBillableEditGranted($this->owner, TimeEntry::imported($this->owner, new ImportedTimeEntryData('clickup:1', 'e-8', 'task-1', 'T', null, $entry->getStartedAt(), $entry->getEndedAt(), 3600))));
    }

    public function testAFailingAuditListenerIsLoggedAndDoesNotUndoTheChange(): void
    {
        $this->dispatcher->addListener(TimeTrackEvents::TIME_ENTRY_BILLABLE_CHANGED, static function (): never {
            throw new RuntimeException('listener down');
        });
        $entry = $this->entry('2026-11-10 09:00:00Z');
        $this->service()->change($this->owner, $entry, true);

        self::assertTrue($entry->getBillable());
        self::assertContains('TimeTrack billable-changed listener failed.', $this->logger->messages());
    }

    private function entry(string $start): TimeEntry
    {
        $from  = new DateTimeImmutable($start);
        $entry = (new TimeEntry($this->owner, 'task-1', 'T', null, $from, $from->modify('+1 hour'), 3600, TimeEntrySource::Web))->assignScopeKey('org-a');
        $this->entries->save($entry);

        return $entry;
    }

    private function guard(bool $manager = false): TeamAccessGuard
    {
        return new TeamAccessGuard(new StubTeamContextProvider([], $manager), $this->dispatcher, ['ROLE_ADMIN'], true, true, null, new PeriodLockGuard($this->timesheets));
    }

    private function service(bool $manager = false): TimeEntryBillableService
    {
        return new TimeEntryBillableService($this->entries, $this->guard($manager), new PeriodLockGuard($this->timesheets), $this->dispatcher, new MockClock('2026-11-12 10:00:00'), $this->logger);
    }

    private function expectRefusal(string $code, callable $operation): void
    {
        try {
            $operation();
            self::fail('expected ' . $code);
        } catch (BillableChangeRefusedException $exception) {
            self::assertSame($code, $exception->errorCode);
        }
    }
}
