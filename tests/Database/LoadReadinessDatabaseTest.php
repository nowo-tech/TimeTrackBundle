<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Database;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Nowo\TimeTrackBundle\Client\ClientTokenRevoker;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Entry\TimeEntryCorrection;
use Nowo\TimeTrackBundle\Entry\TimeEntryOperationsInterface;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\ClientSessionRevocation;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\EventSubscriber\StorageFailureSubscriber;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryData;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryWriter;
use Nowo\TimeTrackBundle\Port\Internal\TimesheetScopeProviderInterface;
use Nowo\TimeTrackBundle\Query\TimeEntryQueryInterface;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimeEntryRepository;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Functional\FunctionalTestCase;
use Nowo\TimeTrackBundle\Tests\Stub\StubTimesheetApproverProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedWorkingCalendar;
use Nowo\TimeTrackBundle\Tests\Support\FunctionalKernel;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;

use function array_map;
use function fclose;
use function fwrite;
use function json_decode;
use function proc_close;
use function proc_open;
use function stream_get_contents;

use const DATE_ATOM;
use const PHP_BINARY;

/**
 * 1.27.0 (Beacon spec 239): lock waits and concurrent processes on a real server (MySQL 8.4, MariaDB 11.4, PostgreSQL 16; CI job
 * "Load readiness", run with `--fail-on-skipped`). Skipped on SQLite.
 *
 * - A lock wait answers 409 + Retry-After (1.26: the raw lock wait timeout, a 500).
 * - Two processes adding ledger lines for one owner at once all chain (1.26: lines refused as `conflict`).
 * - Two processes starting timers for one user at once never detach the application's entities (1.26: every lost start
 *   reset the application's manager).
 * - 1.36.0: two processes starting timers without a task for one user leave exactly one running.
 * - 1.34.0: two processes revoking the same client sessions by id revoke each once; a revocation behind a row lock
 *   fails with nothing committed (CONFLICT_001) and the retry revokes.
 * - 1.40.0: two processes correcting one entry by id with the version they read never lose an update (every lost race
 *   is `conflict`); a correction behind the owner's lock fails with nothing committed and the retry corrects; two
 *   processes revoking the same sessions through `revokeSession()` revoke each once.
 */
final class LoadReadinessDatabaseTest extends FunctionalTestCase
{
    private AppUser $approver;

    protected static function timeTrackConfig(): array
    {
        return [
            'manual_entries' => ['enabled' => true],
            'timesheets'     => ['enabled' => true, 'scope_provider' => FixedScopeProvider::class, 'approver_provider' => StubTimesheetApproverProvider::class],
            'working_time'   => ['enabled' => true, 'calendar_provider' => FixedWorkingCalendar::class],
        ];
    }

    protected function setUp(): void
    {
        if (FunctionalKernel::databaseUrl() === 'sqlite:///:memory:') {
            self::markTestSkipped('Set TIME_TRACK_TEST_DATABASE_URL to a MySQL or PostgreSQL database.');
        }
        parent::setUp();
        $this->approver = $this->createUser('boss@example.com');
    }

