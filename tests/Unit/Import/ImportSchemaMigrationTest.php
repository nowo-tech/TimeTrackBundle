<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Import;

use Doctrine\ORM\Mapping\Index;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function dirname;
use function file_get_contents;

/**
 * Since 1.11: the reference migration of the demo (what hosts copy) and the entity mapping agree on the imported-entry
 * schema, and the `(ended_at, id)` index keeps exactly the name Beacon's own migration uses.
 */
final class ImportSchemaMigrationTest extends TestCase
{
    private const MIGRATION = '/demo/symfony8/migrations/Version20261105000000.php';

    public function testTheEntityDeclaresTheEndedAtIndexWithBeaconsNameAndColumnOrder(): void
    {
        $indexes = [];
        foreach ((new ReflectionClass(TimeEntry::class))->getAttributes(Index::class) as $attribute) {
            $index                          = $attribute->newInstance();
            $indexes[(string) $index->name] = $index->columns;
        }

        self::assertSame(['ended_at', 'id'], $indexes['time_track_entries_ended_idx'] ?? null);
        self::assertSame(['source'], $indexes['idx_time_track_entries_source'] ?? null);
    }

    public function testTheReferenceMigrationCreatesTheColumnsAndIndexes(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 3) . self::MIGRATION);

        self::assertStringContainsString('ADD external_source VARCHAR(64) DEFAULT NULL', $sql);
        self::assertStringContainsString('ADD external_id VARCHAR(128) DEFAULT NULL', $sql);
        self::assertStringContainsString('ADD billable TINYINT(1) DEFAULT NULL', $sql);
        self::assertStringContainsString('ADD description LONGTEXT DEFAULT NULL', $sql, 'the description has no length cap');
        self::assertStringContainsString('CREATE UNIQUE INDEX uniq_time_track_entries_external ON time_track_entries (external_source, external_id)', $sql);
        self::assertStringContainsString('CREATE INDEX idx_time_track_entries_source ON time_track_entries (source)', $sql);
        self::assertStringContainsString('CREATE INDEX time_track_entries_ended_idx ON time_track_entries (ended_at, id)', $sql);
        self::assertStringContainsString('DROP INDEX time_track_entries_ended_idx ON time_track_entries', $sql);
    }
}
