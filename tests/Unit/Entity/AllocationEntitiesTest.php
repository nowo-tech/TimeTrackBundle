<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Entity;

use DateTimeImmutable;
use LogicException;
use Nowo\TimeTrackBundle\Dto\TaskReference;
use Nowo\TimeTrackBundle\Dto\TimeEntryView;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Event\TimeEntryCreatedEvent;
use Nowo\TimeTrackBundle\Event\TimeEntryUnallocatedEvent;
use Nowo\TimeTrackBundle\Event\TimerAllocatedEvent;
use Nowo\TimeTrackBundle\Event\TimerStartedWithoutTaskEvent;
use Nowo\TimeTrackBundle\Event\TimerStartEvent;
use Nowo\TimeTrackBundle\Event\TimerStopEvent;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryData;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Timer\TimerEntrySnapshot;
use Nowo\TimeTrackBundle\Timer\TimerSnapshot;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Since 2.1.0 (Beacon spec 256): subject and activity type on the entities, read models and events.
 */
final class AllocationEntitiesTest extends TestCase
{
    public function testTimerAllocation(): void
    {
        $timer = new ActiveTimer(new TestUser('7', 'ana'), 'task-1', 'Task', null, ClientType::Web);
        self::assertNull($timer->subject());
        self::assertNull($timer->getActivityTypeKey());

        $subject = new TimeSubjectRef('client', 'c-1');
        self::assertSame($timer, $timer->allocate($subject, 'dev'));
        self::assertTrue($subject->equals($timer->subject() ?? self::fail()));
        self::assertSame('dev', $timer->getActivityTypeKey());

        $snapshot = TimerSnapshot::fromTimer($timer);
        self::assertTrue($subject->equals($snapshot->subject ?? self::fail()));
        self::assertSame('dev', $snapshot->activityTypeKey);

        $timer->allocate(null, null);
        self::assertNull($timer->subject());
        self::assertNull(TimerSnapshot::fromTimer($timer)->subject);
    }

    public function testAHandWrittenHalfPairReadsAsNoSubject(): void
    {
        $timer = new ActiveTimer(new TestUser('7', 'ana'), 'task-1', 'Task', null, ClientType::Web);
        (new ReflectionProperty(ActiveTimer::class, 'subjectType'))->setValue($timer, 'client');
        self::assertNull($timer->subject());

        $entry = $this->entry();
        (new ReflectionProperty(TimeEntry::class, 'subjectType'))->setValue($entry, 'Bad Type');
        (new ReflectionProperty(TimeEntry::class, 'subjectId'))->setValue($entry, 'c-1');
        self::assertNull($entry->subject());
    }

    public function testEntryAllocationAndReadModels(): void
    {
        $entry   = $this->entry();
        $subject = new TimeSubjectRef('client', 'c-1');
        self::assertNull($entry->subject());
        self::assertSame($entry, $entry->allocate($subject, 'dev'));
        self::assertSame('dev', $entry->getActivityTypeKey());

        $view = TimeEntryView::fromEntry($entry);
        self::assertTrue($subject->equals($view->subject ?? self::fail()));
        self::assertSame('dev', $view->activityTypeKey);

        $snapshot = TimerEntrySnapshot::fromEntry($entry);
        self::assertTrue($subject->equals($snapshot->subject ?? self::fail()));
        self::assertSame('dev', $snapshot->activityTypeKey);

        $created = new TimeEntryCreatedEvent($entry, 'manual');
        self::assertTrue($subject->equals($created->getSubject() ?? self::fail()));
        self::assertSame('dev', $created->getActivityTypeKey());

        $stop = new TimerStopEvent(new TestUser('7', 'ana'), $entry);
        self::assertTrue($subject->equals($stop->getSubject() ?? self::fail()));
        self::assertSame('dev', $stop->getActivityTypeKey());

        $entry->allocate(null, null);
        self::assertNull(TimeEntryView::fromEntry($entry)->subject);
        self::assertNull((new TimeEntryCreatedEvent($entry, 'manual'))->getSubject());
    }

    public function testAnonymisingKeepsSubjectAndActivity(): void
    {
        $entry = $this->entry();
        $entry->allocate(new TimeSubjectRef('client', 'c-1'), 'dev');
        $entry->anonymiseInPlace(new DateTimeImmutable('2026-11-20'));

        self::assertSame('c-1', $entry->subject()?->id);
        self::assertSame('dev', $entry->getActivityTypeKey());
        $this->expectException(LogicException::class);
        $entry->allocate(null, null);
    }

    public function testAnImportedEntryCannotBeAllocated(): void
    {
        $entry = TimeEntry::imported(new TestUser('7', 'ana'), new ImportedTimeEntryData('clickup:1', 'e-1', 'task-1', 'T', null, new DateTimeImmutable('2026-11-03 09:00:00Z'), new DateTimeImmutable('2026-11-03 10:00:00Z'), 3600));

        $this->expectException(LogicException::class);
        $entry->allocate(new TimeSubjectRef('client', 'c-1'), null);
    }

    public function testStartEvents(): void
    {
        $subject = new TimeSubjectRef('client', 'c-1');
        $start   = new TimerStartEvent(new TestUser('7', 'ana'), new TaskReference('task-1', 'Task'), ClientType::Web, null, $subject, 'dev');
        self::assertSame($subject, $start->getSubject());
        self::assertSame('dev', $start->getActivityTypeKey());
        self::assertNull((new TimerStartEvent(new TestUser('7', 'ana'), new TaskReference('task-1', 'Task'), ClientType::Web))->getSubject());

        $at      = new DateTimeImmutable('2026-11-02 09:00:00');
        $without = new TimerStartedWithoutTaskEvent('7', 'tm-1', 'org-a', ClientType::Desktop, $at, null, $subject, 'qa');
        self::assertSame($subject, $without->getSubject());
        self::assertSame('qa', $without->getActivityTypeKey());
        self::assertNull((new TimerStartedWithoutTaskEvent('7', 'tm-1', null, ClientType::Web, $at))->getActivityTypeKey());
    }

    public function testAllocationEvents(): void
    {
        $old       = new TimeSubjectRef('client', 'c-1');
        $new       = new TimeSubjectRef('client', 'c-2');
        $allocated = new TimerAllocatedEvent('7', 'tm-1', null, 'org-a', $old, 'dev', $new, 'qa');
        self::assertSame(['7', 'tm-1', null, 'org-a', $old, 'dev', $new, 'qa'], [$allocated->userId, $allocated->timerId, $allocated->taskId, $allocated->scopeKey, $allocated->previousSubject, $allocated->previousActivityTypeKey, $allocated->subject, $allocated->activityTypeKey]);

        $unallocated = new TimeEntryUnallocatedEvent('e-1', '7', null, 'replaced');
        self::assertSame(['e-1', '7', null, 'replaced'], [$unallocated->entryId, $unallocated->userId, $unallocated->scopeKey, $unallocated->closedBy]);
    }

    /**
     * A live task entry of user 7.
     */
    private function entry(): TimeEntry
    {
        return new TimeEntry(new TestUser('7', 'ana'), 'task-1', 'Task', 'board-1', new DateTimeImmutable('2026-11-02 09:00:00'), new DateTimeImmutable('2026-11-02 10:00:00'), 3600, TimeEntrySource::Web);
    }
}
