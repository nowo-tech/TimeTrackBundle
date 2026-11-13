<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
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
use function str_starts_with;
use function trim;

/**
 * Since 1.25.0: the reference migrations of docs/sql/1.25.0 (entry channel and service-account mark) match the mapping
 * on every platform, keep the rows, and are carried by UPGRADING and the demo migration.
 */
final class EntryOriginSchemaTest extends FunctionalTestCase
{
    public function testTheMappingHasTheColumnsAndTheIndex(): void
    {
        $metadata = $this->entityManager()->getClassMetadata(TimeEntry::class);

        self::assertSame(16, $metadata->getFieldMapping('channel')->length);
        self::assertTrue($metadata->getFieldMapping('channel')->nullable);
        self::assertFalse($metadata->getFieldMapping('serviceAccount')->options['default'] ?? null);
        self::assertSame(['service_account', 'channel', 'started_at'], $metadata->table['indexes']['idx_time_track_entries_origin']['columns'] ?? null);
    }

    public function testTheReferenceMigrationsMatchTheMappingAndKeepTheRows(): void
    {
        $em   = $this->entityManager();
        $conn = $em->getConnection();
        $em->persist(new TimeEntry($this->user, 'task-1', 'Kept', null, new DateTimeImmutable('2026-01-01 10:00:00'), new DateTimeImmutable('2026-01-01 11:00:00'), 3600, TimeEntrySource::Web));
        $em->flush();

        $this->runSql($this->platformName() . '-down.sql');
        $table = $conn->createSchemaManager()->introspectTable('time_track_entries');
        self::assertFalse($table->hasColumn('channel'));
        self::assertFalse($table->hasColumn('service_account'));
        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM time_track_entries'));

        $this->runSql($this->platformName() . '-up.sql');
        self::assertSame([], (new SchemaTool($em))->getUpdateSchemaSql($em->getMetadataFactory()->getAllMetadata()), 'the up script is exactly the 1.25 mapping');
        self::assertSame(['Kept', null, 0], [(string) $conn->fetchOne('SELECT task_title_snapshot FROM time_track_entries'), $conn->fetchOne('SELECT channel FROM time_track_entries'), (int) $conn->fetchOne('SELECT COUNT(*) FROM time_track_entries WHERE service_account = ?', [true], ['boolean'])]);
    }

    public function testTheDemoMigrationAndUpgradingCarryTheReferenceStatements(): void
    {
        $root      = dirname(__DIR__, 2);
        $migration = (string) file_get_contents($root . '/demo/symfony8/migrations/Version20261113000000.php');
        $upgrading = (string) file_get_contents($root . '/docs/UPGRADING.md');
        foreach ($this->statements('mysql-up.sql') as $statement) {
            self::assertStringContainsString($statement . ';', $upgrading);
        }
        foreach (['up', 'down'] as $direction) {
            foreach ($this->statements('mysql-' . $direction . '.sql') as $statement) {
                self::assertTrue(str_contains($migration, "addSql('" . $statement . "')"), $statement);
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
        foreach ($this->statements($file) as $statement) {
            $this->entityManager()->getConnection()->executeStatement($statement);
        }
    }

    /**
     * @return list<string>
     */
    private function statements(string $file): array
    {
        $statements = [];
        foreach (explode("\n", (string) file_get_contents(dirname(__DIR__, 2) . '/docs/sql/1.25.0/' . $file)) as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '--')) {
                $statements[] = rtrim($line, ';');
            }
        }

        return $statements;
    }
}
