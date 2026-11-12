<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Doctrine;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
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

        if (!in_array($class, [TimeEntry::class, ActiveTimer::class, ClientToken::class, DeviceAuthorization::class, Timesheet::class, BalanceLedgerLine::class, CalendarConnection::class, CalendarSuggestion::class, CalendarRule::class], true)) {
            return;
        }

        if (isset($metadata->associationMappings['user'])) {
            $this->remapUserAssociation($metadata, 'user');
        }
    }

    /**
     * Since 1.23.0 (`user_mapping: id`): the host users table name and, on MySQL / MariaDB and SQL Server, a binary
     * collation for its id (and so for every `user_id` column referencing it), so `usera` never matches `UserA`. The
     * result is cached with the metadata: an unknown platform fails loudly instead of caching it without the collation
     * (set `doctrine.dbal.server_version` when the database is not reachable at `cache:warmup`).
     *
     * @param ClassMetadata<object> $metadata
     */
    private function mapHostUsers(ClassMetadata $metadata, LoadClassMetadataEventArgs $args): void
    {
        $metadata->setPrimaryTable(array_merge($metadata->table, ['name' => $this->hostUsersTableName]));
        try {
            $platform = $args->getEntityManager()->getConnection()->getDatabasePlatform();
        } catch (Throwable $exception) {
            throw new LogicException('nowo_time_track.user_mapping "id" needs the database platform to map the user id column (binary collation). Set doctrine.dbal.server_version (or make the database reachable) before warming up the cache.', 0, $exception);
        }
        $options = match (true) {
            $platform instanceof AbstractMySQLPlatform => ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_bin'],
            $platform instanceof SQLServerPlatform     => ['collation' => 'Latin1_General_100_BIN2'],
            default                                    => [],
        };
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
