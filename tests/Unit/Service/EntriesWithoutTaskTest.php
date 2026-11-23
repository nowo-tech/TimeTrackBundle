<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\IdleFollowUp;
use Nowo\TimeTrackBundle\Enum\IdleResolution;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Event\TimeEntryAccessCheckEvent;
use Nowo\TimeTrackBundle\Event\TimeEntryCreatedEvent;
use Nowo\TimeTrackBundle\Event\TimeEntryListQueryEvent;
use Nowo\TimeTrackBundle\Event\TimerStartedWithoutTaskEvent;
use Nowo\TimeTrackBundle\Event\TimerStartEvent;
use Nowo\TimeTrackBundle\Event\TimerStopEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Exception\ActiveTimerConflictException;
use Nowo\TimeTrackBundle\Exception\ManualEntryException;
use Nowo\TimeTrackBundle\Exception\TaskNotTrackableException;
use Nowo\TimeTrackBundle\Port\Internal\TimesheetScopeProviderInterface;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefFactory;
use Nowo\TimeTrackBundle\Security\TaskProviderTaskAccessChecker;
use Nowo\TimeTrackBundle\Service\ManualEntryService;
use Nowo\TimeTrackBundle\Service\TeamAccessGuard;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Tests\Stub\StubBillableDefaultProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubTeamContextProvider;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryActiveTimerRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryPaginatedTimeEntryRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimeEntryRepository;
use Nowo\TimeTrackBundle\Timer\HostTimerApi;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function array_map;
use function count;

/**
 * Since 1.36.0 (Beacon spec 250, `entries.allow_without_task`): timers and entries without a task, their tenant scope,
 * who reaches them, and the option off (the 1.35 behaviour).
 */
final class EntriesWithoutTaskTest extends TestCase
{
    private TestUser $user;

    private EventDispatcher $dispatcher;

    private MockClock $clock;

    private InMemoryActiveTimerRepository $timers;

    private InMemoryTimeEntryRepository $entries;

    private FixedScopeProvider $scopes;

