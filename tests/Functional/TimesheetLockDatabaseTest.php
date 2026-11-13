<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\TimeTrackBundle\Bridge\NullTeamContextProvider;
use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Nowo\TimeTrackBundle\Dto\BackdateLimits;
use Nowo\TimeTrackBundle\Entity\BalanceLedgerLine;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Entity\TimesheetEvent;
use Nowo\TimeTrackBundle\Enum\AuditClient;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\LedgerKind;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Enum\TimesheetAction;
use Nowo\TimeTrackBundle\Enum\TimesheetActorRole;
use Nowo\TimeTrackBundle\Enum\TimesheetLockReason;
use Nowo\TimeTrackBundle\Enum\TimesheetPeriodKind;
use Nowo\TimeTrackBundle\Enum\TimesheetStatus;
use Nowo\TimeTrackBundle\Event\TimeEntryAccessCheckEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Exception\PeriodLockedException;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryData;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryDeleter;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryOutcome;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryResult;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryWriter;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmBalanceLedgerRepository;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimeEntryRepository;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimesheetEventRepository;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimesheetRepository;
use Nowo\TimeTrackBundle\Service\TeamAccessGuard;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Tests\Stub\StubTimesheetApproverProvider;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryActiveTimerRepository;
use Nowo\TimeTrackBundle\Tests\Support\RacingImportedTimeEntryRepository;
use Nowo\TimeTrackBundle\Tests\Support\ScopeKeyCollation;
use Nowo\TimeTrackBundle\Timesheet\Period;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;
use Nowo\TimeTrackBundle\Timesheet\SingleScopeProvider;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function dirname;
use function explode;
use function file_get_contents;
use function str_repeat;
use function str_replace;
use function str_starts_with;
use function trim;

/**
 * Since 1.13, against the test database (SQLite by default; MySQL and PostgreSQL with TIME_TRACK_TEST_DATABASE_URL):
 * the reference migrations of docs/sql/1.13.0 match the mapping, the stores query timesheets, events and the ledger,
 * and the period lock stops every bundle write path on stored entries.
 */
final class TimesheetLockDatabaseTest extends FunctionalTestCase
{
    protected static function timeTrackConfig(): array
    {
        return ['timesheets' => ['enabled' => true, 'scope_mode' => 'single', 'approver_provider' => StubTimesheetApproverProvider::class]];
    }

    public function testTheReferenceMigrationsMatchTheMapping(): void
    {
        $em       = $this->entityManager();
        $conn     = $em->getConnection();
        $platform = $conn->getDatabasePlatform();
        $name     = match (true) {
            $platform instanceof AbstractMySQLPlatform => 'mysql',
            $platform instanceof PostgreSQLPlatform    => 'postgresql',
            $platform instanceof SQLitePlatform        => 'sqlite',
            default                                    => throw new LogicException('Unsupported test platform.'),
        };
        $tool     = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();

        $this->runSql($name . '-down.sql');
        $expected112 = $tool->getSchemaFromMetadata($metadata);
        foreach (['time_track_timesheet_events', 'time_track_balance_ledger', 'time_track_timesheets'] as $table) {
            $expected112->dropTable($table);
        }
        $entries = $expected112->getTable('time_track_entries');
        $entries->dropIndex('idx_time_track_entries_user_scope_started');
        $entries->dropColumn('scope_key');
        $schemaManager = $conn->createSchemaManager();
        $diff          = $schemaManager->createComparator()->compareSchemas($schemaManager->introspectSchema(), $expected112);
        self::assertSame([], $platform->getAlterSchemaSQL($diff), 'the down script restores the 1.12 schema');

        $this->runSql($name . '-up.sql');
        // Since 1.15 the mapping also has this index, from docs/sql/1.15.0.
        $conn->executeStatement('CREATE INDEX idx_time_track_timesheets_owner_ref ON time_track_timesheets (owner_ref, period_end)');
        // Since 1.24.1 the scope_key columns compare exactly (UPGRADING "To 1.24.1").
        ScopeKeyCollation::apply($conn);
        self::assertSame([], $tool->getUpdateSchemaSql($metadata), 'the up script is exactly the 1.13 mapping');
    }

