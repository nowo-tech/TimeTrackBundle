<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Support;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Subject\ActivityType;
use Nowo\TimeTrackBundle\Subject\ActivityTypeList;
use Nowo\TimeTrackBundle\Subject\AllocationLabeler;
use Nowo\TimeTrackBundle\Subject\AllocationLabels;
use Nowo\TimeTrackBundle\Subject\ScopedSubjectLabels;
use Nowo\TimeTrackBundle\Subject\TimeSubjectLabel;
use Nowo\TimeTrackBundle\Subject\TimeSubjectLabels;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;
use Nowo\TimeTrackBundle\Support\TimeEntryCsv;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

use function array_slice;
use function explode;
use function str_getcsv;
use function trim;

/**
 * Since 2.1.0 (Beacon spec 256): the CSV export appends the allocation columns; unreadable subjects are masked.
 */
final class TimeEntryCsvAllocationTest extends TestCase
{
    public function testAllocationColumnsReadableMaskedAndNeutralised(): void
    {
        $readable = $this->entry()->allocate(new TimeSubjectRef('client', 'c-1'), 'dev');
        $hidden   = $this->entry()->allocate(new TimeSubjectRef('client', 'c-2'), 'gone');
        $formula  = $this->entry()->allocate(new TimeSubjectRef('client', 'c-3'), null);
        $plain    = $this->entry();
        $labels   = new AllocationLabels(
            new ScopedSubjectLabels(['org-a' => TimeSubjectLabels::of([
                new TimeSubjectLabel(new TimeSubjectRef('client', 'c-1'), 'Acme', 'https://crm.example.test/c-1'),
                new TimeSubjectLabel(new TimeSubjectRef('client', 'c-3'), '=cmd|calc'),
            ])]),
            ['org-a' => ActivityTypeList::of([new ActivityType('dev', '+Development')])],
            'Not visible',
        );

        $rows = $this->rows(TimeEntryCsv::render([$readable, $hidden, $formula, $plain], [], TimeEntry::HIDDEN_TASK_TITLE, [], $labels));

        self::assertSame(['subject_type', 'subject_id', 'subject_label', 'activity_type_key', 'activity_type_label'], array_slice($rows[0], -5));
        self::assertSame(['client', 'c-1', 'Acme', 'dev', "'+Development"], array_slice($rows[1], -5));
        self::assertSame(['client', '', 'Not visible', 'gone', 'gone'], array_slice($rows[2], -5), 'masked: no id, hidden label, key as label');
        self::assertSame(['client', 'c-3', "'=cmd|calc", '', ''], array_slice($rows[3], -5));
        self::assertSame(['', '', '', '', ''], array_slice($rows[4], -5));
    }

    public function testWithoutLabelsEverySubjectIsMasked(): void
    {
        $rows = $this->rows(TimeEntryCsv::render([$this->entry()->allocate(new TimeSubjectRef('client', 'c-1'), 'dev')]));

        self::assertSame(['client', '', AllocationLabeler::HIDDEN_SUBJECT_LABEL, 'dev', 'dev'], array_slice($rows[1], -5));
    }

    /**
     * CSV rows.
     *
     * @return list<list<string|null>>
     */
    private function rows(string $csv): array
    {
        $rows = [];
        foreach (explode("\n", trim($csv)) as $line) {
            $rows[] = str_getcsv($line, ',', '"', '');
        }

        return $rows;
    }

    /**
     * A live task entry in org-a.
     */
    private function entry(): TimeEntry
    {
        return (new TimeEntry(new TestUser('7', 'ana'), 'task-1', 'Task', null, new DateTimeImmutable('2026-11-02 09:00:00Z'), new DateTimeImmutable('2026-11-02 10:00:00Z'), 3600, TimeEntrySource::Web))->assignScopeKey('org-a');
    }
}
