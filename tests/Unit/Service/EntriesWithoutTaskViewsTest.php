<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Nowo\TimeTrackBundle\Client\ClientTaskPresenter;
use Nowo\TimeTrackBundle\Dto\TimeEntryView;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Repository\TimeEntryListing;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryActiveTimerRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimeEntryRepository;
use Nowo\TimeTrackBundle\Timer\TimerEntrySnapshot;
use Nowo\TimeTrackBundle\Timer\TimerSnapshot;
use Nowo\TimeTrackBundle\Timesheet\ScopeKeys;
use Nowo\TimeTrackBundle\Ui\TimeTrackPages;
use Nowo\UiKitBundle\View\Label;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;

/**
 * Since 1.36.0 (Beacon spec 250): the shapes of timers and entries without a task (entities, views, snapshots, the
 * client API timer, the timer page) and the scope helpers; a task timer's and entry's shapes are unchanged.
 */
final class EntriesWithoutTaskViewsTest extends TestCase
{
    public function testEntitiesAndViewsTellATaskFromNone(): void
    {
        $user     = new TestUser('user-1', 'u@example.com');
        $timer    = ActiveTimer::withoutTask($user, ClientType::Web, 'ACME', 'org-a', ['note' => 'n'], new DateTimeImmutable('2026-11-21 09:00'));
        $withTask = new ActiveTimer($user, 'task-1', 'Task', 'board-1', ClientType::Web, null, new DateTimeImmutable('2026-11-21 09:00'));

        self::assertSame(['', '', true, 'ACME'], [$timer->toArray()['taskId'], $timer->toArray()['taskTitle'], $timer->toArray()['withoutTask'] ?? null, $timer->toArray()['description'] ?? null]);
        self::assertArrayNotHasKey('withoutTask', $withTask->toArray());
        self::assertSame([true, 'task-1', null, null], [$withTask->hasTask(), $withTask->taskIdOrNull(), $withTask->getDescription(), $withTask->getScopeKey()]);
        self::assertSame(['task-1', false], [TimerSnapshot::fromTimer($withTask)->taskIdOrNull(), TimerSnapshot::fromTimer($withTask)->withoutTask]);
        self::assertNull(TimerSnapshot::fromTimer($timer)->taskIdOrNull());

        $entry = new TimeEntry($user, 'task-1', 'Task', 'board-1', new DateTimeImmutable('2026-11-21 09:00'), new DateTimeImmutable('2026-11-21 10:00'), 3600, TimeEntrySource::Web);
        self::assertSame([true, 'task-1', 'task-1', false], [$entry->hasTask(), $entry->taskIdOrNull(), TimeEntryView::fromEntry($entry)->taskIdOrNull(), TimeEntryView::fromEntry($entry)->withoutTask]);
        self::assertArrayNotHasKey('withoutTask', $entry->toArray());
        self::assertSame(['task-1', false], [TimerEntrySnapshot::fromEntry($entry)->taskIdOrNull(), TimerEntrySnapshot::fromEntry($entry)->withoutTask]);
    }

    public function testTheClientApiTimerOfATimerWithoutATaskHasNoTaskDetails(): void
    {
        $tasks     = new StubTaskProvider();
        $presenter = new ClientTaskPresenter(new TimerService($tasks, new InMemoryActiveTimerRepository(), new InMemoryTimeEntryRepository(), new EventDispatcher()));
        $timer     = ActiveTimer::withoutTask(new TestUser('user-1', 'u@example.com'), ClientType::Desktop, 'ACME', 'org-a');

        $body = $presenter->timer($timer, new TestUser('user-1', 'u@example.com'), Request::create('https://beacon.test/api/time-track/timer'));
        self::assertSame(['', true, 'ACME', null, null, null], [$body['taskId'], $body['withoutTask'], $body['description'], $body['boardTitle'], $body['url'], $body['loggedSeconds']]);
    }

    public function testTheTimerPageOfATimerWithoutATask(): void
    {
        $pages = new TimeTrackPages();
        $user  = new TestUser('user-1', 'u@example.com');

        $described = $pages->timerPage([], ActiveTimer::withoutTask($user, ClientType::Web, 'ACME', null, null, new DateTimeImmutable('2026-11-21 09:00')), new FormView(), [])->header->intro;
        self::assertInstanceOf(Label::class, $described);
        self::assertSame(['timer.running_intro', ['%task%' => 'ACME', '%started%' => '2026-11-21 09:00']], [$described->key, $described->params]);

        $plain = $pages->timerPage([], ActiveTimer::withoutTask($user, ClientType::Web, null, null, null, new DateTimeImmutable('2026-11-21 09:00')), new FormView(), [])->header->intro;
        self::assertInstanceOf(Label::class, $plain);
        self::assertSame(['timer.running_intro_without_task', ['%started%' => '2026-11-21 09:00']], [$plain->key, $plain->params]);
    }

    public function testTheScopeHelpers(): void
    {
        $user = new TestUser('user-1', 'u@example.com');

        self::assertSame([], ScopeKeys::ofUser(null, $user, new NullLogger()));
        self::assertNull(ScopeKeys::forEntryWithoutTask(null, $user, null, new NullLogger()));
        self::assertSame(['org-a'], ScopeKeys::ofUser(new FixedScopeProvider(['user-1' => ['org-a', "org-b\u{200B}"]]), $user, new NullLogger()), 'an unusable host key is left out');
        self::assertNull(ScopeKeys::forEntryWithoutTask(new FixedScopeProvider(), $user, null, new NullLogger()), 'a user in no scope gets none');

        $listing = new TimeEntryListing(['user-1'], new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-11-30'), true, false, null, 'viewer', ['org-a']);
        self::assertSame([true, true, false, false, false], [
            $listing->keepsWithoutTask('viewer', null),
            $listing->keepsWithoutTask('user-1', 'org-a'),
            $listing->keepsWithoutTask('user-1', 'Org-a'),
            $listing->keepsWithoutTask(null, null),
            $listing->keepsWithoutTask('viewer ', 'org-b'),
        ]);
    }
}