    public function testTheDemoMigrationAndUpgradingCarryTheReferenceStatements(): void
    {
        $migration = (string) file_get_contents(dirname(__DIR__, 2) . '/demo/symfony8/migrations/Version20261106000000.php');
        $upgrading = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/UPGRADING.md');
        foreach (['mysql', 'postgresql', 'sqlite'] as $platform) {
            foreach (['up', 'down'] as $direction) {
                foreach ($this->statements($platform . '-' . $direction . '.sql') as $statement) {
                    self::assertStringContainsString($statement . ';', $upgrading);
                    if ($platform === 'mysql') {
                        self::assertStringContainsString("addSql('" . $statement . "')", $migration);
                    }
                }
            }
        }
    }

    public function testTheStoresQueryTimesheetsEventsAndTheLedger(): void
    {
        $timesheets = new DoctrineOrmTimesheetRepository($this->entityManager());
        $events     = new DoctrineOrmTimesheetEventRepository($this->entityManager());
        $ledger     = new DoctrineOrmBalanceLedgerRepository($this->entityManager());
        $owner      = (string) $this->user->getId();
        $other      = $this->createUser('other@example.com');
        $week44     = new Timesheet($this->user, $owner, 'org-a', Period::fromKey(TimesheetPeriodKind::Week, '2026-W44'), 'Europe/Madrid', new DateTimeImmutable('2026-11-01 08:00:00'));
        $week45     = new Timesheet($this->user, $owner, 'org-a', Period::fromKey(TimesheetPeriodKind::Week, '2026-W45'), 'Europe/Madrid', new DateTimeImmutable('2026-11-01 08:00:00'));
        $week45b    = new Timesheet($this->user, $owner, 'org-b', Period::fromKey(TimesheetPeriodKind::Week, '2026-W45'), 'UTC', new DateTimeImmutable('2026-11-01 08:00:00'));
        $theirs     = new Timesheet($other, (string) $other->getId(), 'org-a', Period::fromKey(TimesheetPeriodKind::Week, '2026-W45'), 'UTC', new DateTimeImmutable('2026-11-01 08:00:00'));
        $week44->applyApprove(new DateTimeImmutable('2026-11-02 08:00:00'), '99', str_repeat('a', 64), ['diffSeconds' => -60, 'source' => 'task_time']);
        $week45->applySubmit(new DateTimeImmutable('2026-11-06 08:00:00'), $owner, str_repeat('b', 64), null);

        $created = $timesheets->transactional(function () use ($timesheets, $events, $ledger, $week44, $week45, $week45b, $theirs, $owner): string {
            foreach ([$week44, $week45, $week45b, $theirs] as $timesheet) {
                $timesheets->save($timesheet);
            }
            $first  = new TimesheetEvent($week44, 1, TimesheetAction::Submitted, TimesheetStatus::Open, TimesheetStatus::Submitted, $owner, TimesheetActorRole::Owner, AuditClient::Web, new DateTimeImmutable('2026-11-01 09:00:00'), null);
            $second = new TimesheetEvent($week44, 2, TimesheetAction::Approved, TimesheetStatus::Submitted, TimesheetStatus::Approved, '99', TimesheetActorRole::Approver, AuditClient::Web, new DateTimeImmutable('2026-11-02 08:00:00'), $first->getHash(), null, str_repeat('a', 64), ['diffSeconds' => -60, 'source' => 'task_time']);
            $events->append($first);
            $events->append($second);
            $opening  = new BalanceLedgerLine($this->user, $owner, 'org-a', '2026-10-01', LedgerKind::Opening, 7200, new DateTimeImmutable('2026-11-01 08:00:00'), null, 'Opening');
            $ratified = new BalanceLedgerLine($this->user, $owner, 'org-a', '2026-11-03', LedgerKind::OvertimeRatified, 600, new DateTimeImmutable('2026-11-02 08:00:00'), $opening->getHash(), null, $week44);
            $reversal = new BalanceLedgerLine($this->user, $owner, 'org-a', '2026-11-03', LedgerKind::Reversal, -600, new DateTimeImmutable('2026-11-03 08:00:00'), $ratified->getHash(), 'Reopen', $week44, $ratified->getId());
            $ledger->append($opening);
            $ledger->append($ratified);
            $ledger->append($reversal);

            return $ratified->getId();
        });
        $ledger->flush();
        $this->entityManager()->clear();

        $found = $timesheets->findForPeriod($owner, 'org-a', TimesheetPeriodKind::Week, '2026-11-02');
        self::assertSame($week45->getId(), $found?->getId());
        self::assertSame(TimesheetStatus::Submitted, $found->getStatus());
        self::assertNull($timesheets->findForPeriod($owner, 'org-a', TimesheetPeriodKind::Month, '2026-11-02'));
        self::assertSame($week44->getId(), $timesheets->find($week44->getId())?->getId());
        self::assertEquals(['diffSeconds' => -60, 'source' => 'task_time'], $timesheets->find($week44->getId())->getTotals());

        $guarding = $timesheets->findGuarding($owner, null, '2026-10-30', '2026-11-03');
        self::assertSame([$week44->getId(), $week45->getId()], array_map(static fn (Timesheet $t): string => $t->getId(), $guarding));
        self::assertSame([$week44->getId()], array_map(static fn (Timesheet $t): string => $t->getId(), $timesheets->findGuarding($owner, 'org-a', '2026-10-30', '2026-10-31')));
        self::assertSame([], $timesheets->findGuarding($owner, 'org-b', '2026-10-30', '2026-11-30'), 'org-b is neither locked nor submitted');
        self::assertCount(2, $timesheets->findByOwner($owner, 'org-a', '2026-10-01', '2026-12-31'));
        self::assertCount(1, $timesheets->findQueue('org-a', [$owner], TimesheetStatus::Submitted, 10));
        self::assertCount(3, $timesheets->findQueue('org-a', [$owner, (string) $other->getId()], null, 10));
        self::assertSame([], $timesheets->findQueue('org-a', [], null, 10));
        self::assertSame($week44->getId(), $timesheets->findLatestLockedBefore($owner, 'org-a', '2026-11-30')?->getId());
        self::assertNull($timesheets->findLatestLockedBefore($owner, 'org-a', '2026-11-01'));

        $rows = $events->findByTimesheet($week44->getId());
        self::assertSame([1, 2], array_map(static fn (TimesheetEvent $e): int => $e->getSeq(), $rows));
        foreach ($rows as $row) {
            self::assertSame($row->getHash(), $row->computeHash(), 'a row read back hashes like the row written');
        }
        self::assertSame(2, $events->findLast($week44->getId())?->getSeq());
        self::assertNull($events->findLast($week45->getId()));

        $lines = $ledger->findByOwner($owner, 'org-a');
        self::assertSame([LedgerKind::Opening, LedgerKind::OvertimeRatified, LedgerKind::Reversal], array_map(static fn (BalanceLedgerLine $l): LedgerKind => $l->getKind(), $lines));
        foreach ($lines as $line) {
            self::assertSame($line->getHash(), $line->computeHash());
        }
        self::assertCount(1, $ledger->findByOwner($owner, 'org-a', '2026-09-01', '2026-10-31'));
        self::assertCount(2, $ledger->findByOwner($owner, 'org-a', '2026-11-02'));
        self::assertSame(LedgerKind::Reversal, $ledger->findLast($owner, 'org-a')?->getKind());
        self::assertSame($created, $ledger->find($created)?->getId());
        self::assertSame(LedgerKind::Reversal, $ledger->findReversalOf($created)?->getKind());
        self::assertCount(2, $ledger->findByTimesheet($week44->getId()));

        self::assertSame($this->sorted([$week44->getId(), $week45->getId()]), $this->sorted($timesheets->timesheetIds('org-a', $owner, '', 10)));
        self::assertCount(4, $timesheets->timesheetIds(null, null, '', 10));
        self::assertSame([['ownerRef' => $owner, 'scopeKey' => 'org-a']], $timesheets->ledgerChains('org-a', $owner));
        self::assertCount(1, $timesheets->ledgerChains(null, null));

        // Retention: the chain prefix effective before 2026-11-02 goes (the 2026-10-01 opening); the first line kept is
        // marked as the chain's restart, and week 44 stays while a kept ledger line still points to it.
        self::assertSame(['timesheets' => 0, 'events' => 0, 'ledger' => 1], $timesheets->purgeBefore('2026-11-02'));
        self::assertNotNull($timesheets->find($week44->getId()));
        $kept = $ledger->find($created);
        self::assertSame('2026-11-02', $kept->getPurgedBefore());
        self::assertSame($kept->getHash(), $kept->computeHash(), 'the restart mark is not part of the hash');
        self::assertNull($ledger->findReversalOf($created)->getPurgedBefore());
        self::assertSame(['timesheets' => 0, 'events' => 0, 'ledger' => 0], $timesheets->purgeBefore('2026-11-02'));

        // A later cut-off takes the rest of the chain and then every timesheet ended before it (week 44 with its rows).
        $first  = $timesheets->purgeBefore('2026-11-10', 2);
        $second = $timesheets->purgeBefore('2026-11-10', 2);
        self::assertSame(['timesheets' => 4, 'events' => 2, 'ledger' => 2], ['timesheets' => $first['timesheets'] + $second['timesheets'], 'events' => $first['events'] + $second['events'], 'ledger' => $first['ledger'] + $second['ledger']]);
        self::assertSame(['timesheets' => 0, 'events' => 0, 'ledger' => 0], $timesheets->purgeBefore('2026-11-10', 2));
        self::assertNull($timesheets->find($week44->getId()));
        self::assertSame([], $events->findByTimesheet($week44->getId()));
        self::assertSame([], $ledger->findByOwner($owner, 'org-a'));
    }

