<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Database;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Functional\FunctionalTestCase;
use Nowo\TimeTrackBundle\Tests\Stub\StubTimesheetApproverProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedWorkingCalendar;
use Nowo\TimeTrackBundle\Tests\Support\FunctionalKernel;

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
 * 1.27.0 (Beacon spec 239): lock waits and concurrent processes on a real server (MySQL 8.4, PostgreSQL 16; CI job
 * "Load readiness", run with `--fail-on-skipped`). Skipped on SQLite.
 *
 * - A lock wait answers 409 + Retry-After (1.26: the raw lock wait timeout, a 500).
 * - Two processes adding ledger lines for one owner at once all chain (1.26: lines refused as `conflict`).
 * - Two processes starting timers for one user at once never detach the application's entities (1.26: every lost start
 *   reset the application's manager).
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
        self::assertFalse($this->entityManager()->getConnection()->isTransactionActive(), 'the connection is back at its entry level');
        self::assertSame(0, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_entries'));

        $this->api('POST', '/api/time-track/entries', $payload, $token, ['HTTP_IDEMPOTENCY_KEY' => 'lock-1']);
        self::assertResponseStatusCodeSame(201, 'the retry succeeds');
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
