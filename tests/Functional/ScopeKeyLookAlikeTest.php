<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Nowo\TimeTrackBundle\Entity\BalanceLedgerLine;
use Nowo\TimeTrackBundle\Entity\CalendarConnection;
use Nowo\TimeTrackBundle\Entity\CalendarRule;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Enum\CalendarConnectionMode;
use Nowo\TimeTrackBundle\Enum\CalendarProvider;
use Nowo\TimeTrackBundle\Enum\LedgerKind;
use Nowo\TimeTrackBundle\Enum\TimesheetLockReason;
use Nowo\TimeTrackBundle\Enum\TimesheetPeriodKind;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryData;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryPerson;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryWriter;
use Nowo\TimeTrackBundle\Import\InvalidImportedTimeEntryException;
use Nowo\TimeTrackBundle\Integration\UserReferenceResolverInterface;
use Nowo\TimeTrackBundle\Repository\BalanceLedgerRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\CalendarConnectionRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmBalanceLedgerRepository;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmCalendarRepository;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimeEntryRepository;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimesheetRepository;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimesheetRepositoryInterface;
use Nowo\TimeTrackBundle\Timesheet\Period;

use function array_map;
use function preg_replace;
use function sort;
use function sprintf;

/**
 * 1.24.1 (REQ-SEC-006, audit OA-R2 / F4): tenant scope keys are matched byte for byte. `bob` and its look-alikes `Bob`
 * and `bób` are distinct scopes; `bob `, `bob\n`, NBSP and ZWSP variants are refused before they are written.
 *
 * The look-alike test first turns the `scope_key` columns into a folding collation, as on a database upgraded without
 * the 1.24.1 `ALTER` (SQLite `NOCASE`; MySQL / MariaDB `utf8mb4_unicode_ci`, which also folds accents and pads trailing
 * spaces), so it fails without the re-checks in the stores. The collation test fails without the mapping change.
 */
final class ScopeKeyLookAlikeTest extends FunctionalTestCase
{
    /** Table => whether its scope_key column is nullable. */
    private const TABLES = [
        'time_track_timesheets'           => false,
        'time_track_balance_ledger'       => false,
        'time_track_calendar_connections' => false,
        'time_track_calendar_rules'       => false,
        'time_track_entries'              => true,
    ];

