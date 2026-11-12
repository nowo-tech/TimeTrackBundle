<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use LogicException;
use Nowo\TimeTrackBundle\Doctrine\TimeTrackEntityManager;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\CalendarConnection;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Enum\CalendarConnectionMode;
use Nowo\TimeTrackBundle\Enum\CalendarProvider;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\TimesheetLockReason;
use Nowo\TimeTrackBundle\Enum\TimesheetPeriodKind;
use Nowo\TimeTrackBundle\Integration\UserReferenceResolverInterface;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefs;
use Nowo\TimeTrackBundle\Repository\ActiveTimerRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\CalendarConnectionRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmCalendarRepository;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimeEntryRepository;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimesheetRepository;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimesheetRepositoryInterface;
use Nowo\TimeTrackBundle\Retention\FormerPersonTimeEntries;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Tests\Fixtures\DddHost\SecurityUser;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Timesheet\Period;
use Nowo\TimeTrackBundle\UserRef\Entity\HostUser;
use Nowo\TimeTrackBundle\UserRef\HostUserRows;

use function array_map;
use function sort;
use function sprintf;

/**
 * 1.23.0 (REQ-ARCH-001 rules 2 and 6, the DDD host case): `user_mapping: id`, `database.mapping_type: xml` and
 * `database.private_entity_manager: true`. The `user_class` is a plain security user that is no Doctrine entity at all;
 * the bundle rows reference its class and id in the bundle-owned `time_track_host_users` table (no foreign key to your
 * users table), ids compare byte for byte, and releasing a user deletes its row with the timers and tokens behind it.
 */
final class DddIdHostTest extends FunctionalTestCase
{
    protected static function timeTrackConfig(): array
    {
        return [
            'user_class'            => SecurityUser::class,
            'accepted_user_classes' => [AppUser::class],
            'user_mapping'          => 'id',
            'database'              => ['mapping_type' => 'xml', 'private_entity_manager' => true],
        ];
    }

