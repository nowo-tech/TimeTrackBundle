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
    }

    public function testTheReferenceMigrationsMatchTheMappingAndKeepTheRows(): void
    {
        $em   = $this->entityManager();
        $conn = $em->getConnection();
        $em->persist(new TimeEntry($this->user, 'task-1', 'Kept', null, new DateTimeImmutable('2026-01-01 10:00:00'), new DateTimeImmutable('2026-01-01 11:00:00'), 3600, TimeEntrySource::Web));
        $em->flush();

        $this->runSql($this->platformName() . '-down.sql');
        $table = $conn->createSchemaManager()->introspectTable('time_track_entries');
        self::assertTrue($table->getColumn('user_id')->getNotnull());
        self::assertFalse($table->hasColumn('person_name_snapshot'));
        self::assertFalse($table->hasColumn('person_ref'));
        self::assertFalse($table->hasColumn('anonymised_at'));
        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM time_track_entries'));

        $this->runSql($this->platformName() . '-up.sql');
        self::assertSame([], (new SchemaTool($em))->getUpdateSchemaSql($em->getMetadataFactory()->getAllMetadata()), 'the up script is exactly the 1.15 mapping');
        self::assertSame(['Kept', '3600'], [(string) $conn->fetchOne('SELECT task_title_snapshot FROM time_track_entries'), (string) $conn->fetchOne('SELECT duration_seconds FROM time_track_entries')]);
    }

    public function testTheRollbackRefusesToDropAnEntryWithNoUser(): void
    {
        $em   = $this->entityManager();
        $conn = $em->getConnection();
        $em->persist(new TimeEntry(null, 'task-1', 'Kept', null, new DateTimeImmutable('2026-01-01 10:00:00'), new DateTimeImmutable('2026-01-01 11:00:00'), 3600, TimeEntrySource::Import));
        $em->flush();

        $this->expectException(DbalException::class);
        try {
            $this->runSql($this->platformName() . '-down.sql');
        } finally {
            self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM time_track_entries'), 'nothing is deleted');
            if ($conn->getDatabasePlatform() instanceof SQLitePlatform) {
                $conn->executeStatement('DROP TABLE time_track_entries_old');
                $conn->executeStatement('PRAGMA foreign_keys = ON');
            }
        }
    }

    public function testTheDemoMigrationAndUpgradingCarryTheReferenceStatements(): void
    {
        $root      = dirname(__DIR__, 2);
        $migration = (string) file_get_contents($root . '/demo/symfony8/migrations/Version20261108000000.php');
        $upgrading = (string) file_get_contents($root . '/docs/UPGRADING.md');
        foreach (['mysql', 'postgresql', 'sqlite'] as $platform) {
            foreach (['up', 'down'] as $direction) {
                foreach ($this->statements($platform . '-' . $direction . '.sql') as $statement) {
                    self::assertStringContainsString($statement . ';', $upgrading);
                    // The demo's foreign key has its own name until this migration renames it.
                    if ($platform === 'mysql' && ($direction !== 'up' || !str_contains($statement, 'DROP FOREIGN KEY')) && !str_contains($statement, 'ON DELETE CASCADE')) {
                        self::assertStringContainsString("addSql('" . $statement . "')", $migration);
                    }
                }
            }
        }
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
            $conn->executeStatement(str_replace('REFERENCES users (id)', 'REFERENCES app_user (id)', $statement));
        }
    }

    /**
     * @return list<string>
     */
    private function statements(string $file): array
    {
        $statements = [];
        foreach (explode("\n", (string) file_get_contents(dirname(__DIR__, 2) . '/docs/sql/1.15.0/' . $file)) as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '--')) {
                $statements[] = rtrim($line, ';');
            }
        }

        return $statements;
    }
}
