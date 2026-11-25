<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Tools\SchemaTool;
use LogicException;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Tests\Support\AllocationSchema;
use Nowo\TimeTrackBundle\Tests\Support\ScopeKeyCollation;

use function dirname;
use function explode;
use function file_get_contents;
use function rtrim;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function trim;

/**
 * Since 1.36.0 (Beacon spec 250): the reference migrations of docs/sql/1.36.0 (task_id nullable on entries and running
 * timers, the timer's description and scope) match the mapping on every platform, keep the rows, refuse a rollback while
 * a row has no task (never deleting time), and are carried by UPGRADING and the demo migration.
 */
final class EntriesWithoutTaskSchemaTest extends FunctionalTestCase
{
    public function testTheMappingAllowsNoTask(): void
    {
        $entries = $this->entityManager()->getClassMetadata(TimeEntry::class);
        $timers  = $this->entityManager()->getClassMetadata(ActiveTimer::class);

        self::assertTrue($entries->getFieldMapping('taskId')->nullable);
        self::assertTrue($timers->getFieldMapping('taskId')->nullable);
        self::assertTrue($timers->getFieldMapping('description')->nullable);
        self::assertSame(64, $timers->getFieldMapping('scopeKey')->length);
    }

    public function testTheReferenceMigrationsMatchTheMappingAndKeepTheRows(): void
    {
        $em   = $this->entityManager();
        $conn = $em->getConnection();
        $em->persist(new TimeEntry($this->user, 'task-1', 'Kept', null, new DateTimeImmutable('2026-01-01 10:00:00'), new DateTimeImmutable('2026-01-01 11:00:00'), 3600, TimeEntrySource::Web));
        $em->persist(new ActiveTimer($this->user, 'task-2', 'Running', null, ClientType::Web, null, new DateTimeImmutable('2026-01-02 09:00:00')));
        $em->flush();

        // Since 2.1.0: the later migration is rolled back first and applied again last.
        AllocationSchema::down($conn);
        $this->runSql($this->platformName() . '-down.sql');
        self::assertTrue($conn->createSchemaManager()->introspectTable('time_track_entries')->getColumn('task_id')->getNotnull());
        $timers = $conn->createSchemaManager()->introspectTable('time_track_active_timers');
        self::assertTrue($timers->getColumn('task_id')->getNotnull());
        self::assertFalse($timers->hasColumn('description'));
        self::assertFalse($timers->hasColumn('scope_key'));
        self::assertSame(['task-1', 'task-2'], [(string) $conn->fetchOne('SELECT task_id FROM time_track_entries'), (string) $conn->fetchOne('SELECT task_id FROM time_track_active_timers')]);

        $this->runSql($this->platformName() . '-up.sql');
        AllocationSchema::up($conn);
        // The exact (binary, NO PAD) collation of the scope_key columns, as UPGRADING 1.24.1 sets it on MySQL / MariaDB.
        ScopeKeyCollation::apply($conn);
        self::assertSame([], (new SchemaTool($em))->getUpdateSchemaSql($em->getMetadataFactory()->getAllMetadata()), 'the up script is exactly the 1.36 mapping');
        self::assertSame(['Kept', 'Running'], [(string) $conn->fetchOne('SELECT task_title_snapshot FROM time_track_entries'), (string) $conn->fetchOne('SELECT task_title_snapshot FROM time_track_active_timers')]);
    }

