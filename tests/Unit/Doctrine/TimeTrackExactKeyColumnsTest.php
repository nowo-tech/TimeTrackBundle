<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\Mapping\Table;
use Nowo\MigrationsKitContracts\Collation\ExactKeyColumn;
use Nowo\TimeTrackBundle\Doctrine\TimeTrackExactKeyColumns;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\BalanceLedgerLine;
use Nowo\TimeTrackBundle\Entity\CalendarConnection;
use Nowo\TimeTrackBundle\Entity\CalendarRule;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Entity\TimesheetEvent;
use Nowo\TimeTrackBundle\UserRef\UserMapping;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function array_map;
use function array_unique;
use function array_values;
use function sort;

/**
 * REQ-SEC-006: the declaration names the tenant scope keys and the person reference columns, on table names built
 * from the configured prefix, and declares the reference columns inactive with the relation mapping.
 */
final class TimeTrackExactKeyColumnsTest extends TestCase
{
    public function testItDeclaresTheScopeKeysAndThePersonReferences(): void
    {
        $columns = (new TimeTrackExactKeyColumns('time_track_', UserMapping::ID))->exactKeyColumns();

        self::assertSame([
            'time_track_timesheets.scope_key',
            'time_track_balance_ledger.scope_key',
            'time_track_calendar_connections.scope_key',
            'time_track_calendar_rules.scope_key',
            'time_track_entries.scope_key',
            'time_track_active_timers.scope_key',
            'time_track_timesheets.owner_ref',
            'time_track_timesheets.submitted_by_ref',
            'time_track_timesheets.decided_by_ref',
            'time_track_balance_ledger.owner_ref',
            'time_track_balance_ledger.actor_ref',
            'time_track_timesheet_events.actor_ref',
            'time_track_entries.person_ref',
        ], array_map(static fn (ExactKeyColumn $column): string => $column->describe(), $columns));

        foreach ($columns as $column) {
            self::assertTrue($column->active, $column->describe());
            self::assertNull($column->condition);
            self::assertSame(TimeTrackExactKeyColumns::OWNER, $column->owner);
            self::assertNotSame('', $column->purpose, $column->describe());
        }
    }

    /**
     * The table names are the bundle's own: with the default prefix they must be exactly the ones the entities map,
     * so a renamed table cannot leave the declaration behind.
     */
    public function testTheDefaultPrefixYieldsTheMappedTableNames(): void
    {
        $mapped = [];
        foreach ([Timesheet::class, TimesheetEvent::class, BalanceLedgerLine::class, TimeEntry::class, ActiveTimer::class, CalendarConnection::class, CalendarRule::class] as $entity) {
            $attributes = (new ReflectionClass($entity))->getAttributes(Table::class);
            self::assertCount(1, $attributes, $entity);
            $mapped[] = (string) $attributes[0]->newInstance()->name;
        }
        sort($mapped);

        $declared = [];
        foreach ((new TimeTrackExactKeyColumns())->exactKeyColumns() as $column) {
            $declared[] = $column->table;
        }
        $declared = array_values(array_unique($declared));
        sort($declared);

        self::assertSame($mapped, $declared);
    }

    public function testThePrefixIsResolvedWithOrWithoutItsTrailingUnderscore(): void
    {
        $with    = (new TimeTrackExactKeyColumns('app_tt_'))->exactKeyColumns();
        $without = (new TimeTrackExactKeyColumns('app_tt'))->exactKeyColumns();

        self::assertSame('app_tt_timesheets', $with[0]->table);
        self::assertEquals($with, $without);
    }

    public function testTheReferenceColumnsAreInactiveWithTheRelationMapping(): void
    {
        // Default configuration: the scope keys exist and are exact; the reference columns are the DDL of a mode
        // that is off, so they are reported as skipped with that setting instead of as failures.
        foreach ((new TimeTrackExactKeyColumns())->exactKeyColumns() as $column) {
            $scope = $column->column === 'scope_key';
            self::assertSame($scope, $column->active, $column->describe());
            self::assertSame($scope ? null : TimeTrackExactKeyColumns::REF_CONDITION, $column->condition, $column->describe());
        }
    }

    public function testItIsSafeToCallMoreThanOnce(): void
    {
        // REQ-RUNTIME-002: nothing is memoised, so a worker that keeps the service gets the same answer every time.
        $declaration = new TimeTrackExactKeyColumns('app_tt_', UserMapping::ID);

        self::assertEquals($declaration->exactKeyColumns(), $declaration->exactKeyColumns());
    }
}