    public function testThePurgeNeverCutsAChainInTheMiddle(): void
    {
        $ledger = new DoctrineOrmBalanceLedgerRepository($this->entityManager());
        $owner  = (string) $this->user->getId();
        // A backdated line written after a recent one: effective before the cut-off, but not part of a chain prefix.
        $recent    = new BalanceLedgerLine($this->user, $owner, 'org-a', '2026-10-01', LedgerKind::Opening, 60, new DateTimeImmutable('2026-10-01 08:00:00'), null, 'Opening');
        $backdated = new BalanceLedgerLine($this->user, $owner, 'org-a', '2020-01-01', LedgerKind::Adjustment, 60, new DateTimeImmutable('2026-10-02 08:00:00'), $recent->getHash(), 'Backdated');
        $ledger->append($recent);
        $ledger->append($backdated);
        $ledger->flush();

        self::assertSame(['timesheets' => 0, 'events' => 0, 'ledger' => 0], (new DoctrineOrmTimesheetRepository($this->entityManager()))->purgeBefore('2021-01-01'));
        self::assertCount(2, $ledger->findByOwner($owner, 'org-a'));
        self::assertNull($ledger->findByOwner($owner, 'org-a')[0]->getPurgedBefore());
    }

    public function testTheLedgerChainRefusesAFork(): void
    {
        $ledger = new DoctrineOrmBalanceLedgerRepository($this->entityManager());
        $owner  = (string) $this->user->getId();
        $first  = new BalanceLedgerLine($this->user, $owner, 'org-a', '2026-10-01', LedgerKind::Opening, 60, new DateTimeImmutable('2026-11-01 08:00:00'), null, 'Opening');
        $ledger->append($first);
        $ledger->flush();
        $ledger->append(new BalanceLedgerLine($this->user, $owner, 'org-a', '2026-10-02', LedgerKind::Adjustment, 60, new DateTimeImmutable('2026-11-01 09:00:00'), $first->getHash(), 'One'));
        $ledger->flush();

        $this->expectException(UniqueConstraintViolationException::class);
        $ledger->append(new BalanceLedgerLine($this->user, $owner, 'org-a', '2026-10-03', LedgerKind::Adjustment, 60, new DateTimeImmutable('2026-11-01 09:00:01'), $first->getHash(), 'Two'));
        $ledger->flush();
    }

