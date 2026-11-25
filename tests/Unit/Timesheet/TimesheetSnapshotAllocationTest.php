<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Timesheet;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Enum\TimesheetPeriodKind;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Timesheet\Period;
use Nowo\TimeTrackBundle\Timesheet\TimesheetSnapshotBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Since 2.1.0 (Beacon spec 256, Q-TS-14): timesheet totals per subject and activity type; the hash is unchanged.
 */
final class TimesheetSnapshotAllocationTest extends TestCase
{
    public function testTotalsPerSubjectAndActivityTypeWithAnUnchangedHash(): void
    {
        $owner = new TestUser('7', 'ana');
        $a     = $this->entry($owner, '2026-11-03 09:00:00Z', 3600)->allocate(new TimeSubjectRef('client', 'c-1'), 'dev');
        $b     = $this->entry($owner, '2026-11-04 09:00:00Z', 1800)->allocate(new TimeSubjectRef('client', 'c-1'), 'qa');
        $c     = $this->entry($owner, '2026-11-05 09:00:00Z', 600)->allocate(new TimeSubjectRef('project', 'c-1'), 'dev');
        $d     = $this->entry($owner, '2026-11-06 09:00:00Z', 300);
        $store = new class([$a, $b, $c, $d]) implements TimeEntryRepositoryInterface {
            /**
             * @param list<TimeEntry> $list
             */
            public function __construct(private readonly array $list)
            {
            }

            /**
             * Not used.
             */
            public function save(TimeEntry $entry): void
            {
            }

            /**
             * Every entry.
             */
            public function findByUserAndPeriod(string $userId, DateTimeImmutable $from, DateTimeImmutable $to): array
            {
                return $this->list;
            }
        };

        $snapshot = (new TimesheetSnapshotBuilder($store))->build(null, '7', 'org-a', Period::fromKey(TimesheetPeriodKind::Month, '2026-11'), 'UTC');

        self::assertSame([
            ['subjectType' => 'client', 'subjectId' => 'c-1', 'seconds' => 5400],
            ['subjectType' => 'project', 'subjectId' => 'c-1', 'seconds' => 600],
        ], $snapshot->totals['bySubject']);
        self::assertSame([
            ['activityTypeKey' => 'dev', 'seconds' => 4200],
            ['activityTypeKey' => 'qa', 'seconds' => 1800],
        ], $snapshot->totals['byActivityType']);
        self::assertSame(6300, $snapshot->totals['taskSeconds']);

        $plain = [];
        foreach ([$a, $b, $c, $d] as $entry) {
            $copy = $this->entry($owner, $entry->getStartedAt()->format('Y-m-d H:i:s\Z'), $entry->getDurationSeconds());
            (new ReflectionProperty(TimeEntry::class, 'id'))->setValue($copy, $entry->getId());
            $plain[] = $copy;
        }
        self::assertSame(TimesheetSnapshotBuilder::hash($plain), $snapshot->entriesHash, 'the hash never covers the allocation');
    }

    /**
     * A task entry in org-a.
     */
    private function entry(TestUser $owner, string $start, int $seconds): TimeEntry
    {
        $startedAt = new DateTimeImmutable($start);

        return (new TimeEntry($owner, 'task-1', 'T', null, $startedAt, $startedAt->modify('+' . $seconds . ' seconds'), $seconds, TimeEntrySource::Web))->assignScopeKey('org-a');
    }
}
