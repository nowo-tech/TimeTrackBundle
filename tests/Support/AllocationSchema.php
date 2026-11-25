<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

use function dirname;
use function explode;
use function file_get_contents;
use function rtrim;
use function str_starts_with;
use function trim;

/**
 * Since 2.1.0: runs the reference migrations of docs/sql/2.1.0 (subject and activity type) on the test connection, so the
 * schema tests of earlier versions roll 2.1.0 back first and apply it again last.
 */
final class AllocationSchema
{
    /**
     * Rolls 2.1.0 back.
     */
    public static function down(Connection $connection): void
    {
        self::run($connection, 'down');
    }

    /**
     * Applies 2.1.0.
     */
    public static function up(Connection $connection): void
    {
        self::run($connection, 'up');
    }

    /**
     * The statements of one script of the platform (one statement per line; client directives skipped).
     *
     * @return list<string>
     */
    public static function statements(Connection $connection, string $direction): array
    {
        $platform = $connection->getDatabasePlatform();
        $name     = match (true) {
            $platform instanceof MariaDBPlatform       => 'mariadb',
            $platform instanceof AbstractMySQLPlatform => 'mysql',
            $platform instanceof PostgreSQLPlatform    => 'postgresql',
            default                                    => 'sqlite',
        };

        return self::statementsOf(dirname(__DIR__, 2) . '/docs/sql/2.1.0/' . $name . '-' . $direction . '.sql');
    }

    /**
     * The statements of a script file (one statement per line; comments and client directives skipped).
     *
     * @return list<string>
     */
    public static function statementsOf(string $path): array
    {
        $statements = [];
        foreach (explode("\n", (string) file_get_contents($path)) as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '--') && !str_starts_with($line, '.') && !str_starts_with($line, '\\')) {
                $statements[] = rtrim($line, ';');
            }
        }

        return $statements;
    }

    private static function run(Connection $connection, string $direction): void
    {
        foreach (self::statements($connection, $direction) as $statement) {
            $connection->executeStatement($statement);
        }
    }
}
