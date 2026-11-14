<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Tools\SchemaTool;
use LogicException;
use Nowo\TimeTrackBundle\Entity\MonitoringAcknowledgementRecord;
use Nowo\TimeTrackBundle\Entity\MonitoringUploadIntent;
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
 * Since 1.26.0: the reference migrations of docs/sql/1.26.0 (the two always-mapped monitoring tables) match the mapping
 * on every platform (SQLite here; MySQL 8, MariaDB and PostgreSQL 16 in the CI database jobs), the down scripts restore
 * the 1.25 schema, and UPGRADING and the demo migration carry the statements.
 */
final class MonitoringSchemaTest extends FunctionalTestCase
{
    private const TABLES = ['time_track_monitoring_acknowledgements', 'time_track_monitoring_upload_intents'];

    public function testTheMappingHasTheTablesAndIndexes(): void
    {
        $em = $this->entityManager();
        self::assertSame(self::TABLES[0], $em->getClassMetadata(MonitoringAcknowledgementRecord::class)->getTableName());
        self::assertSame(['user_id', 'policy_version'], $em->getClassMetadata(MonitoringAcknowledgementRecord::class)->table['uniqueConstraints']['uniq_time_track_monitoring_ack_version']['columns'] ?? null);
        $intent = $em->getClassMetadata(MonitoringUploadIntent::class);
        self::assertSame(self::TABLES[1], $intent->getTableName());
        self::assertSame(['user_id', 'idempotency_hash'], $intent->table['uniqueConstraints']['uniq_time_track_monitoring_intent_key']['columns'] ?? null);
        self::assertSame(['user_id', 'slot'], $intent->table['indexes']['idx_time_track_monitoring_intent_slot']['columns'] ?? null);
    }

    public function testTheReferenceMigrationsMatchTheMapping(): void
    {
        $em       = $this->entityManager();
        $conn     = $em->getConnection();
        $tool     = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();

        $this->runSql($this->platformName() . '-down.sql');
        $expected = $tool->getSchemaFromMetadata($metadata);
        foreach (self::TABLES as $table) {
            $expected->dropTable($table);
        }
        $schemaManager = $conn->createSchemaManager();
        self::assertSame([], $conn->getDatabasePlatform()->getAlterSchemaSQL($schemaManager->createComparator()->compareSchemas($schemaManager->introspectSchema(), $expected)), 'the down script restores the 1.25 schema');

        $this->runSql($this->platformName() . '-up.sql');
        ScopeKeyCollation::apply($conn);
        self::assertSame([], $tool->getUpdateSchemaSql($metadata), 'the up script is exactly the 1.26 mapping');
    }

    public function testTheDemoMigrationAndUpgradingCarryTheReferenceStatements(): void
    {
        $root      = dirname(__DIR__, 2);
        $migration = (string) file_get_contents($root . '/demo/symfony8/migrations/Version20261114000000.php');
        $upgrading = (string) file_get_contents($root . '/docs/UPGRADING.md');
        foreach (['mysql', 'postgresql', 'sqlite'] as $platform) {
            foreach ($this->statements($platform . '-up.sql') as $statement) {
                self::assertStringContainsString($statement . ';', $upgrading, $statement);
            }
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
        $conn      = $this->entityManager()->getConnection();
        $collation = $conn->getDatabasePlatform() instanceof AbstractMySQLPlatform
            ? (string) $conn->fetchOne("SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'time_track_entries'")
            : 'utf8mb4_unicode_ci';
        foreach ($this->statements($file) as $statement) {
            $conn->executeStatement(str_replace(['REFERENCES users (id)', '`utf8mb4_unicode_ci`'], ['REFERENCES app_user (id)', '`' . $collation . '`'], $statement));
        }
    }

    /**
     * @return list<string>
     */
    private function statements(string $file): array
    {
        $statements = [];
        foreach (explode("\n", (string) file_get_contents(dirname(__DIR__, 2) . '/docs/sql/1.26.0/' . $file)) as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '--')) {
                $statements[] = rtrim($line, ';');
            }
        }

        return $statements;
    }
}