    public function testALockWaitAnswers409WithRetryAfter(): void
    {
        $auth = self::getContainer()->get(ClientAuthService::class);
        self::assertInstanceOf(ClientAuthService::class, $auth);
        $token   = (string) $auth->issueToken($this->user, ClientType::Desktop, null, [ClientScope::EntriesWrite])['token'];
        $start   = (new DateTimeImmutable('today -2 days'))->setTime(9, 0);
        $payload = ['taskId' => 'task-1', 'startedAt' => $start->format(DATE_ATOM), 'endedAt' => $start->modify('+1 hour')->format(DATE_ATOM), 'source' => 'manual'];
        $this->shortLockWait($this->entityManager()->getConnection());

        $blocker = $this->otherConnection();
        $blocker->beginTransaction();
        $blocker->executeQuery('SELECT id FROM app_user WHERE id = ? FOR UPDATE', [$this->user->getId()]);
        try {
            $body = $this->api('POST', '/api/time-track/entries', $payload, $token, ['HTTP_IDEMPOTENCY_KEY' => 'lock-1']);
        } finally {
            $blocker->rollBack(); // @phpstan-ignore frankenphp.worker.noRollbackAfterNestedBegin (the test plays another request's transaction)
            $blocker->close();
        }

        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('Retry-After', '1');
        self::assertSame('unavailable', $body['error'] ?? null);
        self::assertSame('CONFLICT_001', $body['code'] ?? null, 'REQ-RUNTIME-003 rule 6: nothing committed');
        self::assertFalse($this->entityManager()->getConnection()->isTransactionActive(), 'the connection is back at its entry level');
        self::assertSame(0, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_entries'));

        $this->api('POST', '/api/time-track/entries', $payload, $token, ['HTTP_IDEMPOTENCY_KEY' => 'lock-1']);
        self::assertResponseStatusCodeSame(201, 'the retry succeeds');
    }

    /**
     * QO-L3 (1.31.0): while an import plans (reads the stored rows, checks the period locks) and writes, another
     * connection cannot take the owner's row lock: the import holds it, so a submit or approval of that owner waits.
     */
    public function testAnImportHoldsTheOwnersWriteLockWhilePlanningAndWriting(): void
    {
        $other = $this->otherConnection();
        $held  = [];
        $id    = $this->user->getId();
        $probe = static function () use ($other, $id): bool {
            try {
                $other->executeQuery('SELECT id FROM app_user WHERE id = ? FOR UPDATE NOWAIT', [$id]);

                return false;
            } catch (DbalException) {
                return true; // The lock is held by the import.
            }
        };
        $scopes = $this->createStub(TimesheetScopeProviderInterface::class);
        $scopes->method('scopeKeyForTask')->willReturnCallback(static function () use ($probe, &$held): string {
            $held[] = $probe();

            return '';
        });
        $repository = self::getContainer()->get(TimeEntryRepositoryInterface::class);
        self::assertInstanceOf(DoctrineOrmTimeEntryRepository::class, $repository);
        $guard = self::getContainer()->get(PeriodLockGuard::class);
        self::assertInstanceOf(PeriodLockGuard::class, $guard);
        $start = new DateTimeImmutable('2026-03-02 09:00:00');

        try {
            (new ImportedTimeEntryWriter($repository, periodLockGuard: $guard, scopeProvider: $scopes))
                ->upsert($this->user, new ImportedTimeEntryData('clickup:wave', 'e-1', 'task-1', 'T', 'board-1', $start, $start->modify('+1 hour'), 3600));
            self::assertSame([true], $held, 'the owner row was locked while the import planned');
            self::assertFalse($probe(), 'and free again once it committed');
        } finally {
            $other->close();
        }
    }

    public function testTwoProcessesAddingLedgerLinesForOneOwnerAllChain(): void
    {
        $reports = $this->workers('ledger', 15, 2);

        foreach ($reports as $report) {
            self::assertSame(15, $report['ok'], 'no line refused: ' . json_encode($report));
        }
        $rows = $this->entityManager()->getConnection()->fetchAllAssociative('SELECT hash, prev_hash FROM time_track_balance_ledger');
        self::assertCount(30, $rows);
        $previous = array_map(static fn (array $row): string => (string) $row['prev_hash'], $rows);
        self::assertSame($previous, array_unique($previous), 'no fork: every line follows a different one');
    }

    public function testTwoProcessesStartingTimersForOneUserNeverDetachTheApplicationsEntities(): void
    {
        $reports = $this->workers('start', 25, 2);

        foreach ($reports as $report) {
            self::assertSame([], $report['errors'], (string) json_encode($report));
            self::assertSame(0, $report['detached'], 'a lost start never resets the application\'s manager');
        }
        self::assertLessThanOrEqual(1, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_active_timers'));
    }

    /**
     * 1.36.0 (REQ-RUNTIME-003, Beacon spec 250): two processes starting timers without a task for one user at the same
     * moment, again and again: exactly one start wins, every other one is refused as `running` (no error, nothing
     * detached), and one timer without a task runs.
     */
    public function testTwoProcessesStartingTimersWithoutATaskLeaveOnlyOneRunning(): void
    {
        $reports = $this->workers('start-without-task', 10, 2);

        $won = 0;
        foreach ($reports as $report) {
            self::assertSame([], $report['errors'], (string) json_encode($report));
            self::assertSame(0, $report['detached']);
            $won += $report['ok'];
            self::assertSame(10 - $report['ok'], $report['refused']['running'] ?? 0);
        }
        self::assertSame(1, $won, 'one start wins');
        $connection = $this->entityManager()->getConnection();
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM time_track_active_timers'));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM time_track_active_timers WHERE task_id IS NULL AND scope_key = ?', ['org-a']));
    }

