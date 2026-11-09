<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Event;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Dto\TaskReference;
use Nowo\TimeTrackBundle\Dto\TimeEntryView;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\IdleResolution;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Enum\TimesheetPeriodKind;
use Nowo\TimeTrackBundle\Event\IdleResolvedEvent;
use Nowo\TimeTrackBundle\Event\TaskEffortAccessCheckEvent;
use Nowo\TimeTrackBundle\Event\TimeEntryAccessCheckEvent;
use Nowo\TimeTrackBundle\Event\TimeEntryCreatedEvent;
use Nowo\TimeTrackBundle\Event\TimeEntryImportedEvent;
use Nowo\TimeTrackBundle\Event\TimeEntryListQueryEvent;
use Nowo\TimeTrackBundle\Event\TimerStartEvent;
use Nowo\TimeTrackBundle\Event\TimerStopEvent;
use Nowo\TimeTrackBundle\Event\TimesheetAccessCheckEvent;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryOutcome;
use Nowo\TimeTrackBundle\Tests\Fixtures\DddHost\SecurityUser;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Timesheet\Period;
use PHPUnit\Framework\TestCase;

/** Since 1.18: scalar accessors on the events, the entities and {@see TimeEntryView} (DDD adapter audit G2/G3). */
final class ScalarEventAccessorsTest extends TestCase
{
    public function testEntitiesExposeTheOwnerId(): void
    {
        $owner = new TestUser('42', 'owner@example.com');

        self::assertSame('42', $this->entry($owner)->getUserId());
        self::assertNull($this->entry(null)->getUserId());
        self::assertSame('42', (new ActiveTimer($owner, 'task-1', 'Task', null, ClientType::Web))->getUserId());
        $timesheet = new Timesheet($owner, '42', 'org-a', Period::fromKey(TimesheetPeriodKind::Month, '2026-11'), 'UTC', new DateTimeImmutable('2026-11-05 10:00:00'));
        self::assertSame('42', $timesheet->getUserId());
    }

    public function testTimeEntryViewCopiesScalarValues(): void
    {
        $entry = $this->entry(new TestUser('42', 'owner@example.com'));
        $entry->assignScopeKey('org-a');
        $view = TimeEntryView::fromEntry($entry);

        self::assertSame($entry->getId(), $view->id);
        self::assertSame('42', $view->userId);
        self::assertSame('task-1', $view->taskId);
        self::assertSame('Task', $view->taskTitle);
        self::assertSame('board-1', $view->boardId);
        self::assertSame('org-a', $view->scopeKey);
        self::assertEquals($entry->getStartedAt(), $view->startedAt);
        self::assertEquals($entry->getEndedAt(), $view->endedAt);
        self::assertSame(3600, $view->durationSeconds);
        self::assertSame(TimeEntrySource::Web, $view->source);
        self::assertNull($view->externalSource);
        self::assertNull($view->externalId);
    }

    public function testUserEventsExposeIds(): void
    {
        $user  = new SecurityUser(7);
        $entry = $this->entry(new TestUser('42', 'owner@example.com'));

        $start = new TimerStartEvent($user, new TaskReference('task-1', 'Task', 'board-1'), ClientType::Web);
        self::assertSame('7', $start->getUserId());
        self::assertSame('task-1', $start->getTaskId());
        self::assertSame('board-1', $start->getBoardId());

        $stop = new TimerStopEvent($user, $entry);
        self::assertSame('7', $stop->getUserId());
        self::assertSame($entry->getId(), $stop->getEntryId());
        self::assertSame('42', $stop->getEntryView()->userId);

        $check = new TimeEntryAccessCheckEvent($user, $entry, 'edit', true);
        self::assertSame('7', $check->getUserId());
        self::assertSame($entry->getId(), $check->getEntryId());
        self::assertSame($entry->getId(), $check->getEntryView()->id);

        $now = new DateTimeImmutable();
        self::assertSame('7', (new IdleResolvedEvent($user, IdleResolution::Keep, $now, $now, ClientType::Desktop))->getUserId());
        self::assertSame('7', (new TaskEffortAccessCheckEvent($user, 'task-1'))->getUserId());
        self::assertSame('7', (new TimeEntryListQueryEvent($user, $now, $now))->getViewerId());
        self::assertSame('7', (new TimesheetAccessCheckEvent($user, '42', 'org-a', 'view', true))->getViewerId());
    }

    public function testEntryEventsExposeIds(): void
    {
        $entry = $this->entry(new TestUser('42', 'owner@example.com'));

        $created = new TimeEntryCreatedEvent($entry, 'manual');
        self::assertSame('42', $created->getUserId());
        self::assertSame($entry->getId(), $created->getEntryId());
        self::assertSame('task-1', $created->getEntryView()->taskId);

        $imported = new TimeEntryImportedEvent($this->entry(null), ImportedTimeEntryOutcome::Created);
        self::assertNull($imported->getUserId());
        self::assertSame($imported->getEntry()->getId(), $imported->getEntryId());
        self::assertNull($imported->getEntryView()->userId);
    }

    private function entry(?object $owner): TimeEntry
    {
        $start = new DateTimeImmutable('2026-11-09 09:00:00');

        return new TimeEntry($owner, 'task-1', 'Task', 'board-1', $start, $start->modify('+1 hour'), 3600, TimeEntrySource::Web);
    }
}