    public function testAFailedTransactionIsRethrown(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('rolled back');

        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        (new DoctrineOrmTimesheetRepository($this->entityManager(), $registry))->transactional(static function (): never {
            throw new RuntimeException('rolled back');
        });
    }

    public function testThePeriodLockStopsEveryEntryWritePath(): void
    {
        $em    = $this->entityManager();
        $guard = self::getContainer()->get(PeriodLockGuard::class);
        self::assertInstanceOf(PeriodLockGuard::class, $guard);
        $repository = new DoctrineOrmTimeEntryRepository($em);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(TimeTrackEvents::TIME_ENTRY_ACCESS_CHECK, static function (TimeEntryAccessCheckEvent $event): void {
            $event->grant();
        });
        $writer = new ImportedTimeEntryWriter($repository, $dispatcher, periodLockGuard: $guard, scopeProvider: new SingleScopeProvider());
        $owner  = (string) $this->user->getId();

        $inside  = new ImportedTimeEntryData('clickup:dir-1', 'e-1', 'task-1', 'T', null, new DateTimeImmutable('2026-10-06 09:00:00'), new DateTimeImmutable('2026-10-06 10:00:00'), 3600);
        $outside = new ImportedTimeEntryData('clickup:dir-1', 'e-2', 'task-1', 'T', null, new DateTimeImmutable('2026-10-13 09:00:00'), new DateTimeImmutable('2026-10-13 10:00:00'), 3600);
        $writer->upsertMany([[$this->user, $inside], [$this->user, $outside]]);
        $live = (new TimeEntry($this->user, 'task-1', 'T', null, new DateTimeImmutable('2026-10-07 09:00:00'), new DateTimeImmutable('2026-10-07 10:00:00'), 3600, TimeEntrySource::Web))->assignScopeKey('');
        $repository->save($live);

        $timesheets = new DoctrineOrmTimesheetRepository($em);
        $locked     = new Timesheet($this->user, $owner, '', Period::fromKey(TimesheetPeriodKind::Week, '2026-W41'), 'UTC', new DateTimeImmutable('2026-10-12 08:00:00'));
        $locked->applyLock(new DateTimeImmutable('2026-10-12 08:00:00'), TimesheetLockReason::Manual);
        $timesheets->save($locked);

        // Import: a new row and a change of a stored row are skipped; a row outside the period is written.
        $late   = new ImportedTimeEntryData('clickup:dir-1', 'e-3', 'task-1', 'T', null, new DateTimeImmutable('2026-10-08 09:00:00'), new DateTimeImmutable('2026-10-08 10:00:00'), 3600);
        $moved  = new ImportedTimeEntryData('clickup:dir-1', 'e-1', 'task-2', 'T2', null, new DateTimeImmutable('2026-10-06 09:00:00'), new DateTimeImmutable('2026-10-06 10:00:00'), 3600);
        $result = $writer->upsertMany([[$this->user, $late], [$this->user, $moved], [$this->user, new ImportedTimeEntryData('clickup:dir-1', 'e-2', 'task-2', 'T2', null, new DateTimeImmutable('2026-10-13 09:00:00'), new DateTimeImmutable('2026-10-13 10:00:00'), 3600)]]);
        self::assertSame([ImportedTimeEntryOutcome::Locked, ImportedTimeEntryOutcome::Locked, ImportedTimeEntryOutcome::Updated], array_map(static fn (ImportedTimeEntryResult $r): ImportedTimeEntryOutcome => $r->outcome, $result->results));
        self::assertSame(2, $result->locked);
        self::assertSame(ImportedTimeEntryOutcome::Unchanged, $writer->upsert($this->user, $inside)->outcome, 'an unchanged row is not a lock refusal');
        // Moving an entry from an open week into the locked one is refused too.
        self::assertSame(ImportedTimeEntryOutcome::Locked, $writer->upsert($this->user, new ImportedTimeEntryData('clickup:dir-1', 'e-2', 'task-2', 'T2', null, new DateTimeImmutable('2026-10-08 09:00:00'), new DateTimeImmutable('2026-10-08 10:00:00'), 3600))->outcome);
        self::assertNull($repository->findByExternal('clickup:dir-1', 'e-3'));
        self::assertSame('task-1', $repository->findByExternal('clickup:dir-1', 'e-1')?->getTaskId());

        // Edit and delete: refused whatever the listener grants.
        $stored    = $repository->findByExternal('clickup:dir-1', 'e-1');
        $teamGuard = new TeamAccessGuard(new NullTeamContextProvider(), $dispatcher, ['ROLE_ADMIN'], false, false, null, $guard);
        self::assertFalse($teamGuard->canEditEntry($this->user, $live));
        self::assertFalse($teamGuard->canDeleteImportedEntry($this->user, $stored));
        try {
            (new ImportedTimeEntryDeleter($teamGuard, $writer))->delete($this->user, $stored);
            self::fail('locked delete');
        } catch (AccessDeniedException) {
        }
        try {
            $writer->remove('clickup:dir-1', 'e-1');
            self::fail('locked remove');
        } catch (PeriodLockedException) {
        }
        self::assertSame(0, $writer->remove('clickup:dir-1', 'missing'));

        // Bulk removals keep the locked entry; since 1.15 removeForUser() too: it is for re-association, not erasure.
        self::assertSame(1, $writer->removeForTasks('clickup:dir-1', ['task-1', 'task-2']));
        self::assertNotNull($repository->findByExternal('clickup:dir-1', 'e-1'));
        self::assertSame(0, $writer->removeForSource('clickup:dir-1'), 'a batch of locked entries only is skipped');
        self::assertSame(0, $writer->removeForUser('clickup:dir-1', $this->user));
        self::assertNotNull($repository->findByExternal('clickup:dir-1', 'e-1'));

        // Timer: a backdated start into the locked week is refused.
        $timer = new TimerService(new StubTaskProvider(), new InMemoryActiveTimerRepository(), $repository, $dispatcher, new MockClock('2026-10-12 09:00:00'), null, $guard, new SingleScopeProvider());
        $this->expectException(PeriodLockedException::class);
        $timer->startAt($this->user, 'task-1', ClientType::Web, [], new DateTimeImmutable('2026-10-11 20:00:00'), new BackdateLimits(86400));
    }