    public function testTheRollbackRefusesAnEntryWithoutATaskAndChangesNothing(): void
    {
        $em   = $this->entityManager();
        $conn = $em->getConnection();
        $em->persist((new TimeEntry($this->user, null, '', null, new DateTimeImmutable('2026-01-01 10:00:00'), new DateTimeImmutable('2026-01-01 11:00:00'), 3600, TimeEntrySource::Manual))->describe('Customer call'));
        $em->flush();

        try {
            $this->runSql($this->platformName() . '-down.sql');
            self::fail('expected a refusal');
        } catch (DbalException) {
            // What the runner does at the first error; the native clients are in .scripts/check-rollback-clients.sh.
            if ($this->platformName() === 'mysql') {
                $conn->executeStatement('SET SESSION sql_mode = @time_track_sql_mode');
            } else {
                $conn->executeStatement('ROLLBACK');
            }
            if ($this->platformName() === 'sqlite') {
                $conn->executeStatement('PRAGMA foreign_keys = ON');
            }
        }

        self::assertSame(['1', 'Customer call'], [(string) $conn->fetchOne('SELECT COUNT(*) FROM time_track_entries WHERE task_id IS NULL'), (string) $conn->fetchOne('SELECT description FROM time_track_entries')], 'nothing is deleted');
        self::assertSame([], (new SchemaTool($em))->getUpdateSchemaSql($em->getMetadataFactory()->getAllMetadata()), 'nothing is left half-changed');
    }

    public function testTheDemoMigrationAndUpgradingCarryTheReferenceStatements(): void
    {
        $root      = dirname(__DIR__, 2);
        $migration = (string) file_get_contents($root . '/demo/symfony8/migrations/Version20261121000000.php');
        $upgrading = (string) file_get_contents($root . '/docs/UPGRADING.md');
        foreach (['mysql', 'postgresql', 'sqlite'] as $platform) {
            foreach (['up', 'down'] as $direction) {
                foreach ($this->statements($platform . '-' . $direction . '.sql') as $statement) {
                    self::assertStringContainsString($statement . ';', $upgrading);
                    if ($platform === 'mysql') {
                        self::assertTrue(str_contains($migration, "addSql('" . $statement . "')") || str_contains($migration, 'addSql("' . $statement . '")'), $statement);
                    }
                }
            }
        }
    }

    /**
     * The native clients run these files with their own defaults, so the safety must be in the files.
     */
    public function testTheRollbackFilesAreSafeWithTheNativeClientsDefaults(): void
    {
        $sql = static fn (string $file): string => (string) file_get_contents(dirname(__DIR__, 2) . '/docs/sql/1.36.0/' . $file);

        self::assertMatchesRegularExpression('/^\\.bail on$/m', $sql('sqlite-down.sql'));
        self::assertMatchesRegularExpression('/^\\\\set ON_ERROR_STOP on\nBEGIN;$/m', $sql('postgresql-down.sql'));
        self::assertStringEndsWith("COMMIT;\n", $sql('postgresql-down.sql'));
        self::assertStringContainsString('STRICT_ALL_TABLES', $sql('mysql-down.sql'), 'without strict mode NOT NULL turns a missing task into an empty one');
    }

    private function platformName(): string
    {
        $platform = $this->entityManager()->getConnection()->getDatabasePlatform();

        return match (true) {
            $platform instanceof AbstractMySQLPlatform => 'mysql',
            $platform instanceof PostgreSQLPlatform    => 'postgresql',
            $platform instanceof SQLitePlatform        => 'sqlite',
            default                                    => throw new LogicException('Unsupported test platform.'),
        };
    }

    private function runSql(string $file): void
    {
        $conn = $this->entityManager()->getConnection();
        foreach ($this->statements($file) as $statement) {
            // The MySQL scripts name MySQL 8's binary collation; MariaDB's is utf8mb4_nopad_bin (as the 1.36.0 header says).
            $statement = $conn->getDatabasePlatform() instanceof MariaDBPlatform ? str_replace('utf8mb4_0900_bin', 'utf8mb4_nopad_bin', $statement) : $statement;
            $conn->executeStatement(str_replace('REFERENCES users (id)', 'REFERENCES app_user (id)', $statement));
        }
    }

    /**
     * @return list<string>
     */
    private function statements(string $file): array
    {
        $statements = [];
        foreach (explode("\n", (string) file_get_contents(dirname(__DIR__, 2) . '/docs/sql/1.36.0/' . $file)) as $line) {
            $line = trim($line);
            // `.bail on` and `\\set ON_ERROR_STOP on` are for the native clients.
            if ($line !== '' && !str_starts_with($line, '--') && !str_starts_with($line, '.') && !str_starts_with($line, '\\')) {
                $statements[] = rtrim($line, ';');
            }
        }

        return $statements;
    }
}