    public function testTheScopeKeyColumnsUseABinaryNoPadCollation(): void
    {
        $connection = $this->entityManager()->getConnection();
        $platform   = $connection->getDatabasePlatform();
        if (!$platform instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('MySQL / MariaDB only: PostgreSQL and SQLite compare exactly by default.');
        }
        $expected = $platform instanceof MariaDBPlatform ? 'utf8mb4_nopad_bin' : 'utf8mb4_0900_bin';
        foreach (self::TABLES as $table => $nullable) {
            self::assertSame($expected, $connection->fetchOne('SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, 'scope_key']), $table);
        }
        // The NO PAD collation itself: a trailing space or another case never matches.
        $connection->insert('time_track_calendar_rules', ['id' => 'r-1', 'user_id' => $this->user->getId(), 'scope_key' => 'bob', 'pattern' => 'p', 'task_id' => 't', 'group_matches' => 1, 'created_at' => '2026-11-13 10:00:00']);
        foreach (['Bob', 'bób', 'bob '] as $lookAlike) {
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM time_track_calendar_rules WHERE scope_key = ?', [$lookAlike]), $lookAlike);
        }
    }

    public function testTimesheetLookupsNeverReachALookAlikeScope(): void
    {
        [$owner, $ownerId, $now] = $this->foldedOwner();
        $sheets                  = $this->sheets();

        // The look-alikes sort first (queue by period start) and last (latest locked): a LIMIT before the exact check
        // would return one of them.
        $byScope = [];
        foreach (['Bob' => '2026-W44', 'bób' => '2026-W45', 'bob' => '2026-W46'] as $scope => $week) {
            $sheet = new Timesheet($owner, $ownerId, $scope, Period::fromKey(TimesheetPeriodKind::Week, $week), 'UTC', $now);
            $sheet->applyLock($now, TimesheetLockReason::Manual);
            $sheets->save($sheet);
            $byScope[$scope] = $sheet->getId();
        }
        $from = '2026-10-01';
        $to   = '2026-12-31';

        $queue = $sheets->findQueue('bob', [$ownerId], null, 1);
        self::assertSame([$byScope['bob']], $this->ids($queue), 'F4: the approver of bob never sees Bob or bób');
        self::assertSame([$byScope['bob']], $this->ids($sheets->findQueue('bob', [$ownerId], null, 10)));
        self::assertSame([$byScope['bob']], $this->ids($sheets->findByOwner($ownerId, 'bob', $from, $to)));
        self::assertSame([$byScope['bob']], $this->ids($sheets->findGuarding($ownerId, 'bob', $from, $to)));
        self::assertSame([$byScope['bob']], $this->ids($sheets->findGuardingByOwnerRef($ownerId, 'bob', $from, $to)));
        self::assertSame([$byScope['bob']], $this->ids($sheets->transactional(static fn (): array => $sheets->findGuardingForMove($ownerId, 'bob', $from, $to))));
        self::assertNull($sheets->findForPeriod($ownerId, 'bob', TimesheetPeriodKind::Week, Period::fromKey(TimesheetPeriodKind::Week, '2026-W44')->start));
        self::assertSame($byScope['bob'], $sheets->findForPeriod($ownerId, 'bob', TimesheetPeriodKind::Week, Period::fromKey(TimesheetPeriodKind::Week, '2026-W46')->start)?->getId());
        self::assertSame($byScope['Bob'], $sheets->findLatestLockedBefore($ownerId, 'Bob', '2027-01-31')?->getId(), 'the newer bób / bob sheets never hide Bob\'s');
        self::assertSame([$byScope['bob']], $sheets->timesheetIds('bob', null, '', 1));
        self::assertSame([$byScope['bob']], $sheets->timesheetIds('bob', $ownerId, '', 5));
    }

    public function testLedgerChainsOfLookAlikeScopesStayApart(): void
    {
        [$owner, $ownerId, $now] = $this->foldedOwner();
        $sheets                  = $this->sheets();

        // Different previous hashes: the still-folding unique key would refuse two chain starts.
        $ledger = $this->ledger();
        $mine   = new BalanceLedgerLine($owner, $ownerId, 'bob', '2026-11-02', LedgerKind::Opening, 60, $now, null, 'x');
        $ledger->append($mine);
        $ledger->flush();
        $other = new BalanceLedgerLine($owner, $ownerId, 'Bob', '2026-11-03', LedgerKind::Opening, 30, $now->modify('+1 second'), 'other-chain', 'y');
        $ledger->append($other);
        $ledger->flush();
        self::assertSame([$mine->getId()], array_map(static fn (BalanceLedgerLine $line): string => $line->getId(), $ledger->findByOwner($ownerId, 'bob')));
        self::assertSame($mine->getId(), $ledger->findLast($ownerId, 'bob')?->getId(), 'Bob\'s newer head never stands for bob\'s');
        self::assertSame([['ownerRef' => $ownerId, 'scopeKey' => 'bob']], $sheets->ledgerChains('bob', null));
        self::assertCount(2, $sheets->ledgerChains(null, $ownerId), 'a DISTINCT on the folding column merged both chains');
    }

    public function testCalendarRowsOfLookAlikeScopesStayApart(): void
    {
        [$owner, $ownerId, $now] = $this->foldedOwner();

        // Different providers: the still-folding unique key would refuse both connections.
        $calendar = $this->calendar();
        $bob      = new CalendarConnection($owner, 'bob', CalendarProvider::Google, CalendarConnectionMode::Server, 'cred', 'v1', $now);
        $calendar->saveConnection($bob);
        $calendar->saveConnection(new CalendarConnection($owner, 'Bob', CalendarProvider::Microsoft, CalendarConnectionMode::Server, 'cred', 'v1', $now));
        $calendar->saveRule(new CalendarRule($owner, 'bob', 'standup', 'task-1', true, $now));
        $calendar->saveRule($kept = new CalendarRule($owner, 'bób', 'standup', 'task-1', true, $now));
        self::assertNull($calendar->findConnectionFor($ownerId, 'bob', CalendarProvider::Microsoft));
        self::assertSame($bob->getId(), $calendar->findConnectionFor($ownerId, 'bob', CalendarProvider::Google)?->getId());
        self::assertSame([$bob->getId()], array_map(static fn (CalendarConnection $c): string => $c->getId(), $calendar->findConnectionsByUser($ownerId, 'bob')));
        self::assertCount(1, $calendar->findRulesByUser($ownerId, 'bob'));
        $calendar->deleteConnection($bob);
        self::assertSame([$kept->getId()], array_map(static fn (CalendarRule $rule): string => $rule->getId(), $calendar->findRulesByOwner($ownerId)), 'deleting bob\'s connection keeps bób\'s rules');
    }

    public function testEntriesOfLookAlikeScopesStayApart(): void
    {
        [$owner] = $this->foldedOwner();

        // Ownerless imported entries listed by scope, and the auto-lock (owner, scope) pairs.
        $entries = $this->entries();
        $start   = new DateTimeImmutable('2026-11-10 09:00:00');
        foreach (['bob', 'Bob', 'bób'] as $i => $scope) {
            $data = new ImportedTimeEntryData('clickup:s', 'e-' . $i, 'task-1', 'T', null, $start, $start->modify('+1 hour'), 3600);
            $entries->save(TimeEntry::importedForPerson(new ImportedTimeEntryPerson('p-' . $i, 'P', $scope), $data));
            $entries->save(TimeEntry::imported($owner, new ImportedTimeEntryData('clickup:s', 'o-' . $i, 'task-1', 'T', null, $start, $start->modify('+1 hour'), 3600))->assignScopeKey($scope));
        }
        $window = [new DateTimeImmutable('2026-11-09'), new DateTimeImmutable('2026-11-12')];
        self::assertSame(1, $entries->countWithOwnerless([], ['bob'], ...$window, includeImported: true));
        $page = $entries->findPageWithOwnerless([], ['bob'], ...$window, includeImported: true, offset: 0, limit: 1);
        self::assertSame(['bob'], array_map(static fn (TimeEntry $entry): ?string => $entry->getScopeKey(), $page));
        self::assertSame([], $entries->findPageWithOwnerless([], ['bob'], ...$window, includeImported: true, offset: 1, limit: 5));
        $pairs = array_map(static fn (array $pair): string => $pair['scopeKey'], $entries->ownersWithEntries(...$window));
        sort($pairs);
        self::assertSame(['Bob', 'bob', 'bób'], $pairs, 'every look-alike scope gets its own timesheet lock');
    }

    public function testUnusableScopeKeysAreRefusedBeforeAnyWrite(): void
    {
        $writer = new ImportedTimeEntryWriter($this->entries());
        $start  = new DateTimeImmutable('2026-11-10 09:00:00');
        foreach (['bob ', "bob\n", "bo\u{00A0}b", "bob\u{200B}"] as $i => $key) {
            try {
                $writer->upsertForPerson(new ImportedTimeEntryPerson('p-1', 'P', $key), new ImportedTimeEntryData('clickup:s', 'e-' . $i, 'task-1', 'T', null, $start, $start->modify('+1 hour'), 3600));
                self::fail(sprintf('"%s" is refused', $key));
            } catch (InvalidImportedTimeEntryException $exception) {
                self::assertSame('person.scopeKey', $exception->field);
            }
        }
        self::assertSame(0, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_entries'));
    }

    /**
     * Folds the scope_key columns and returns the owner's reference for the bundle rows, its id and now.
     *
     * @return array{object, string, DateTimeImmutable}
     */
    private function foldedOwner(): array
    {
        $this->foldScopeColumns();
        $resolver = self::getContainer()->get(UserReferenceResolverInterface::class);
        self::assertInstanceOf(UserReferenceResolverInterface::class, $resolver);

        return [$resolver->reference($this->user), (string) $this->user->getId(), new DateTimeImmutable()];
    }

    /**
     * Gives the scope_key columns a folding collation, as on a database without the 1.24.1 ALTER.
     */
    private function foldScopeColumns(): void
    {
        $connection = $this->entityManager()->getConnection();
        $platform   = $connection->getDatabasePlatform();
        foreach (self::TABLES as $table => $nullable) {
            if ($platform instanceof AbstractMySQLPlatform) {
                $connection->executeStatement(sprintf('ALTER TABLE %s MODIFY scope_key VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci %s', $table, $nullable ? 'NULL' : 'NOT NULL'));
            } elseif ($platform instanceof SQLitePlatform) {
                $this->sqliteNoCase($connection, $table);
            }
        }
    }

    private function sqliteNoCase(Connection $connection, string $table): void
    {
        $create  = (string) $connection->fetchOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]);
        $indexes = $connection->fetchFirstColumn("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL", [$table]);
        $folded  = (string) preg_replace('/(scope_key VARCHAR\(64\))/', '$1 COLLATE NOCASE', $create);
        self::assertNotSame($create, $folded, $table);
        $connection->executeStatement('PRAGMA foreign_keys = OFF');
        $connection->executeStatement('PRAGMA legacy_alter_table = ON');
        $connection->executeStatement(sprintf('ALTER TABLE %1$s RENAME TO %1$s_old', $table));
        $connection->executeStatement($folded);
        $connection->executeStatement(sprintf('INSERT INTO %1$s SELECT * FROM %1$s_old', $table));
        $connection->executeStatement(sprintf('DROP TABLE %s_old', $table));
        foreach ($indexes as $index) {
            $connection->executeStatement((string) $index);
        }
        $connection->executeStatement('PRAGMA legacy_alter_table = OFF');
        $connection->executeStatement('PRAGMA foreign_keys = ON');
    }

    /**
     * @param list<Timesheet> $sheets
     *
     * @return list<string>
     */
    private function ids(array $sheets): array
    {
        return array_map(static fn (Timesheet $sheet): string => $sheet->getId(), $sheets);
    }

    private function sheets(): DoctrineOrmTimesheetRepository
    {
        $sheets = self::getContainer()->get(TimesheetRepositoryInterface::class);
        self::assertInstanceOf(DoctrineOrmTimesheetRepository::class, $sheets);

        return $sheets;
    }

    private function ledger(): DoctrineOrmBalanceLedgerRepository
    {
        $ledger = self::getContainer()->get(BalanceLedgerRepositoryInterface::class);
        self::assertInstanceOf(DoctrineOrmBalanceLedgerRepository::class, $ledger);

        return $ledger;
    }

    private function calendar(): DoctrineOrmCalendarRepository
    {
        $calendar = self::getContainer()->get(CalendarConnectionRepositoryInterface::class);
        self::assertInstanceOf(DoctrineOrmCalendarRepository::class, $calendar);

        return $calendar;
    }

    private function entries(): DoctrineOrmTimeEntryRepository
    {
        $entries = self::getContainer()->get(TimeEntryRepositoryInterface::class);
        self::assertInstanceOf(DoctrineOrmTimeEntryRepository::class, $entries);

        return $entries;
    }
}