    /**
     * 1.34.0 (REQ-RUNTIME-003): two processes revoking the same sessions by id at once — each session is revoked exactly
     * once, the loser answers not_found (no error, nothing detached from the application's manager).
     */
    public function testTwoProcessesRevokingTheSameSessionsRevokeEachOnce(): void
    {
        $auth = self::getContainer()->get(ClientAuthService::class);
        self::assertInstanceOf(ClientAuthService::class, $auth);
        for ($i = 0; $i < 20; ++$i) {
            $auth->issueToken($this->user, ClientType::Desktop, 'app-password-' . $i);
        }

        $reports = $this->workers('revoke', 20, 2);

        $revoked = 0;
        foreach ($reports as $report) {
            self::assertSame([], $report['errors'], (string) json_encode($report));
            self::assertSame(0, $report['detached']);
            self::assertSame(20, $report['ok'] + ($report['refused']['not_found'] ?? 0));
            $revoked += $report['ok'];
        }
        self::assertSame(20, $revoked, 'every session revoked exactly once');
        self::assertSame(0, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_client_tokens'));
    }

    /**
     * 1.34.0 (REQ-RUNTIME-003 rule 6): a revocation that waits on a locked session row fails with nothing committed
     * (CONFLICT_001, retry safe), the token still works, and the retry revokes it.
     */
    public function testARevocationBehindALockFailsWithNothingCommittedAndTheRetryRevokes(): void
    {
        $auth = self::getContainer()->get(ClientAuthService::class);
        self::assertInstanceOf(ClientAuthService::class, $auth);
        $revoker = self::getContainer()->get(ClientTokenRevoker::class);
        self::assertInstanceOf(ClientTokenRevoker::class, $revoker);
        $token = (string) $auth->issueToken($this->user, ClientType::Desktop, 'app-password-lock')['token'];
        $id    = (string) $this->entityManager()->getConnection()->fetchOne('SELECT id FROM time_track_client_tokens');
        $this->shortLockWait($this->entityManager()->getConnection());

        $blocker = $this->otherConnection();
        $blocker->beginTransaction();
        $blocker->executeQuery('SELECT id FROM time_track_client_tokens WHERE id = ? FOR UPDATE', [$id]);
        $failure = null;
        try {
            $revoker->revokeById($id, $this->user);
        } catch (TransientStorageFailure $exception) {
            $failure = $exception;
        } finally {
            $blocker->rollBack(); // @phpstan-ignore frankenphp.worker.noRollbackAfterNestedBegin (the test plays another request's transaction)
            $blocker->close();
        }

        self::assertInstanceOf(TransientStorageFailure::class, $failure, 'the lock wait surfaces as a transient failure on every platform');
        self::assertFalse(StorageFailureSubscriber::committed($failure), 'a bundle route would answer 409 + Retry-After');
        self::assertFalse($this->entityManager()->getConnection()->isTransactionActive(), 'the connection is back at its entry level');
        self::assertSame(ConflictCode::RetrySafe, ConflictCode::forFailure($failure));
        self::assertSame('CONFLICT_001', ConflictCode::forFailure($failure)->value);
        self::assertTrue($this->entityManager()->isOpen(), 'the application\'s manager is left open');
        $this->api('GET', '/api/time-track/me', null, $token);
        self::assertResponseStatusCodeSame(200, 'nothing was committed: the session still works');

        self::assertSame(ClientSessionRevocation::Revoked, $revoker->revokeById($id, $this->user), 'the retry succeeds');
        $this->api('GET', '/api/time-track/me', null, $token);
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * 1.40.0 (REQ-RUNTIME-003 rule 4, optimistic version): two processes increment a metadata counter of one entry by
     * read-version-write at the same moment. Every success is counted in the row (no lost update); every lost race is the
     * domain answer `conflict` (or a transient failure with nothing committed); no error, nothing detached.
     */
    public function testTwoProcessesCorrectingOneEntryNeverLoseAnUpdate(): void
    {
        $start = (new DateTimeImmutable('today -2 days'))->setTime(9, 0);
        $this->entityManager()->persist(new TimeEntry($this->user, 'task-1', 'Task', null, $start, $start->modify('+1 hour'), 3600, TimeEntrySource::Web));
        $this->entityManager()->flush();

        $reports = $this->workers('correct', 15, 2);

        $won = 0;
        foreach ($reports as $report) {
            self::assertSame([], $report['errors'], (string) json_encode($report));
            self::assertSame(0, $report['detached']);
            self::assertSame(15, $report['ok'] + ($report['refused']['conflict'] ?? 0) + ($report['refused']['transient'] ?? 0), (string) json_encode($report));
            $won += $report['ok'];
        }
        $metadata = json_decode((string) $this->entityManager()->getConnection()->fetchOne('SELECT metadata FROM time_track_entries'), true);
        self::assertIsArray($metadata);
        self::assertSame($won, $metadata['n'] ?? null, 'every committed correction is in the row: no lost update');
        self::assertGreaterThan(0, $won);
    }

    /**
     * 1.40.0 (REQ-RUNTIME-003 rule 6): a correction waiting on the owner's lock fails with nothing committed
     * (CONFLICT_001), the connection is back at its entry level, and the retry with the same version corrects.
     */
    public function testACorrectionBehindTheOwnersLockFailsWithNothingCommittedAndTheRetryCorrects(): void
    {
        $start = (new DateTimeImmutable('today -2 days'))->setTime(9, 0);
        $entry = new TimeEntry($this->user, 'task-1', 'Task', null, $start, $start->modify('+1 hour'), 3600, TimeEntrySource::Web);
        $this->entityManager()->persist($entry);
        $this->entityManager()->flush();
        $operations = self::getContainer()->get(TimeEntryOperationsInterface::class);
        self::assertInstanceOf(TimeEntryOperationsInterface::class, $operations);
        $query = self::getContainer()->get(TimeEntryQueryInterface::class);
        self::assertInstanceOf(TimeEntryQueryInterface::class, $query);
        $version = ($query->find($entry->getId()) ?? self::fail())->version();
        $this->shortLockWait($this->entityManager()->getConnection());

        $blocker = $this->otherConnection();
        $blocker->beginTransaction();
        $blocker->executeQuery('SELECT id FROM app_user WHERE id = ? FOR UPDATE', [$this->user->getId()]);
        $failure = null;
        try {
            $operations->correct((string) $this->user->getId(), $entry->getId(), $version, new TimeEntryCorrection(durationSeconds: 60));
        } catch (TransientStorageFailure $exception) {
            $failure = $exception;
        } finally {
            $blocker->rollBack(); // @phpstan-ignore frankenphp.worker.noRollbackAfterNestedBegin (the test plays another request's transaction)
            $blocker->close();
        }

        self::assertInstanceOf(TransientStorageFailure::class, $failure);
        self::assertSame('CONFLICT_001', ConflictCode::forFailure($failure)->value);
        self::assertFalse($this->entityManager()->getConnection()->isTransactionActive());
        self::assertSame(3600, (int) $this->entityManager()->getConnection()->fetchOne('SELECT duration_seconds FROM time_track_entries'), 'nothing committed');

        self::assertSame(60, $operations->correct((string) $this->user->getId(), $entry->getId(), $version, new TimeEntryCorrection(durationSeconds: 60))->after->entry->durationSeconds, 'the retry corrects');
        self::assertSame(60, (int) $this->entityManager()->getConnection()->fetchOne('SELECT duration_seconds FROM time_track_entries'));
    }

    /**
     * 1.40.0: the same as {@see self::testTwoProcessesRevokingTheSameSessionsRevokeEachOnce()} through the id-based
     * `ClientSessionRevokerInterface::revokeSession()`.
     */
    public function testTwoProcessesRevokingSessionsByIdsRevokeEachOnce(): void
    {
        $auth = self::getContainer()->get(ClientAuthService::class);
        self::assertInstanceOf(ClientAuthService::class, $auth);
        for ($i = 0; $i < 20; ++$i) {
            $auth->issueToken($this->user, ClientType::Desktop, 'app-password-' . $i);
        }

        $revoked = 0;
        foreach ($this->workers('revoke-session', 20, 2) as $report) {
            self::assertSame([], $report['errors'], (string) json_encode($report));
            $revoked += $report['ok'];
        }
        self::assertSame(20, $revoked, 'every session revoked exactly once');
        self::assertSame(0, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_client_tokens'));
    }

    /**
     * Runs `$processes` workers of the action at the same moment.
     *
     * @return list<array{ok: int, refused: array<string, int>, errors: array<string, int>, detached: int}>
     */
    private function workers(string $action, int $count, int $processes): array
    {
        $running = [];
        for ($i = 0; $i < $processes; ++$i) {
            $pipes = [];
            // @phpstan-ignore-next-line (a test helper: the workers end after their loop, and the CI step has a timeout)
            $process = proc_open([PHP_BINARY, __DIR__ . '/worker.php', $action, (string) $count, (string) $this->user->getId(), (string) $this->approver->getId()], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['TIME_TRACK_TEST_DATABASE_URL' => FunctionalKernel::databaseUrl()] + getenv());
            self::assertIsResource($process);
            $running[] = [$process, $pipes];
        }
        usleep(1_500_000); // Every worker booted its kernel.
        foreach ($running as [, $pipes]) {
            fwrite($pipes[0], "go\n");
            fclose($pipes[0]);
        }
        $reports = [];
        foreach ($running as [$process, $pipes]) {
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            proc_close($process);
            /** @var array{ok: int, refused: array<string, int>, errors: array<string, int>, detached: int}|null $report */
            $report = json_decode($out !== '' ? $out : 'null', true);
            self::assertIsArray($report, 'worker output: ' . $out . $err);
            $reports[] = $report;
        }

        return $reports;
    }

    private function otherConnection(): Connection
    {
        $params = (new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']))->parse(FunctionalKernel::databaseUrl());

        return DriverManager::getConnection($params);
    }

    private function shortLockWait(Connection $connection): void
    {
        $platform = $connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
        } elseif ($platform instanceof PostgreSQLPlatform) {
            $connection->executeStatement("SET lock_timeout = '1s'");
        }
    }
}