    /** @var list<object> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->user       = new TestUser('user-1', 'sales@example.com');
        $this->dispatcher = new EventDispatcher();
        $this->clock      = new MockClock('2026-11-21 12:00:00');
        $this->timers     = new InMemoryActiveTimerRepository();
        $this->entries    = new InMemoryTimeEntryRepository();
        $this->scopes     = new FixedScopeProvider(['user-1' => ['org-a', 'org-b'], 'admin' => ['org-a'], 'other-admin' => ['org-c']]);
        foreach ([TimeTrackEvents::TIMER_START, TimeTrackEvents::TIMER_STOP, TimeTrackEvents::TIMER_START_WITHOUT_TASK, TimeTrackEvents::TIME_ENTRY_CREATED] as $name) {
            $this->dispatcher->addListener($name, function (object $event): void {
                $this->events[] = $event;
            });
        }
    }

    public function testANullTaskIsRefusedWhileTheOptionIsOff(): void
    {
        $service = $this->service(allow: false);

        foreach ([
            fn (): mixed => $service->start($this->user, null, ClientType::Web),
            fn (): mixed => $service->startReplacing($this->user, null, ClientType::Web, 'api'),
            fn (): mixed => $service->scopeForEntryWithoutTask($this->user, null),
        ] as $call) {
            try {
                $call();
                self::fail('expected a refusal');
            } catch (TaskNotTrackableException $e) {
                self::assertSame('User cannot track time without a task here.', $e->getMessage());
            }
        }
        self::assertFalse($service->allowsEntriesWithoutTask());
        self::assertNull($service->getActive($this->user));
        self::assertSame([], $this->events);
    }

    public function testATimerWithoutATaskRecordsAnEntryWithoutATaskInTheCurrentScope(): void
    {
        $billable = new StubBillableDefaultProvider(true);
        $service  = $this->service(billable: $billable);
        self::assertTrue($service->allowsEntriesWithoutTask());

        $result = $service->startAt($this->user, null, ClientType::Web, ['note' => 'first call', 'channel' => 'forged'], description: "  Call\twith ACME  ");
        $timer  = $result['timer'];
        self::assertNull($result['task']);
        self::assertSame([false, '', null, '', 'Call with ACME', 'org-a', ['note' => 'first call']], [$timer->hasTask(), $timer->getTaskId(), $timer->taskIdOrNull(), $timer->getTaskTitleSnapshot(), $timer->getDescription(), $timer->getScopeKey(), $timer->getMetadata()]);
        self::assertCount(1, $this->events);
        $started = $this->event(0);
        self::assertInstanceOf(TimerStartedWithoutTaskEvent::class, $started);
        self::assertSame(['user-1', $timer->getId(), 'org-a', ClientType::Web, null, null], [$started->userId, $started->timerId, $started->scopeKey, $started->clientType, $started->startTime, $started->getTaskId()]);

        $this->clock->modify('+30 minutes');
        $entry = $service->stop($this->user, null, 'done');
        self::assertNotNull($entry);
        self::assertSame([false, null, '', 'Call with ACME', 'org-a', 1800, null], [$entry->hasTask(), $entry->taskIdOrNull(), $entry->getTaskTitleSnapshot(), $entry->getDescription(), $entry->getScopeKey(), $entry->getDurationSeconds(), $entry->getBillable()]);
        self::assertSame("first call\ndone", ($entry->getMetadata() ?? [])['note'] ?? null);
        self::assertSame([], $billable->calls, 'the host default is per task: not asked without one');
        $stopped = $this->event(1);
        self::assertInstanceOf(TimerStopEvent::class, $stopped);
        self::assertNull($stopped->getTaskId());
        self::assertTrue($stopped->getEntryView()->withoutTask);
        self::assertNull($stopped->getEntryView()->taskIdOrNull());
        self::assertSame(['withoutTask' => true], ['withoutTask' => $entry->toArray()['withoutTask'] ?? false]);
        self::assertSame('', $entry->toArray()['taskId']);
    }

    public function testAFutureStartIsClampedAsWithATask(): void
    {
        $result = $this->service()->startAt($this->user, null, ClientType::Desktop, [], new DateTimeImmutable('2026-11-21 13:00:00'));

        self::assertSame([true, 'future', '2026-11-21 12:00:00'], [$result['startTime']?->clamped, $result['startTime']?->reason, $result['timer']->getStartedAt()->format('Y-m-d H:i:s')]);
        $started = $this->event(0);
        self::assertInstanceOf(TimerStartedWithoutTaskEvent::class, $started);
        self::assertSame($result['startTime'], $started->startTime);
    }

    public function testTheScopeMustBeOneOfTheUsersExactly(): void
    {
        $service = $this->service();
        self::assertSame('org-b', $service->start($this->user, null, ClientType::Web, scopeKey: 'org-b')->getScopeKey());
        $service->stop($this->user);

        foreach (['org-c', 'ORG-A', 'org-a ', ''] as $scope) {
            try {
                $service->start($this->user, null, ClientType::Web, scopeKey: $scope);
                self::fail('expected a refusal for ' . $scope);
            } catch (TaskNotTrackableException) {
                self::assertNull($service->getActive($this->user));
            }
        }

        // Audit YM-L2: under a scope provider, a user with no scope is refused (the entry would be its owner's only).
        $nobody = new TestUser('nobody', 'nobody@example.com');
        try {
            $service->start($nobody, null, ClientType::Web);
            self::fail('expected a refusal for a user without a scope');
        } catch (TaskNotTrackableException) {
            self::assertNull($service->getActive($nobody));
        }
        $manual = new ManualEntryService($service, $this->entries, $this->timers, new TaskProviderTaskAccessChecker(new StubTaskProvider()), scopeProvider: $this->scopes, clock: $this->clock);
        try {
            $manual->create($nobody, null, new DateTimeImmutable('2026-11-21 08:00'), new DateTimeImmutable('2026-11-21 09:00'), TimeEntrySource::Manual);
            self::fail('expected a refusal for a user without a scope');
        } catch (ManualEntryException $e) {
            self::assertSame(ManualEntryException::NOT_TRACKABLE, $e->getMessage());
        }

        // Without a scope provider (single tenant): no scope, and a requested one cannot be checked.
        $single = new TimerService(new StubTaskProvider(), new InMemoryActiveTimerRepository(), new InMemoryTimeEntryRepository(), $this->dispatcher, $this->clock, allowWithoutTask: true);
        self::assertNull($single->start($this->user, null, ClientType::Web)->getScopeKey());
        self::assertNull($single->stop($this->user)?->getScopeKey());
        $this->expectException(TaskNotTrackableException::class);
        $single->start($this->user, null, ClientType::Web, scopeKey: 'org-a');
    }

    public function testOnlyOneTimerRunsWithoutATask(): void
    {
        $service = $this->service();
        $first   = $service->start($this->user, null, ClientType::Web);

        try {
            $service->start($this->user, null, ClientType::Desktop);
            self::fail('expected a conflict');
        } catch (ActiveTimerConflictException) {
            self::assertSame($first->getId(), $service->getActive($this->user)?->getId());
        }
        $this->expectException(ActiveTimerConflictException::class);
        $service->start($this->user, 'task-1', ClientType::Web);
    }

    public function testStartReplacingWithoutATaskStopsTheRunningTimerFirst(): void
    {
        $service = $this->service();
        $service->start($this->user, 'task-1', ClientType::Web);
        $this->clock->modify('+10 minutes');

        $result = $service->startReplacing($this->user, null, ClientType::Web, 'mcp', ['note' => 'n'], 'switched', 'CRM: ACME', 'org-b');
        self::assertNull($result['task']);
        self::assertSame(['task-1'], array_map(static fn (TimeEntry $entry): string => $entry->getTaskId(), $result['stopped']));
        self::assertSame([false, 'CRM: ACME', 'org-b', 'mcp'], [$result['timer']->hasTask(), $result['timer']->getDescription(), $result['timer']->getScopeKey(), ($result['timer']->getMetadata() ?? [])['channel'] ?? null]);
        self::assertInstanceOf(TimerStartedWithoutTaskEvent::class, $this->event(2));

        // A refused scope stops nothing.
        try {
            $service->startReplacing($this->user, null, ClientType::Web, 'api', scopeKey: 'org-z');
            self::fail('expected a refusal');
        } catch (TaskNotTrackableException) {
            self::assertSame($result['timer']->getId(), $service->getActive($this->user)?->getId());
        }

        $this->clock->modify('+5 minutes');
        $next = $service->startReplacing($this->user, 'task-2', ClientType::Web, 'api');
        self::assertSame([null, 'CRM: ACME', 'org-b', 'mcp'], [$next['stopped'][0]->taskIdOrNull(), $next['stopped'][0]->getDescription(), $next['stopped'][0]->getScopeKey(), $next['stopped'][0]->channel()]);
    }

    public function testIdleDecisionsKeepATimerWithoutATaskWithoutOne(): void
    {
        $service = $this->service();
        $service->startAt($this->user, null, ClientType::Web, ['note' => 'kept on the first entry'], description: 'ACME', scopeKey: 'org-b');
        $this->clock->modify('+60 minutes');

        $split = $service->resolveIdle($this->user, new DateTimeImmutable('2026-11-21 12:20:00'), new DateTimeImmutable('2026-11-21 12:30:00'), IdleResolution::Split);
        self::assertNotNull($split);
        self::assertSame([null, 'ACME', 'org-b'], [$split['entry']?->taskIdOrNull(), $split['entry']?->getDescription(), $split['entry']?->getScopeKey()]);
        self::assertSame([false, 'ACME', 'org-b', []], [$split['timer']?->hasTask(), $split['timer']?->getDescription(), $split['timer']?->getScopeKey(), $split['timer']?->getMetadata()]);

        $reassign = $service->resolveIdle($this->user, new DateTimeImmutable('2026-11-21 12:40:00'), new DateTimeImmutable('2026-11-21 12:50:00'), IdleResolution::Reassign, 'task-2', IdleFollowUp::Continue);
        self::assertNotNull($reassign);
        self::assertSame(['task-2', null, false], [$reassign['reassigned']?->getTaskId(), $reassign['entry']?->taskIdOrNull(), $reassign['timer']?->hasTask()]);

        $switch = $service->resolveIdle($this->user, new DateTimeImmutable('2026-11-21 12:55:00'), new DateTimeImmutable('2026-11-21 12:58:00'), IdleResolution::Reassign, 'task-3', IdleFollowUp::Switch);
        self::assertNotNull($switch);
        self::assertSame(['task-3', 'task-3'], [$switch['reassigned']?->getTaskId(), $switch['timer']?->getTaskId()]);
        self::assertInstanceOf(TimerStartEvent::class, $this->lastEvent());
    }

    public function testMaskingOfEntriesWithoutATask(): void
    {
        $service   = $this->service();
        $owned     = $this->entry($this->user, 'org-a');
        $ownerless = $this->entry(null, 'org-a', TimeEntrySource::Manual);

        self::assertSame([$ownerless->getId() => true], $service->hiddenTaskEntryIds([$owned, $ownerless], $this->user));
    }

    public function testAnotherOwnersEntriesWithoutATaskAreListedOnlyInTheViewersScopes(): void
    {
        $admin = new TestUser('admin', 'admin@example.com', ['ROLE_ADMIN']);
        $this->dispatcher->addListener(TimeTrackEvents::TIME_ENTRY_LIST_QUERY, static function (TimeEntryListQueryEvent $event): void {
            $event->setUserIds(['user-1', 'admin']);
        });
        foreach ([new InMemoryTimeEntryRepository(), new InMemoryPaginatedTimeEntryRepository()] as $store) {
            $service = new TimerService(new StubTaskProvider(), new InMemoryActiveTimerRepository(), $store, $this->dispatcher, $this->clock, scopeProvider: $this->scopes, allowWithoutTask: true);
            $store->save($inScope = $this->entry($this->user, 'org-a'));
            $store->save($otherScope = $this->entry($this->user, 'org-b'));
            $store->save($withTask = new TimeEntry($this->user, 'task-1', 'Task', null, new DateTimeImmutable('2026-11-20 08:00'), new DateTimeImmutable('2026-11-20 09:00'), 3600, TimeEntrySource::Web));
            $store->save($own = $this->entry($admin, null));

            $listed = $service->listEntries($admin, new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-11-30'));
            self::assertEqualsCanonicalizing([$inScope->getId(), $withTask->getId(), $own->getId()], $this->ids($listed), $store::class);
            $page = $service->listEntriesPage($admin, new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-11-30'), 1, 2);
            self::assertSame([3, 2], [$page->total, count($page->entries)]);
            self::assertNotContains($otherScope->getId(), $this->ids($page->entries));

            // The owner lists them all, an admin of one tenant looking at the user only that tenant's; with the option
            // off the stored rows keep the tenant rule (coordinator decision 2026-11-22).
            $plain = new TimerService(new StubTaskProvider(), new InMemoryActiveTimerRepository(), $store, new EventDispatcher(), $this->clock, scopeProvider: $this->scopes, allowWithoutTask: true);
            self::assertCount(3, $plain->listEntries($this->user, new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-11-30')));
            self::assertCount(3, $plain->listEntriesPage($this->user, new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-11-30'), 1, 10)->entries);
            self::assertEqualsCanonicalizing([$inScope->getId(), $withTask->getId()], $this->ids($plain->listEntriesPage($admin, new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-11-30'), 1, 10, 'user-1')->entries));
            $off = new TimerService(new StubTaskProvider(), new InMemoryActiveTimerRepository(), $store, $this->dispatcher, $this->clock, scopeProvider: $this->scopes);
            self::assertSame(3, $off->listEntriesPage($admin, new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-11-30'), 1, 10)->total);
            self::assertNotContains($otherScope->getId(), $this->ids($off->listEntries($admin, new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-11-30'))));
        }
    }

    public function testManualEntriesWithoutATask(): void
    {
        $billable = new StubBillableDefaultProvider(true);
        $tasks    = new StubTaskProvider();
        $off      = new ManualEntryService($this->service(allow: false), $this->entries, $this->timers, new TaskProviderTaskAccessChecker($tasks), $this->dispatcher, scopeProvider: $this->scopes, clock: $this->clock);
        try {
            $off->create($this->user, null, new DateTimeImmutable('2026-11-21 08:00'), new DateTimeImmutable('2026-11-21 09:00'), TimeEntrySource::Manual);
            self::fail('expected a refusal');
        } catch (ManualEntryException $e) {
            self::assertSame(ManualEntryException::NOT_TRACKABLE, $e->getMessage());
        }

        $service = new ManualEntryService($this->service(), $this->entries, $this->timers, new TaskProviderTaskAccessChecker($tasks), $this->dispatcher, scopeProvider: $this->scopes, clock: $this->clock, billableDefaults: $billable);
        $entry   = $service->create($this->user, null, new DateTimeImmutable('2026-11-21 08:00'), new DateTimeImmutable('2026-11-21 09:00'), TimeEntrySource::Manual, 'note', description: ' Visit ACME ', scopeKey: 'org-b');
        self::assertSame([null, '', 'Visit ACME', 'org-b', null, ['note' => 'note']], [$entry->taskIdOrNull(), $entry->getTaskTitleSnapshot(), $entry->getDescription(), $entry->getScopeKey(), $entry->getBillable(), $entry->getMetadata()]);
        self::assertSame([], $billable->calls);
        $created = $this->lastEvent();
        self::assertInstanceOf(TimeEntryCreatedEvent::class, $created);
        self::assertNull($created->getTaskId());
        self::assertFalse($service->create($this->user, null, new DateTimeImmutable('2026-11-21 09:00'), new DateTimeImmutable('2026-11-21 09:30'), TimeEntrySource::Manual, billable: false)->getBillable());
        $withTask = $service->create($this->user, 'task-1', new DateTimeImmutable('2026-11-21 10:00'), new DateTimeImmutable('2026-11-21 10:30'), TimeEntrySource::Manual, description: 'ignored with a task');
        self::assertSame(['task-1', null, true], [$withTask->getTaskId(), $withTask->getDescription(), $withTask->getBillable()]);

        $this->expectException(ManualEntryException::class);
        $service->create($this->user, null, new DateTimeImmutable('2026-11-21 11:00'), new DateTimeImmutable('2026-11-21 11:30'), TimeEntrySource::Manual, scopeKey: 'org-c');
    }

    public function testOnlyTheOwnerOrTheSameTenantReachesAnEntryWithoutATask(): void
    {
        $admin      = new TestUser('admin', 'admin@example.com', ['ROLE_ADMIN']);
        $otherAdmin = new TestUser('other-admin', 'other@example.com', ['ROLE_ADMIN']);
        $guard      = new TeamAccessGuard(new StubTeamContextProvider(['user-1'], true), $this->dispatcher, ['ROLE_ADMIN'], true, true, scopeProvider: $this->scopes);
        $inA        = $this->entry($this->user, 'org-a');
        $noScope    = $this->entry($this->user, null);
        $withTask   = new TimeEntry($this->user, 'task-1', 'Task', null, new DateTimeImmutable('2026-11-20 08:00'), new DateTimeImmutable('2026-11-20 09:00'), 3600, TimeEntrySource::Web);

        self::assertTrue($guard->isEditGranted($this->user, $inA));
        self::assertTrue($guard->isEditGranted($this->user, $noScope));
        self::assertTrue($guard->isEditGranted($admin, $inA));
        self::assertFalse($guard->isEditGranted($otherAdmin, $inA));
        self::assertFalse($guard->isEditGranted($admin, $noScope));
        self::assertTrue($guard->isEditGranted($otherAdmin, $withTask), 'entries with a task are unchanged');
        self::assertFalse($guard->isBillableEditGranted($otherAdmin, $inA));
        self::assertTrue($guard->isBillableEditGranted($admin, $inA));

        // A listener that grants (e.g. through a board) never crosses tenants for an entry without a task.
        $seen = [];
        $this->dispatcher->addListener(TimeTrackEvents::TIME_ENTRY_ACCESS_CHECK, static function (TimeEntryAccessCheckEvent $event) use (&$seen): void {
            $seen[] = $event->getTaskId();
            $event->grant();
        });
        $stranger = new TestUser('stranger', 'stranger@example.com');
        self::assertFalse($guard->isEditGranted($stranger, $inA));
        self::assertFalse($guard->isBillableEditGranted($stranger, $inA));
        self::assertTrue($guard->isEditGranted($stranger, $withTask));
        self::assertSame([null, null, null, 'task-1'], $seen);

        // A single-tenant host (no scope provider) keeps the per-user rules.
        self::assertTrue((new TeamAccessGuard(new StubTeamContextProvider(), new EventDispatcher(), ['ROLE_ADMIN'], true, true))->isWithinTenant($otherAdmin, $inA));
    }

    public function testTheHostTimerApiStartsATimerWithoutATask(): void
    {
        $api = new HostTimerApi($this->service(), new TaskProviderTaskAccessChecker(new StubTaskProvider()));
        $api->start(TimeTrackUserRefFactory::build($this->user), 'task-1');
        $this->clock->modify('+15 minutes');

        $start = $api->startWithoutTask(TimeTrackUserRefFactory::build($this->user), 'ACME call', 'note', 'mcp', 'org-b');
        self::assertSame([true, '', null, 'ACME call', 'org-b', 'mcp', 'note'], [$start->timer->withoutTask, $start->timer->taskId, $start->timer->taskIdOrNull(), $start->timer->description, $start->timer->scopeKey, $start->timer->channel, $start->timer->note]);
        self::assertSame(['task-1', 'task-1', false], [$start->stopped?->taskId, $start->stopped?->taskIdOrNull(), $start->stopped?->withoutTask]);
        self::assertSame([true, 'ACME call'], [$api->current(TimeTrackUserRefFactory::build($this->user))?->withoutTask, $api->current(TimeTrackUserRefFactory::build($this->user))?->description]);

        $this->clock->modify('+15 minutes');
        $entry = $api->stop(TimeTrackUserRefFactory::build($this->user));
        self::assertSame([true, null, 'ACME call', 'org-b', 900], [$entry?->withoutTask, $entry?->taskIdOrNull(), $entry?->description, $entry?->scopeKey, $entry?->durationSeconds]);
    }

    private function event(int $index): ?object
    {
        return $this->events[$index] ?? null;
    }

    private function lastEvent(): ?object
    {
        return $this->events === [] ? null : $this->events[count($this->events) - 1];
    }

    private function service(bool $allow = true, ?StubBillableDefaultProvider $billable = null, ?TimesheetScopeProviderInterface $scopes = null): TimerService
    {
        return new TimerService(new StubTaskProvider(), $this->timers, $this->entries, $this->dispatcher, $this->clock, scopeProvider: $scopes ?? $this->scopes, billableDefaults: $billable, allowWithoutTask: $allow);
    }

    private function entry(?object $user, ?string $scopeKey, TimeEntrySource $source = TimeEntrySource::Web): TimeEntry
    {
        $entry = (new TimeEntry($user, null, '', null, new DateTimeImmutable('2026-11-20 10:00'), new DateTimeImmutable('2026-11-20 11:00'), 3600, $source))->describe('ACME');
        $entry->assignScopeKey($scopeKey);

        return $entry;
    }

    /**
     * @param list<TimeEntry> $entries
     *
     * @return list<string>
     */
    private function ids(array $entries): array
    {
        return array_map(static fn (TimeEntry $entry): string => $entry->getId(), $entries);
    }
}