    public function testBulkRemovalWithLocksNeedsAFilteringStore(): void
    {
        $guard = self::getContainer()->get(PeriodLockGuard::class);
        self::assertInstanceOf(PeriodLockGuard::class, $guard);
        $writer = new ImportedTimeEntryWriter(new RacingImportedTimeEntryRepository(new DoctrineOrmTimeEntryRepository($this->entityManager())), periodLockGuard: $guard);

        $this->expectException(LogicException::class);
        $writer->removeForSource('clickup:dir-1');
    }

    public function testTheBackfillCommandPlacesScopelessEntries(): void
    {
        $repository = new DoctrineOrmTimeEntryRepository($this->entityManager());
        for ($i = 0; $i < 3; ++$i) {
            $repository->save(new TimeEntry($this->user, 'task-1', 'T', null, new DateTimeImmutable('2026-11-03 09:00:00'), new DateTimeImmutable('2026-11-03 10:00:00'), 3600, TimeEntrySource::Web));
        }
        self::assertNotNull(self::$kernel);
        $application = new Application(self::$kernel);
        $tester      = new CommandTester($application->find('nowo:time-track:entries:backfill-scope'));

        self::assertSame(0, $tester->execute(['--batch' => '2', '--dry-run' => true]));
        self::assertStringContainsString('Would place 3 entries', $tester->getDisplay());
        self::assertCount(3, $repository->findScopelessAfter('', 10));

        self::assertSame(0, $tester->execute(['--batch' => '2']));
        self::assertStringContainsString('Placed 3 entries; 0 left', $tester->getDisplay());
        self::assertSame([], $repository->findScopelessAfter('', 10));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Placed 0 entries', $tester->getDisplay());
    }

