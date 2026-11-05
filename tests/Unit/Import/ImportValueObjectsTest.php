<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Import;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Event\TimeEntriesRemovedEvent;
use Nowo\TimeTrackBundle\Event\TimeEntryImportedEvent;
use Nowo\TimeTrackBundle\Event\TimeEntryListQueryEvent;
use Nowo\TimeTrackBundle\Import\ImportedDescriptionSanitizer;
use Nowo\TimeTrackBundle\Import\ImportedEntryOwnerMismatchException;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryData;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryOutcome;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryRemoval;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryScope;
use Nowo\TimeTrackBundle\Import\InvalidImportedTimeEntryException;
use Nowo\TimeTrackBundle\Retention\TimeEntryRetentionPolicy;
use Nowo\TimeTrackBundle\Support\TimeEntryCsv;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

use function str_repeat;
use function substr_count;

final class ImportValueObjectsTest extends TestCase
{
    public function testSourceIsLiveAndReadOnly(): void
    {
        self::assertTrue(TimeEntrySource::Web->isLive());
        self::assertFalse(TimeEntrySource::Web->isReadOnly());
        self::assertFalse(TimeEntrySource::Import->isLive());
        self::assertTrue(TimeEntrySource::Import->isReadOnly());
        self::assertSame('import', TimeEntrySource::Import->value);
    }

    public function testDescriptionSanitizerKeepsLineBreaksAndNeverTruncates(): void
    {
        self::assertNull(ImportedDescriptionSanitizer::clean(null));
        self::assertNull(ImportedDescriptionSanitizer::clean(" \u{200B}\t "));
        self::assertSame("Line 1\nLine 2\nLine 3", ImportedDescriptionSanitizer::clean("  Line 1\r\nLine 2\rLine 3  "));
        self::assertSame('a b', ImportedDescriptionSanitizer::clean("a\u{202E}\x07b"));
        $long = str_repeat('x', 100_000);
        self::assertSame($long, ImportedDescriptionSanitizer::clean($long));
    }

    public function testRemovalMergeAddsSecondsAndDeduplicatesUsers(): void
    {
        $merged = (new ImportedTimeEntryRemoval(1, ['t1' => 60], ['u1']))->merge(new ImportedTimeEntryRemoval(2, ['t1' => 30, 't2' => 10], ['u1', 'u2']));

        self::assertSame(3, $merged->count);
        self::assertSame(['t1' => 90, 't2' => 10], $merged->secondsByTask);
        self::assertSame(['u1', 'u2'], $merged->userIds);
    }

    public function testScopes(): void
    {
        self::assertSame([ImportedTimeEntryScope::ENTRY, ['e1']], [ImportedTimeEntryScope::entry('s:1', 'e1')->kind, ImportedTimeEntryScope::entry('s:1', 'e1')->values]);
        self::assertSame(ImportedTimeEntryScope::USER, ImportedTimeEntryScope::user('s:1', 'u')->kind);
        self::assertSame(['a', 'b'], ImportedTimeEntryScope::tasks('s:1', ['a', 'b'])->values);
        self::assertSame(ImportedTimeEntryScope::BOARD, ImportedTimeEntryScope::board('s:1', 'b')->kind);
        self::assertSame([], ImportedTimeEntryScope::source('s:1')->values);
        self::assertSame('s:1', ImportedTimeEntryScope::source('s:1')->externalSource);
    }

    public function testRetentionPolicy(): void
    {
        $default = new TimeEntryRetentionPolicy();
        self::assertTrue($default->isPurgeable(TimeEntrySource::Desktop));
        self::assertFalse($default->isPurgeable(TimeEntrySource::Import));
        self::assertSame(['import'], $default->excludedSources());

        $all = new TimeEntryRetentionPolicy(true);
        self::assertTrue($all->isPurgeable(TimeEntrySource::Import));
        self::assertSame([], $all->excludedSources());
    }

    public function testExceptionsNameTheFieldNeverTheValue(): void
    {
        $invalid = new InvalidImportedTimeEntryException('externalId', 'pattern');
        self::assertSame('externalId', $invalid->field);
        self::assertSame('pattern', $invalid->rule);
        self::assertSame('Imported time entry refused: externalId (pattern).', $invalid->getMessage());
        self::assertStringContainsString('cannot change its owner', (new ImportedEntryOwnerMismatchException())->getMessage());
    }

