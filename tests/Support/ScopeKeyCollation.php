<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;

use function sprintf;

/**
 * The `ALTER` of UPGRADING "To 1.24.1" (REQ-SEC-006): after a reference script of an older release ran on MySQL /
 * MariaDB, the `scope_key` columns it created get the binary NO PAD collation of the current mapping. Nothing to do on
 * PostgreSQL and SQLite.
 */
final class ScopeKeyCollation
{
    public static function apply(Connection $connection): void
    {
        $platform = $connection->getDatabasePlatform();
        if (!$platform instanceof AbstractMySQLPlatform) {
            return;
        }
        $collation = match (true) {
            $platform instanceof MariaDBPlatform => 'utf8mb4_nopad_bin',
            $platform instanceof MySQL80Platform => 'utf8mb4_0900_bin',
            default                              => 'utf8mb4_bin',
        };
        /** @var list<array{TABLE_NAME: string, IS_NULLABLE: string}> $columns */
        $columns = $connection->fetchAllAssociative("SELECT TABLE_NAME, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'scope_key'");
        foreach ($columns as $column) {
            $connection->executeStatement(sprintf(
                'ALTER TABLE %s MODIFY scope_key VARCHAR(64) CHARACTER SET utf8mb4 COLLATE %s %s',
                $column['TABLE_NAME'],
                $collation,
                $column['IS_NULLABLE'] === 'YES' ? 'NULL DEFAULT NULL' : 'NOT NULL',
            ));
        }
    }
}