    public function testANumericScopeKeyIsAssignedAsAString(): void
    {
        // Since 1.24.2: a scope key like "42" is a numeric array key in PHP, so it must not reach the parameter as an
        // int (PostgreSQL refuses an integer for a varchar column).
        $repository = new DoctrineOrmTimeEntryRepository($this->entityManager());
        $entry      = new TimeEntry($this->user, 'task-1', 'T', null, new DateTimeImmutable('2026-11-03 09:00:00'), new DateTimeImmutable('2026-11-03 10:00:00'), 3600, TimeEntrySource::Web);
        $repository->save($entry);

        self::assertSame(1, $repository->assignScopeKeys([$entry->getId() => '42']));
        $this->entityManager()->clear();
        self::assertSame('42', (string) $this->entityManager()->getConnection()->fetchOne('SELECT scope_key FROM time_track_entries WHERE id = ?', [$entry->getId()]));
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    private function runSql(string $file): void
    {
        $conn = $this->entityManager()->getConnection();
        foreach ($this->statements($file) as $statement) {
            $conn->executeStatement(str_replace('REFERENCES users (id)', 'REFERENCES app_user (id)', $statement));
        }
    }

    /**
     * @return list<string>
     */
    private function statements(string $file): array
    {
        $statements = [];
        foreach (explode("\n", (string) file_get_contents(dirname(__DIR__, 2) . '/docs/sql/1.13.0/' . $file)) as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '--')) {
                $statements[] = rtrim($line, ';');
            }
        }

        return $statements;
    }
}