    public function testImportedEntryShapeEventsAndCsv(): void
    {
        $user  = new TestUser('7', 'u@example.com');
        $data  = new ImportedTimeEntryData('clickup:dir-1', 'e-1', 'task-1', 'Title', 'board-1', new DateTimeImmutable('2026-01-01 10:00:00'), new DateTimeImmutable('2026-01-01 11:00:00'), 3600, true, "=SUM(A1)\nmore");
        $entry = TimeEntry::imported($user, $data);

        self::assertSame(TimeEntrySource::Import, $entry->getSource());
        self::assertTrue($entry->isReadOnly());
        self::assertSame('clickup:dir-1', $entry->getExternalSource());
        self::assertSame('e-1', $entry->getExternalId());
        self::assertTrue($entry->getBillable());
        self::assertSame("=SUM(A1)\nmore", $entry->getDescription());

        $visible = $entry->toArray();
        self::assertSame('Title', $visible['taskTitle']);
        self::assertTrue($visible['readOnly']);
        self::assertTrue($visible['billable']);
        self::assertTrue($visible['taskVisible']);
        self::assertArrayNotHasKey('externalSource', $visible);
        self::assertArrayNotHasKey('externalId', $visible);
        $hidden = $entry->toArray(false);
        self::assertSame(TimeEntry::HIDDEN_TASK_TITLE, $hidden['taskTitle']);
        self::assertFalse($hidden['taskVisible']);
        self::assertNull($hidden['description']);
        self::assertSame("=SUM(A1)\nmore", $visible['description']);

        self::assertFalse($entry->applyImport($data));
        $moved = new ImportedTimeEntryData('clickup:dir-1', 'e-1', 'task-2', 'Other', null, new DateTimeImmutable('2026-01-01 10:30:00'), new DateTimeImmutable('2026-01-01 12:00:00'), 5400);
        self::assertTrue($entry->applyImport($moved));
        self::assertSame('task-2', $entry->getTaskId());
        self::assertSame(5400, $entry->getDurationSeconds());
        self::assertNull($entry->getBillable());
        self::assertSame('2026-01-01 10:30:00', $entry->getStartedAt()->format('Y-m-d H:i:s'));
        $sameSpan = new ImportedTimeEntryData('clickup:dir-1', 'e-1', 'task-2', 'Other', null, new DateTimeImmutable('2026-01-01 10:30:00'), new DateTimeImmutable('2026-01-01 12:00:00'), 5000, false, 'x');
        self::assertTrue($entry->applyImport($sameSpan));
        self::assertFalse($entry->getBillable());

        $imported = new TimeEntryImportedEvent($entry, ImportedTimeEntryOutcome::Updated, 3600, 'task-1');
        self::assertSame($entry, $imported->getEntry());
        self::assertSame(ImportedTimeEntryOutcome::Updated, $imported->getOutcome());
        self::assertSame(3600, $imported->getPreviousDurationSeconds());
        self::assertSame('task-1', $imported->getPreviousTaskId());

        $removed = new TimeEntriesRemovedEvent('clickup:dir-1', ImportedTimeEntryScope::BOARD, new ImportedTimeEntryRemoval(2, ['task-1' => 60], ['7']), 'actor');
        self::assertSame('clickup:dir-1', $removed->getExternalSource());
        self::assertSame('board', $removed->getScope());
        self::assertSame(2, $removed->getCount());
        self::assertSame(['task-1' => 60], $removed->getSecondsByTask());
        self::assertSame(['7'], $removed->getUserIds());
        self::assertSame('actor', $removed->getActorId());

        $entry2 = TimeEntry::imported($user, $data);
        $entry3 = TimeEntry::imported($user, $data);
        $csv    = TimeEntryCsv::render([$entry2, $entry, $entry3], [$entry2->getId() => true]);
        self::assertStringStartsWith('id,taskId,taskTitle,boardId,startedAt,endedAt,durationSeconds,source,note,billable,description,imported', $csv);
        self::assertStringContainsString(TimeEntry::HIDDEN_TASK_TITLE, $csv);
        self::assertStringContainsString(",yes,\"'=SUM(A1)\nmore\",yes", $csv);
        self::assertStringContainsString(',no,x,yes', $csv);
        // Since 1.12 (FJ-I3): a masked entry's description is empty too; the visible copy keeps it.
        self::assertStringContainsString(',"' . TimeEntry::HIDDEN_TASK_TITLE . '",board-1,', $csv);
        self::assertSame(1, substr_count($csv, 'SUM(A1)'));
        self::assertStringContainsString(',import,,yes,,yes', $csv);

        $list = new TimeEntryListQueryEvent($user, new DateTimeImmutable(), new DateTimeImmutable());
        self::assertTrue($list->includesImported());
        $list->setIncludeImported(false);
        self::assertFalse($list->includesImported());
    }

    public function testCsvLeavesBillableEmptyWhenUnknown(): void
    {
        $live = new TimeEntry(new TestUser('1', 'a@example.com'), 'task-1', 'T', null, new DateTimeImmutable('-1 hour'), new DateTimeImmutable(), 3600, TimeEntrySource::Web);
        self::assertStringContainsString(',,,no', TimeEntryCsv::render([$live]));
        self::assertFalse($live->isReadOnly());
    }
}
