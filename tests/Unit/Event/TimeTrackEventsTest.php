<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Event;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Dto\TaskReference;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Event\TimeEntryAccessCheckEvent;
use Nowo\TimeTrackBundle\Event\TimeEntryListQueryEvent;
use Nowo\TimeTrackBundle\Event\TimerStartEvent;
use Nowo\TimeTrackBundle\Event\TimerStopEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

final class TimeTrackEventsTest extends TestCase
{
    public function testEventNameConstants(): void
    {
        self::assertSame('nowo_time_track.timer.start', TimeTrackEvents::TIMER_START);
        self::assertSame('nowo_time_track.timer.stop', TimeTrackEvents::TIMER_STOP);
        self::assertSame('nowo_time_track.time_entry.list_query', TimeTrackEvents::TIME_ENTRY_LIST_QUERY);
        self::assertSame('nowo_time_track.time_entry.access_check', TimeTrackEvents::TIME_ENTRY_ACCESS_CHECK);
    }

    public function testTimerStartEvent(): void
    {
        $user  = new TestUser('1', 'u@example.com');
        $task  = new TaskReference('task-1', 'Title', 'board-1', 'Sprint');
        $event = new TimerStartEvent($user, $task, ClientType::Web);

        self::assertSame($user, $event->getUser());
        self::assertSame($task, $event->getTask());
        self::assertSame(ClientType::Web, $event->getClientType());
    }

    public function testTimerStopEvent(): void
    {
        $user  = new TestUser('1', 'u@example.com');
        $entry = new TimeEntry(
            $user,
            'task-1',
            'Title',
            null,
            new DateTimeImmutable('-1 hour'),
            new DateTimeImmutable(),
            3600,
            TimeEntrySource::Web,
        );
        $event = new TimerStopEvent($user, $entry);

        self::assertSame($user, $event->getUser());
        self::assertSame($entry, $event->getEntry());
    }

    public function testTimeEntryListQueryEvent(): void
    {
        $user  = new TestUser('1', 'u@example.com');
        $from  = new DateTimeImmutable('-7 days');
        $to    = new DateTimeImmutable();
        $event = new TimeEntryListQueryEvent($user, $from, $to);

        self::assertSame($user, $event->getViewer());
        self::assertSame($from, $event->getFrom());
        self::assertSame($to, $event->getTo());
        self::assertSame([], $event->getUserIds());

        $event->setUserIds(['1', '2']);
        self::assertSame(['1', '2'], $event->getUserIds());
    }

    public function testTimeEntryAccessCheckEvent(): void
    {
        $user  = new TestUser('1', 'u@example.com');
        $entry = new TimeEntry(
            $user,
            'task-1',
            'Title',
            null,
            new DateTimeImmutable('-1 hour'),
            new DateTimeImmutable(),
            3600,
            TimeEntrySource::Web,
        );
        $event = new TimeEntryAccessCheckEvent($user, $entry, 'edit', false);

        self::assertSame($user, $event->getUser());
        self::assertSame($entry, $event->getEntry());
        self::assertSame('edit', $event->getAction());
        self::assertFalse($event->isGranted());

        $event->grant();
        self::assertTrue($event->isGranted());

        $event->deny();
        self::assertFalse($event->isGranted());
    }
}
