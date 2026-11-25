<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Tests\Support\AllocationSchema;
use Nowo\TimeTrackBundle\Tests\Support\ScopeKeyCollation;

use function dirname;
use function file_get_contents;
use function str_replace;
use function str_starts_with;

/**
 * Since 2.2.0: the reference migrations of docs/sql/2.2.0 match the mapping on every platform (down removes the
 * columns after revoking the other families' tokens, up is exactly the 2.2 mapping and reads every older row as
 * `time`), and are carried by UPGRADING and the demo migration.
 */
final class ScopeFamilySchemaTest extends FunctionalTestCase
{
    public function testTheReferenceMigrationsMatchTheMappingAndKeepTimeTokens(): void
    {
        $em   = $this->entityManager();
        $conn = $em->getConnection();
        $auth = self::getContainer()->get(ClientAuthService::class);
        self::assertInstanceOf(ClientAuthService::class, $auth);
        $time = $auth->issueToken($this->user, ClientType::Desktop);
        $auth->issueFamilyToken($this->user, ClientType::Desktop, 'c', 'chat', ['chat:read'], 'dev', 'Laptop');
        $this->clearEntityManagers();

        $this->runScripts($conn, 'down');
        foreach (['time_track_client_tokens' => ['scope_family', 'device_key', 'client_name'], 'time_track_device_codes' => ['scope_family', 'device_key']] as $table => $columns) {
            $introspected = $conn->createSchemaManager()->introspectTable($table);
            foreach ($columns as $column) {
                self::assertFalse($introspected->hasColumn($column), $table . '.' . $column);
            }
        }
        self::assertSame([ClientAuthService::hashToken($time['token'])], $conn->fetchFirstColumn('SELECT token_hash FROM time_track_client_tokens'), 'the rollback revokes the chat token only');

        $this->runScripts($conn, 'up');
        ScopeKeyCollation::apply($conn);
        self::assertSame([], (new SchemaTool($em))->getUpdateSchemaSql($em->getMetadataFactory()->getAllMetadata()), 'the up script is exactly the 2.2 mapping');
        self::assertSame('time', $conn->fetchOne('SELECT scope_family FROM time_track_client_tokens'), 'an older token is a time token');
    }

    public function testTheDemoMigrationAndUpgradingCarryTheReferenceStatements(): void
    {
        $root      = dirname(__DIR__, 2);
        $migration = str_replace("\\'", "'", (string) file_get_contents($root . '/demo/symfony8/migrations/Version20261126000000.php'));
        $upgrading = (string) file_get_contents($root . '/docs/UPGRADING.md');
        foreach (['mysql-up.sql', 'mysql-down.sql', 'mariadb-up.sql', 'postgresql-up.sql', 'sqlite-up.sql'] as $file) {
            foreach (AllocationSchema::statementsOf($root . '/docs/sql/2.2.0/' . $file) as $statement) {
                self::assertStringContainsString($statement . ';', $upgrading, $file);
                if (str_starts_with($file, 'mysql')) {
                    self::assertStringContainsString("addSql('" . $statement . "')", $migration, $file);
                }
            }
        }
    }

    private function runScripts(Connection $connection, string $direction): void
    {
        $platform = $connection->getDatabasePlatform();
        $name     = match (true) {
            $platform instanceof MariaDBPlatform       => 'mariadb',
            $platform instanceof AbstractMySQLPlatform => 'mysql',
            $platform instanceof PostgreSQLPlatform    => 'postgresql',
            default                                    => 'sqlite',
        };
        foreach (AllocationSchema::statementsOf(dirname(__DIR__, 2) . '/docs/sql/2.2.0/' . $name . '-' . $direction . '.sql') as $statement) {
            $connection->executeStatement($statement);
        }
    }
}
