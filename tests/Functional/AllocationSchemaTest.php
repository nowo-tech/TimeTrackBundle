<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;
use Nowo\TimeTrackBundle\Tests\Support\AllocationSchema;
use Nowo\TimeTrackBundle\Tests\Support\ScopeKeyCollation;

use function dirname;
use function file_get_contents;
use function str_starts_with;

/**
 * Since 2.1.0 (Beacon spec 256): the reference migrations of docs/sql/2.1.0 match the mapping on every platform (down
 * restores the 2.0 columns, up is exactly the 2.1 mapping), keep the time, and are carried by UPGRADING and the demo
 * migration.
 */
final class AllocationSchemaTest extends FunctionalTestCase
{
    public function testTheMappingHasTheColumnsAndTheIndexes(): void
    {
        foreach ([TimeEntry::class, ActiveTimer::class] as $class) {
            $metadata = $this->entityManager()->getClassMetadata($class);
            foreach (['subjectType' => 32, 'subjectId' => 64, 'activityTypeKey' => 64] as $field => $length) {
                self::assertSame($length, $metadata->getFieldMapping($field)->length, $class . '::' . $field);
                self::assertTrue($metadata->getFieldMapping($field)->nullable, $class . '::' . $field);
            }
        }
        $indexes = $this->entityManager()->getClassMetadata(TimeEntry::class)->table['indexes'] ?? [];
        self::assertSame(['scope_key', 'subject_type', 'subject_id', 'started_at'], $indexes['idx_time_track_entries_scope_subject']['columns'] ?? null);
        self::assertSame(['scope_key', 'activity_type_key', 'started_at'], $indexes['idx_time_track_entries_scope_activity']['columns'] ?? null);
    }

    public function testTheReferenceMigrationsMatchTheMappingAndKeepTheTime(): void
    {
        $em    = $this->entityManager();
        $conn  = $em->getConnection();
        $entry = new TimeEntry($this->user, 'task-1', 'Kept', null, new DateTimeImmutable('2026-01-01 10:00:00'), new DateTimeImmutable('2026-01-01 11:00:00'), 3600, TimeEntrySource::Web);
        $entry->allocate(new TimeSubjectRef('customer', '42'), 'support_call');
        $em->persist($entry);
        $em->flush();

        AllocationSchema::down($conn);
        foreach (['time_track_entries', 'time_track_active_timers'] as $table) {
            $columns = $conn->createSchemaManager()->introspectTable($table);
            foreach (['subject_type', 'subject_id', 'activity_type_key'] as $column) {
                self::assertFalse($columns->hasColumn($column), $table . '.' . $column);
            }
        }
        self::assertSame('3600', (string) $conn->fetchOne('SELECT duration_seconds FROM time_track_entries'), 'the rollback drops the allocation, never the time');

        AllocationSchema::up($conn);
        ScopeKeyCollation::apply($conn);
        self::assertSame([], (new SchemaTool($em))->getUpdateSchemaSql($em->getMetadataFactory()->getAllMetadata()), 'the up script is exactly the 2.1 mapping');
        self::assertSame(['Kept', null], [(string) $conn->fetchOne('SELECT task_title_snapshot FROM time_track_entries'), $conn->fetchOne('SELECT subject_type FROM time_track_entries')]);
    }

    public function testTheDemoMigrationAndUpgradingCarryTheReferenceStatements(): void
    {
        $root      = dirname(__DIR__, 2);
        $migration = (string) file_get_contents($root . '/demo/symfony8/migrations/Version20261125000000.php');
        $upgrading = (string) file_get_contents($root . '/docs/UPGRADING.md');
        $conn      = $this->entityManager()->getConnection();
        foreach (['mysql-up.sql', 'mysql-down.sql', 'mariadb-up.sql', 'postgresql-up.sql', 'sqlite-up.sql'] as $file) {
            foreach (AllocationSchema::statementsOf($root . '/docs/sql/2.1.0/' . $file) as $statement) {
                self::assertStringContainsString($statement . ';', $upgrading, $file);
                if (str_starts_with($file, 'mysql')) {
                    self::assertStringContainsString("addSql('" . $statement . "')", $migration, $file);
                }
            }
        }
        self::assertNotSame([], AllocationSchema::statements($conn, 'up'));
    }
}