    public function testTheBundleRowsReferenceTheBundleOwnedHostUsersOnly(): void
    {
        $schema = $this->entityManager()->getConnection()->createSchemaManager();
        self::assertContains('time_track_host_users', $schema->listTableNames());
        foreach (['time_track_entries', 'time_track_active_timers', 'time_track_client_tokens', 'time_track_timesheets'] as $table) {
            $targets = array_map(static fn (ForeignKeyConstraint $key): string => $key->getForeignTableName(), $schema->listTableForeignKeys($table));
            self::assertContains('time_track_host_users', $targets, $table);
            self::assertNotContains('app_user', $targets, $table);
        }

        // Audits OA-M1 / OA-M2: NO PAD binary collations on MySQL (utf8mb4_0900_bin) and MariaDB (utf8mb4_nopad_bin).
        $connection = $this->entityManager()->getConnection();
        $platform   = $connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $expected = $platform instanceof MariaDBPlatform ? 'utf8mb4_nopad_bin' : 'utf8mb4_0900_bin';
            foreach ([['time_track_host_users', 'id'], ['time_track_entries', 'user_id'], ['time_track_entries', 'person_ref'], ['time_track_timesheets', 'owner_ref'], ['time_track_timesheets', 'decided_by_ref'], ['time_track_balance_ledger', 'owner_ref'], ['time_track_timesheet_events', 'actor_ref'], ['time_track_calendar_connections', 'user_id']] as [$table, $column]) {
                self::assertSame($expected, $connection->fetchOne('SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]), $table . '.' . $column);
            }
        }
    }

    public function testASecurityUserThatIsNoEntityDrivesTheServices(): void
    {
        $ana    = new SecurityUser('ext-7', 'ana@example.com');
        $timers = $this->timers();
        $timers->start($ana, 'task-1', ClientType::Web);
        $entry = $timers->stop($ana);
        self::assertInstanceOf(TimeEntry::class, $entry);

        self::assertSame('ext-7', $entry->getUserId());
        $owner = $entry->getUser();
        self::assertInstanceOf(HostUser::class, $owner);
        $ref = TimeTrackUserRefs::fromUser($owner);
        self::assertSame([SecurityUser::class, 'ext-7'], [$ref->userClass, $ref->id], 'the twins see your class and id');
        self::assertSame([['id' => 'ext-7', 'user_class' => SecurityUser::class]], $this->entityManager()->getConnection()->fetchAllAssociative('SELECT id, user_class FROM time_track_host_users'));
        self::assertCount(1, $this->entries()->findByUserAndPeriod('ext-7', new DateTimeImmutable('-1 day'), new DateTimeImmutable('+1 day')));

        // A second write of the same user reuses its row.
        $timers->start($ana, 'task-2', ClientType::Web);
        self::assertSame(1, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_host_users'));
    }

    public function testTheClientApiWithAnAcceptedSecurityUser(): void
    {
        $token = $this->login();
        $me    = $this->api('GET', '/api/time-track/me', null, $token);
        self::assertSame((string) $this->user->getId(), $me['userId'] ?? null);
        $this->api('POST', '/api/time-track/timer/start', ['taskId' => 'task-1'], $token);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('task-1', $this->api('GET', '/api/time-track/timer', null, $token)['timer']['taskId'] ?? null);
    }

    public function testUserIdsCompareByteForByte(): void
    {
        $this->timers()->start(new SecurityUser('abc', 'lower'), 'task-1', ClientType::Web);
        $this->timers()->stop(new SecurityUser('abc', 'lower'));
        $this->timers()->start(new SecurityUser('abc', 'lower'), 'task-2', ClientType::Web);

        $period = [new DateTimeImmutable('-1 day'), new DateTimeImmutable('+1 day')];
        self::assertSame([], $this->entries()->findByUserAndPeriod('ABC', ...$period));
        self::assertCount(1, $this->entries()->findByUserAndPeriod('abc', ...$period));
        $timers = self::getContainer()->get(ActiveTimerRepositoryInterface::class);
        self::assertInstanceOf(ActiveTimerRepositoryInterface::class, $timers);
        self::assertNull($timers->findByUserId('ABC'));
        self::assertInstanceOf(ActiveTimer::class, $timers->findByUserId('abc'));
        self::assertNull($this->timers()->getActive(new SecurityUser('ABC', 'upper')));
    }

    public function testIdsWithBlanksOrInvisibleCharactersAreRefusedBeforeAnyWrite(): void
    {
        // Audit OA-M1: PAD SPACE collations (and SQL Server) ignore trailing blanks; such ids never reach the database.
        $this->timers()->start(new SecurityUser('abc', 'abc'), 'task-1', ClientType::Web);
        foreach (['abc ', ' abc', "abc\u{200B}", "ab\u{00A0}c", "abc\t"] as $id) {
            try {
                $this->timers()->start(new SecurityUser($id, 'look-alike'), 'task-2', ClientType::Web);
                self::fail(sprintf('"%s" is refused', $id));
            } catch (LogicException $exception) {
                self::assertStringContainsString('no leading or trailing blank', $exception->getMessage());
            }
        }
        self::assertSame(['abc'], $this->entityManager()->getConnection()->fetchFirstColumn('SELECT id FROM time_track_host_users'));

        $people = self::getContainer()->get(FormerPersonTimeEntries::class);
        self::assertInstanceOf(FormerPersonTimeEntries::class, $people);
        try {
            $people->release(new SecurityUser('abc ', 'look-alike'));
            self::fail('a look-alike id is never released');
        } catch (LogicException) {
        }
        $rows = self::getContainer()->get(HostUserRows::class);
        self::assertInstanceOf(HostUserRows::class, $rows);
        self::assertSame(0, $rows->remove('abc '));
        self::assertSame(['abc'], $this->entityManager()->getConnection()->fetchFirstColumn('SELECT id FROM time_track_host_users'));
        self::assertInstanceOf(ActiveTimer::class, $this->timers()->getActive(new SecurityUser('abc', 'abc')));
    }

    public function testTimesheetsCalendarRowsAndReferencesCompareExactly(): void
    {
        // Audits OA-M1 / OA-M2: on MySQL / MariaDB the user and reference columns use a NO PAD binary collation, and the
        // stores re-check the ids in PHP: case, accent and trailing-blank look-alikes never see abc's rows.
        $resolver = self::getContainer()->get(UserReferenceResolverInterface::class);
        self::assertInstanceOf(UserReferenceResolverInterface::class, $resolver);
        $owner = $resolver->reference(new SecurityUser('abc', 'abc'));
        $now   = new DateTimeImmutable();
        $sheet = new Timesheet($owner, 'abc', 'org-a', Period::fromKey(TimesheetPeriodKind::Week, $now->format('o-\WW')), 'UTC', $now);
        $sheet->applyLock($now, TimesheetLockReason::Manual);
        $sheets = self::getContainer()->get(TimesheetRepositoryInterface::class);
        self::assertInstanceOf(DoctrineOrmTimesheetRepository::class, $sheets);
        $sheets->save($sheet);
        $calendar = self::getContainer()->get(CalendarConnectionRepositoryInterface::class);
        self::assertInstanceOf(DoctrineOrmCalendarRepository::class, $calendar);
        $calendar->saveConnection(new CalendarConnection($owner, 'org-a', CalendarProvider::Google, CalendarConnectionMode::Server, 'cred', 'v1', $now));
        $this->timers()->start(new SecurityUser('abc', 'abc'), 'task-1', ClientType::Web);
        $this->timers()->stop(new SecurityUser('abc', 'abc'));
        $this->privateManager()->clear();

        $from = $sheet->getPeriodStart();
        $to   = $now->modify('+7 days')->format('Y-m-d');
        $day  = [new DateTimeImmutable('-1 day'), new DateTimeImmutable('+1 day')];
        self::assertCount(1, $sheets->findGuarding('abc', null, $from, $to));
        self::assertCount(1, $sheets->findGuardingByOwnerRef('abc', null, $from, $to));
        self::assertCount(1, $sheets->findByOwner('abc', 'org-a', $from, $to));
        self::assertCount(1, $sheets->findQueue('org-a', ['abc'], null, 10));
        self::assertInstanceOf(Timesheet::class, $sheets->findForPeriod('abc', 'org-a', TimesheetPeriodKind::Week, $from));
        self::assertCount(1, $calendar->findConnectionsByUser('abc'));
        self::assertCount(1, $calendar->findConnectionsDue($now->modify('+1 day'), 'abc', 10));
        self::assertSame(1, $this->entries()->countByUsersAndPeriod(['abc'], ...$day));
        foreach (['ABC', 'àbc', 'abc '] as $lookAlike) {
            self::assertSame([], $sheets->findGuarding($lookAlike, null, $from, $to), $lookAlike);
            self::assertSame([], $sheets->findGuardingByOwnerRef($lookAlike, null, $from, $to), $lookAlike);
            self::assertSame([], $sheets->transactional(static fn (): array => $sheets->findGuardingForMove($lookAlike, null, $from, $to)), $lookAlike);
            self::assertSame([], $sheets->findByOwner($lookAlike, 'org-a', $from, $to), $lookAlike);
            self::assertSame([], $sheets->findQueue('org-a', [$lookAlike], null, 10), $lookAlike);
            self::assertNull($sheets->findForPeriod($lookAlike, 'org-a', TimesheetPeriodKind::Week, $from), $lookAlike);
            self::assertNull($sheets->findLatestLockedBefore($lookAlike, 'org-a', $to), $lookAlike);
            self::assertSame([], $calendar->findConnectionsByUser($lookAlike), $lookAlike);
            self::assertNull($calendar->findConnectionFor($lookAlike, 'org-a', CalendarProvider::Google), $lookAlike);
            self::assertSame([], $calendar->findRulesByOwner($lookAlike), $lookAlike);
            self::assertSame(0, $this->entries()->countByUsersAndPeriod([$lookAlike], ...$day), $lookAlike);
            self::assertSame([], $this->entries()->findByUserAndPeriod($lookAlike, ...$day), $lookAlike);
        }
    }

    public function testReleasingAUserDeletesItsRowWithItsTimersAndTokens(): void
    {
        $connection = $this->entityManager()->getConnection();
        if ($connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $connection->executeStatement('PRAGMA foreign_keys = ON');
        }
        $leaving = new SecurityUser('leaving-1', 'leaving');
        $staying = new SecurityUser('staying-1', 'staying');
        $this->timers()->start($leaving, 'task-1', ClientType::Web);
        $entry = $this->timers()->stop($leaving);
        self::assertInstanceOf(TimeEntry::class, $entry);
        $this->timers()->start($leaving, 'task-2', ClientType::Web);
        $this->timers()->start($staying, 'task-3', ClientType::Web);
        $auth = self::getContainer()->get(ClientAuthService::class);
        self::assertInstanceOf(ClientAuthService::class, $auth);
        $auth->issueToken($leaving, ClientType::Desktop);
        $auth->issueToken($staying, ClientType::Desktop);

        $people = self::getContainer()->get(FormerPersonTimeEntries::class);
        self::assertInstanceOf(FormerPersonTimeEntries::class, $people);
        $people->release($leaving, 'Leaving Person');

        $ids = $connection->fetchFirstColumn('SELECT id FROM time_track_host_users');
        sort($ids);
        self::assertSame(['staying-1'], $ids);
        self::assertSame(['staying-1'], $connection->fetchFirstColumn('SELECT user_id FROM time_track_active_timers'));
        self::assertSame(['staying-1'], $connection->fetchFirstColumn('SELECT user_id FROM time_track_client_tokens'));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM time_track_entries WHERE user_id IS NULL AND person_ref IS NOT NULL'), 'the stopped and the saved timer are kept as a former person\'s');
    }

    private function privateManager(): TimeTrackEntityManager
    {
        $manager = self::getContainer()->get(TimeTrackEntityManager::class);
        self::assertInstanceOf(TimeTrackEntityManager::class, $manager);

        return $manager;
    }

    private function timers(): TimerService
    {
        $timers = self::getContainer()->get(TimerService::class);
        self::assertInstanceOf(TimerService::class, $timers);

        return $timers;
    }

    private function entries(): DoctrineOrmTimeEntryRepository
    {
        $entries = self::getContainer()->get(TimeEntryRepositoryInterface::class);
        self::assertInstanceOf(DoctrineOrmTimeEntryRepository::class, $entries);

        return $entries;
    }
}
