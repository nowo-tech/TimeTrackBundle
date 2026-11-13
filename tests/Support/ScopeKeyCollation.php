<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use LogicException;

use function preg_replace;
use function sprintf;

/**
 * The `ALTER` of UPGRADING "To 1.24.1" (REQ-SEC-006): after a reference script of an older release ran on MySQL /
 * MariaDB, the `scope_key` columns it created get the binary NO PAD collation of the current mapping. Nothing to do on
 * PostgreSQL and SQLite. {@see self::fold()} does the opposite, for the look-alike tests.
 */
final class ScopeKeyCollation
{
    /** Table => whether its scope_key column is nullable. */
    public const TABLES = [
        'time_track_timesheets'           => false,
        'time_track_balance_ledger'       => false,
        'time_track_calendar_connections' => false,
        'time_track_calendar_rules'       => false,
        'time_track_entries'              => true,
    ];

    /**
     * Gives the scope_key columns a folding collation, as on a database without the 1.24.1 ALTER: `utf8mb4_unicode_ci`
     * on MySQL / MariaDB (folds case and accents, pads trailing spaces), `NOCASE` on SQLite (folds ASCII case) and a
     * non-deterministic ICU collation on PostgreSQL (folds case and accents; its unique indexes then refuse the
     * look-alike too). PostgreSQL's own mapping never folds, so this only builds the host shape the tests need.
     */
    public static function fold(Connection $connection): void
    {
        $platform = $connection->getDatabasePlatform();
        foreach (self::TABLES as $table => $nullable) {
            if ($platform instanceof AbstractMySQLPlatform) {
                $connection->executeStatement(sprintf('ALTER TABLE %s MODIFY scope_key VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci %s', $table, $nullable ? 'NULL' : 'NOT NULL'));
            } elseif ($platform instanceof PostgreSQLPlatform) {
                self::postgresFolding($connection, $table, $nullable);
            } elseif ($platform instanceof SQLitePlatform) {
                self::sqliteNoCase($connection, $table);
            }
        }
    }

    /**
     * A non-deterministic ICU collation compares `org-a` and `Org-a` as one value, unique indexes included. Pattern
     * matching is not allowed on such a column, which the folding tests do not use.
     */
    private static function postgresFolding(Connection $connection, string $table, bool $nullable): void
    {
        $connection->executeStatement("CREATE COLLATION IF NOT EXISTS scope_key_folding (provider = icu, locale = 'und-u-ks-level2', deterministic = false)");
        $connection->executeStatement(sprintf('ALTER TABLE %s ALTER COLUMN scope_key TYPE VARCHAR(64) COLLATE scope_key_folding', $table));
        $connection->executeStatement(sprintf('ALTER TABLE %s ALTER COLUMN scope_key %s NOT NULL', $table, $nullable ? 'DROP' : 'SET'));
    }

    private static function sqliteNoCase(Connection $connection, string $table): void
    {
        $create  = (string) $connection->fetchOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]);
        $indexes = $connection->fetchFirstColumn("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL", [$table]);
        $folded  = (string) preg_replace('/(scope_key VARCHAR\(64\))/', '$1 COLLATE NOCASE', $create);
        if ($folded === $create) {
            throw new LogicException('No scope_key column in ' . $table);
        }
        $connection->executeStatement('PRAGMA foreign_keys = OFF');
        $connection->executeStatement('PRAGMA legacy_alter_table = ON');
        $connection->executeStatement(sprintf('ALTER TABLE %1$s RENAME TO %1$s_old', $table));
        $connection->executeStatement($folded);
        $connection->executeStatement(sprintf('INSERT INTO %1$s SELECT * FROM %1$s_old', $table));
        $connection->executeStatement(sprintf('DROP TABLE %s_old', $table));
        foreach ($indexes as $index) {
            $connection->executeStatement((string) $index);
        }
        $connection->executeStatement('PRAGMA legacy_alter_table = OFF');
        $connection->executeStatement('PRAGMA foreign_keys = ON');
    }

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
