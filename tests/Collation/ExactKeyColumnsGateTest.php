<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Collation;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\MigrationsKitBundle\Test\ExactKeyColumnsTestCase;
use Nowo\TimeTrackBundle\Doctrine\TimeTrackExactKeyColumns;
use Nowo\TimeTrackBundle\Doctrine\TimeTrackMetadataListener;
use Nowo\TimeTrackBundle\UserRef\Entity\HostUser;
use Nowo\TimeTrackBundle\UserRef\UserMapping;

use function dirname;
use function rtrim;

use const PHP_VERSION_ID;

/**
 * REQ-SEC-006 on a real server (TIME_TRACK_TEST_DATABASE_URL: MySQL 8.4 and MariaDB 11.4 in CI): the bundle's own
 * gate over its own declaration, with `user_mapping: id` so that both the scope keys and the person reference columns
 * are checked.
 *
 * The tables are created by the schema tool with the bundle's own metadata listener, under a table prefix that is not
 * the default, and every declared column is then read back from `information_schema`. A key column that lost its
 * binary NO PAD collation — or a new one nobody collated — fails *this bundle's* pipeline, long before a host
 * installs it, and the prefix proves the declaration resolves the configured table names (the bundle has no fixed
 * ones).
 *
 * Skips cleanly on PostgreSQL and SQLite, which compare exactly by default, and without the variable; it is
 * therefore kept out of the `database` suite, which CI runs with `--fail-on-skipped`.
 */
final class ExactKeyColumnsGateTest extends ExactKeyColumnsTestCase
{
    /** Not the default prefix: the declaration must follow the configured names. */
    private const PREFIX = 'exactkey_tt_';

    /**
     * {@inheritdoc}
     */
    protected function databaseUrlVariable(): string
    {
        return 'TIME_TRACK_TEST_DATABASE_URL';
    }

    /**
     * {@inheritdoc}
     */
    protected function exactKeyColumnProviders(): array
    {
        return [new TimeTrackExactKeyColumns(self::PREFIX, UserMapping::ID)];
    }

    /**
     * {@inheritdoc}
     */
    protected function createSchema(Connection $connection): void
    {
        $base   = dirname(__DIR__, 2) . '/src';
        $config = ORMSetup::createAttributeMetadataConfiguration([$base . '/Entity', $base . '/UserRef/Entity'], true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }

        $prefix = rtrim(self::PREFIX, '_');
        $events = new EventManager();
        $events->addEventListener(Events::loadClassMetadata, new TimeTrackMetadataListener(
            entriesTableName: $prefix . '_entries',
            activeTimersTableName: $prefix . '_active_timers',
            clientTokensTableName: $prefix . '_client_tokens',
            userClass: HostUser::class,
            deviceCodesTableName: $prefix . '_device_codes',
            timesheetsTableName: $prefix . '_timesheets',
            timesheetEventsTableName: $prefix . '_timesheet_events',
            balanceLedgerTableName: $prefix . '_balance_ledger',
            calendarConnectionsTableName: $prefix . '_calendar_connections',
            calendarSuggestionsTableName: $prefix . '_calendar_suggestions',
            calendarRulesTableName: $prefix . '_calendar_rules',
            userMapping: UserMapping::ID,
            hostUsersTableName: $prefix . '_host_users',
            monitoringAcknowledgementsTableName: $prefix . '_monitoring_acknowledgements',
            monitoringUploadIntentsTableName: $prefix . '_monitoring_upload_intents',
        ));

        $manager = new EntityManager($connection, $config, $events);
        /** @var list<ClassMetadata<object>> $classes */
        $classes = $manager->getMetadataFactory()->getAllMetadata();

        $tool = new SchemaTool($manager);
        $tool->dropSchema($classes);
        $tool->createSchema($classes);
    }
}
