<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Doctrine;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use LogicException;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\BalanceLedgerLine;
use Nowo\TimeTrackBundle\Entity\CalendarConnection;
use Nowo\TimeTrackBundle\Entity\CalendarRule;
use Nowo\TimeTrackBundle\Entity\CalendarSuggestion;
use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Entity\DeviceAuthorization;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Entity\TimesheetEvent;
use Nowo\TimeTrackBundle\UserRef\Entity\HostUser;
use Nowo\TimeTrackBundle\UserRef\UserMapping;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function array_replace_recursive;
use function in_array;
use function ltrim;
use function sprintf;

/**
 * Applies configurable table prefix and user entity mapping to time track entities. Since 1.23.0 with
 * `user_mapping: id` the `user` associations target the bundle-owned {@see HostUser} instead of `user_class`.
 */
final readonly class TimeTrackMetadataListener
{
    /** Since 1.23.0 (audit OA-M2): the person id / reference columns that compare exactly with `user_mapping: id`. */
    public const REF_FIELDS = [
        Timesheet::class         => ['ownerRef', 'submittedByRef', 'decidedByRef'],
        BalanceLedgerLine::class => ['ownerRef', 'actorRef'],
        TimesheetEvent::class    => ['actorRef'],
        TimeEntry::class         => ['personRef'],
    ];

    /**
     * Since 1.24.1 (REQ-SEC-006, audit OA-R2 / F4): the tenant scope key columns, which compare exactly with every
     * `user_mapping` (a host's scope keys are no user ids).
     */
    public const SCOPE_FIELDS = [
        Timesheet::class          => 'scopeKey',
        BalanceLedgerLine::class  => 'scopeKey',
        CalendarConnection::class => 'scopeKey',
        CalendarRule::class       => 'scopeKey',
        TimeEntry::class          => 'scopeKey',
    ];

    /**
     * Receives the configured table names and the host user class.
     */
    public function __construct(
        private string $entriesTableName,
        private string $activeTimersTableName,
        private string $clientTokensTableName,
        private string $userClass,
        private string $deviceCodesTableName = 'time_track_device_codes',
        private string $timesheetsTableName = 'time_track_timesheets',
        private string $timesheetEventsTableName = 'time_track_timesheet_events',
        private string $balanceLedgerTableName = 'time_track_balance_ledger',
        private string $calendarConnectionsTableName = 'time_track_calendar_connections',
        private string $calendarSuggestionsTableName = 'time_track_calendar_suggestions',
        private string $calendarRulesTableName = 'time_track_calendar_rules',
        private string $userMapping = UserMapping::RELATION,
        private string $hostUsersTableName = 'time_track_host_users',
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Applies the table prefix and the host user class to the bundle entities.
     */
    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();
        $class    = $metadata->getName();

        match ($class) {
            TimeEntry::class           => $metadata->setPrimaryTable(array_merge($metadata->table, ['name' => $this->entriesTableName])),
            ActiveTimer::class         => $metadata->setPrimaryTable(array_merge($metadata->table, ['name' => $this->activeTimersTableName])),
            ClientToken::class         => $metadata->setPrimaryTable(array_merge($metadata->table, ['name' => $this->clientTokensTableName])),
            DeviceAuthorization::class => $metadata->setPrimaryTable(array_merge($metadata->table, ['name' => $this->deviceCodesTableName])),
            Timesheet::class           => $metadata->setPrimaryTable(array_merge($metadata->table, ['name' => $this->timesheetsTableName])),
            TimesheetEvent::class      => $metadata->setPrimaryTable(array_merge($metadata->table, ['name' => $this->timesheetEventsTableName])),
            BalanceLedgerLine::class   => $metadata->setPrimaryTable(array_merge($metadata->table, ['name' => $this->balanceLedgerTableName])),
            CalendarConnection::class  => $metadata->setPrimaryTable(array_merge($metadata->table, ['name' => $this->calendarConnectionsTableName])),
            CalendarSuggestion::class  => $metadata->setPrimaryTable(array_merge($metadata->table, ['name' => $this->calendarSuggestionsTableName])),
            CalendarRule::class        => $metadata->setPrimaryTable(array_merge($metadata->table, ['name' => $this->calendarRulesTableName])),
            HostUser::class            => $this->mapHostUsers($metadata, $args),
            default                    => null,
        };
        if ($this->userMapping === UserMapping::ID) {
            $this->exactRefColumns($metadata, $args);
        }
        $this->exactScopeColumn($metadata, $args);

        if (!in_array($class, [TimeEntry::class, ActiveTimer::class, ClientToken::class, DeviceAuthorization::class, Timesheet::class, BalanceLedgerLine::class, CalendarConnection::class, CalendarSuggestion::class, CalendarRule::class], true)) {
            return;
        }

        if (isset($metadata->associationMappings['user'])) {
            $this->remapUserAssociation($metadata, 'user');
        }
    }

    /**
     * Since 1.23.0 (`user_mapping: id`, audit OA-M2): the columns that hold a person's id or reference (owner, actor,
     * submitter, decider, former person) compare exactly too, like the user id columns, so `ABC`, `àbc` or `abc ` never
     * match `abc`'s timesheet, ledger line or former-person entries. New opt-in DDL; nothing changes with the relation
     * mapping.
     *
     * @param ClassMetadata<object> $metadata
     */
    private function exactRefColumns(ClassMetadata $metadata, LoadClassMetadataEventArgs $args): void
    {
        $fields  = self::REF_FIELDS[$metadata->getName()] ?? [];
        $options = $fields === [] ? [] : $this->exactOptions($args);
        foreach ($options === [] ? [] : $fields as $field) {
            $metadata->fieldMappings[$field]->options = $options + ($metadata->fieldMappings[$field]->options ?? []);
        }
    }

    /**
     * Since 1.24.1 (REQ-SEC-006, audit OA-R2 / F4): the `scope_key` column compares exactly ({@see self::exactOptions()}),
     * so the approver of scope `acme` never reads `Acme`'s or `acme `'s timesheets, ledger lines, calendar rows or
     * entries. With `user_mapping: id` an unknown platform fails as for the user columns; with the relation mapping (no
     * platform needed before 1.24.1) the column keeps its default collation and a warning is logged: the stores still
     * re-check every scope key byte for byte.
     *
     * @param ClassMetadata<object> $metadata
     */
    private function exactScopeColumn(ClassMetadata $metadata, LoadClassMetadataEventArgs $args): void
    {
        $field = self::SCOPE_FIELDS[$metadata->getName()] ?? null;
        if ($field === null) {
            return;
        }
        try {
            $options = $this->exactOptions($args);
        } catch (LogicException $exception) {
            if ($this->userMapping === UserMapping::ID) {
                throw $exception;
            }
            $this->logger->warning('TimeTrack could not read the database platform: the scope_key column of {entity} keeps its default collation. Set doctrine.dbal.server_version with the full version (e.g. 8.0.32 or 10.11.6-MariaDB) and clear the cache.', ['bundle' => 'time_track', 'entity' => $metadata->getName()]);

            return;
        }
        if ($options !== []) {
            $metadata->fieldMappings[$field]->options = $options + ($metadata->fieldMappings[$field]->options ?? []);
        }
    }

    /**
     * The column options of an exact (binary, NO PAD) comparison on the platform: `utf8mb4_0900_bin` on MySQL 8,
     * `utf8mb4_nopad_bin` on MariaDB, `utf8mb4_bin` on older MySQL (PAD SPACE: trailing spaces are refused before
     * any write, see DoctrineUserReferenceResolver) and `Latin1_General_100_BIN2` on SQL Server (it always ignores
     * trailing spaces in comparisons: the same refusal covers it). PostgreSQL and SQLite compare exactly by default.
     *
     * The result is cached with the metadata, so an unknown platform fails loudly instead of caching metadata without
     * the collation (set `doctrine.dbal.server_version` when the database is not reachable at `cache:warmup`).
     *
     * @return array<string, string>
     */
    private function exactOptions(LoadClassMetadataEventArgs $args): array
    {
        try {
            $platform = $args->getEntityManager()->getConnection()->getDatabasePlatform();
        } catch (Throwable $exception) {
            throw new LogicException('nowo_time_track.user_mapping "id" needs the database platform to map the user id column (binary collation). Set doctrine.dbal.server_version (or make the database reachable) before warming up the cache.', 0, $exception);
        }

        return match (true) {
            $platform instanceof MariaDBPlatform       => ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_nopad_bin'],
            $platform instanceof MySQL80Platform       => ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_0900_bin'],
            $platform instanceof AbstractMySQLPlatform => $this->padFallback($args),
            $platform instanceof SQLServerPlatform     => ['collation' => 'Latin1_General_100_BIN2'],
            default                                    => [],
        };
    }

    /**
     * Since 1.24.1 (REQ-SEC-006 item 8): `utf8mb4_bin` (PAD SPACE) is the fallback for MySQL before 8.0. A `server_version`
     * without the patch number (`8`, `8.0`) also selects it on MySQL 8, so a warning is logged once per entity
     * metadata load (the result is cached with the metadata) asking for the full version.
     *
     * @return array<string, string>
     */
    private function padFallback(LoadClassMetadataEventArgs $args): array
    {
        $this->logger->warning('TimeTrack falls back to the PAD SPACE collation utf8mb4_bin for {entity}: the database platform is MySQL before 8.0.17 or doctrine.dbal.server_version has no patch number. Set the full server_version (e.g. 8.0.32) so the identifier columns use utf8mb4_0900_bin.', ['bundle' => 'time_track', 'entity' => $args->getClassMetadata()->getName()]);

        return ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_bin'];
    }

    /**
     * Since 1.23.0 (`user_mapping: id`): the host users table name and an exact collation for its id
     * ({@see self::exactOptions()}; so for every `user_id` column referencing it too), so `usera` never matches `UserA`.
     *
     * @param ClassMetadata<object> $metadata
     */
    private function mapHostUsers(ClassMetadata $metadata, LoadClassMetadataEventArgs $args): void
    {
        $metadata->setPrimaryTable(array_merge($metadata->table, ['name' => $this->hostUsersTableName]));
        $options = $this->exactOptions($args);
        if ($options !== []) {
            $metadata->fieldMappings['id']->options = $options + ($metadata->fieldMappings['id']->options ?? []);
        }
    }

    /** @param ClassMetadata<object> $metadata */
    private function remapUserAssociation(ClassMetadata $metadata, string $fieldName): void
    {
        // The caller checked isset(): ORM 3 stores every association as an AssociationMapping object.
        $mapping = $metadata->associationMappings[$fieldName];

        $userClass = ltrim($this->userClass, '\\');
        if (!class_exists($userClass)) {
            throw new LogicException(sprintf('Configured user_class "%s" does not exist.', $userClass));
        }
        if ($this->userMapping === UserMapping::ID) {
            // Since 1.23.0: the bundle-owned host user row instead of your users table (no foreign key to it).
            $userClass = HostUser::class;
        }

        $newMapping = array_replace_recursive(
            $mapping->toArray(),
            ['targetEntity' => $userClass],
        );
        $newMapping['fieldName'] = $mapping->fieldName;
        unset($metadata->associationMappings[$fieldName]);
        $metadata->mapManyToOne($newMapping);
    }
}
