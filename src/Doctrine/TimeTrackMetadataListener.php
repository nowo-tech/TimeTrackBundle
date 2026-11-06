<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Doctrine;

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

use function array_replace_recursive;
use function in_array;
use function ltrim;
use function sprintf;

/**
 * Applies configurable table prefix and user entity mapping to time track entities.
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
            default                    => null,
        };

        if (!in_array($class, [TimeEntry::class, ActiveTimer::class, ClientToken::class, DeviceAuthorization::class, Timesheet::class, BalanceLedgerLine::class, CalendarConnection::class, CalendarSuggestion::class, CalendarRule::class], true)) {
            return;
        }

        if (isset($metadata->associationMappings['user'])) {
            $this->remapUserAssociation($metadata, 'user');
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

        $newMapping = array_replace_recursive(
            $mapping->toArray(),
            ['targetEntity' => $userClass],
        );
        $newMapping['fieldName'] = $mapping->fieldName;
        unset($metadata->associationMappings[$fieldName]);
        $metadata->mapManyToOne($newMapping);
    }
}
