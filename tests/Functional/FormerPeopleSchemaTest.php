<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Tools\SchemaTool;
use LogicException;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;

use function dirname;
use function explode;
use function file_get_contents;
use function rtrim;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strtoupper;
use function trim;

/**
 * Since 1.15: the reference migrations of docs/sql/1.15.0 match the mapping on every platform and keep the rows.
 */
final class FormerPeopleSchemaTest extends FunctionalTestCase
{
    public function testTheMappingKeepsTheEntryWhenTheUserGoes(): void
    {
        $metadata = $this->entityManager()->getClassMetadata(TimeEntry::class);
        $column   = $metadata->getAssociationMapping('user')->toArray()['joinColumns'][0] ?? [];

        self::assertTrue($column['nullable'] ?? false);
        self::assertSame('SET NULL', strtoupper((string) ($column['onDelete'] ?? '')));
        self::assertSame(['person_ref'], $metadata->table['indexes']['idx_time_track_entries_person_ref']['columns'] ?? null);
        self::assertSame(69, $metadata->getFieldMapping('personRef')->length, '"user:" and an owner_ref of 64');
        self::assertSame(['owner_ref', 'period_end'], $this->entityManager()->getClassMetadata(Timesheet::class)->table['indexes']['idx_time_track_timesheets_owner_ref']['columns'] ?? null);
    }

    public function testTheReferenceMigrationsMatchTheMappingAndKeepTheRows(): void
    {
        $em   = $this->entityManager();
        $conn = $em->getConnection();
        $em->persist(new TimeEntry($this->user, 'task-1', 'Kept', null, new DateTimeImmutable('2026-01-01 10:00:00'), new DateTimeImmutable('2026-01-01 11:00:00'), 3600, TimeEntrySource::Web));
        $em->flush();

        // Since 1.25.0: the later migration is rolled back first and applied again last.
        $this->runSql($this->platformName() . '-down.sql', '1.25.0');
        $this->runSql($this->platformName() . '-down.sql');
        $table = $conn->createSchemaManager()->introspectTable('time_track_entries');
        self::assertTrue($table->getColumn('user_id')->getNotnull());
        self::assertFalse($table->hasColumn('person_name_snapshot'));
        self::assertFalse($table->hasColumn('person_ref'));
        self::assertFalse($table->hasColumn('anonymised_at'));
        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM time_track_entries'));

        $this->runSql($this->platformName() . '-up.sql');
        $this->runSql($this->platformName() . '-up.sql', '1.25.0');
        self::assertSame([], (new SchemaTool($em))->getUpdateSchemaSql($em->getMetadataFactory()->getAllMetadata()), 'the up script is exactly the 1.15 mapping');
        self::assertSame(['Kept', '3600'], [(string) $conn->fetchOne('SELECT task_title_snapshot FROM time_track_entries'), (string) $conn->fetchOne('SELECT duration_seconds FROM time_track_entries')]);
    }

    public function testTheRollbackRefusesAnEntryWithNoUserAndChangesNothing(): void
    {
        $em   = $this->entityManager();
        $conn = $em->getConnection();
        $em->persist(new TimeEntry(null, 'task-1', 'Kept', null, new DateTimeImmutable('2026-01-01 10:00:00'), new DateTimeImmutable('2026-01-01 11:00:00'), 3600, TimeEntrySource::Import));
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

        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM time_track_entries'), 'nothing is deleted');
        self::assertSame([], (new SchemaTool($em))->getUpdateSchemaSql($em->getMetadataFactory()->getAllMetadata()), 'nothing changed: columns, indexes and the SET NULL key are all there');
    }

    public function testTheDemoMigrationAndUpgradingCarryTheReferenceStatements(): void
    {
        $root      = dirname(__DIR__, 2);
        $migration = str_replace('FK_TIME_TRACK_ENTRIES_USER', 'FK_7CB77957A76ED395', (string) file_get_contents($root . '/demo/symfony8/migrations/Version20261108000000.php'));
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
        $sql = static fn (string $file): string => (string) file_get_contents(dirname(__DIR__, 2) . '/docs/sql/1.15.0/' . $file);

        self::assertMatchesRegularExpression('/^\\.bail on$/m', $sql('sqlite-down.sql'));
        self::assertMatchesRegularExpression('/^\\\\set ON_ERROR_STOP on\nBEGIN;$/m', $sql('postgresql-down.sql'));
        self::assertStringEndsWith("COMMIT;\n", $sql('postgresql-down.sql'));
        self::assertMatchesRegularExpression('/^ALTER TABLE time_track_entries DROP FOREIGN KEY [^;]+, CHANGE user_id user_id INT NOT NULL;$/m', $sql('mysql-down.sql'), 'one atomic ALTER');
        self::assertStringContainsString('STRICT_ALL_TABLES', $sql('mysql-down.sql'), 'without strict mode NOT NULL turns a null user into 0');
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

    private function runSql(string $file, string $version = '1.15.0'): void
    {
        $conn = $this->entityManager()->getConnection();
        foreach ($this->statements($file, $version) as $statement) {
            $conn->executeStatement(str_replace('REFERENCES users (id)', 'REFERENCES app_user (id)', $statement));
        }
    }

    /**
     * @return list<string>
     */
    private function statements(string $file, string $version = '1.15.0'): array
    {
        $statements = [];
        foreach (explode("\n", (string) file_get_contents(dirname(__DIR__, 2) . '/docs/sql/' . $version . '/' . $file)) as $line) {
            $line = trim($line);
            // `.bail on` and `\\set ON_ERROR_STOP on` are for the native clients.
            if ($line !== '' && !str_starts_with($line, '--') && !str_starts_with($line, '.') && !str_starts_with($line, '\\')) {
                $statements[] = rtrim($line, ';');
            }
        }

        return $statements;
    }
}
