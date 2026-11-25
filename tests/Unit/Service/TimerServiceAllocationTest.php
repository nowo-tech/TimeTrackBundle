<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\IdleFollowUp;
use Nowo\TimeTrackBundle\Enum\IdleResolution;
use Nowo\TimeTrackBundle\Event\TimeEntryUnallocatedEvent;
use Nowo\TimeTrackBundle\Event\TimerAllocatedEvent;
use Nowo\TimeTrackBundle\Event\TimerStartedWithoutTaskEvent;
use Nowo\TimeTrackBundle\Event\TimerStartEvent;
use Nowo\TimeTrackBundle\Event\TimerStopEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Exception\ActiveTimerConflictException;
use Nowo\TimeTrackBundle\Exception\TaskNotTrackableException;
use Nowo\TimeTrackBundle\Exception\TimeAllocationException;
use Nowo\TimeTrackBundle\Exception\TimeAllocationRequiredException;
use Nowo\TimeTrackBundle\Repository\ActiveTimerRepositoryInterface;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Subject\TimeAllocation;
use Nowo\TimeTrackBundle\Subject\TimeAllocationPatch;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;
use Nowo\TimeTrackBundle\Tests\Stub\StubBillableDefaultProvider;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\AllocationFixtures;
use Nowo\TimeTrackBundle\Tests\Support\CustomerTestSubjectProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedActivityTypeProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Tests\Support\HookedActiveTimerRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryActiveTimerRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimeEntryRepository;
use Nowo\TimeTrackBundle\Tests\Support\InternalTestSubjectProvider;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function array_column;
use function array_filter;
use function array_intersect_key;
use function array_map;
use function array_values;
use function count;
use function json_encode;

/**
 * Since 2.1.0 (Beacon spec 256, design §3, §7, §13, §15): the timer write paths with a subject and an activity type —
 * checks before anything is stopped, the interactive refusal inside the unit of work, system stops that are never
 * refused, idle reassignment to a subject, `allocateRunning()` and the billable precedence (Q-TS-9).
 */
final class TimerServiceAllocationTest extends TestCase
{
    private TestUser $user;

    private MockClock $clock;

    private ActiveTimerRepositoryInterface $timers;

    private InMemoryTimeEntryRepository $entries;

    private CustomerTestSubjectProvider $customers;

    private InternalTestSubjectProvider $internal;

    private FixedActivityTypeProvider $activities;

    private StubBillableDefaultProvider $billable;

    private RecordingLogger $logger;

    /** @var list<array{string, object}> */
    private array $events = [];

    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->user                = new TestUser('user-1', 'person@example.com');
        $this->clock               = new MockClock('2026-11-24 10:00:00');
        $this->timers              = new InMemoryActiveTimerRepository();
        $this->entries             = new InMemoryTimeEntryRepository();
        $this->customers           = new CustomerTestSubjectProvider();
        $this->customers->loggable = ['c-1', 'c-2'];
        $this->internal            = new InternalTestSubjectProvider();
        $this->internal->loggable  = ['i-1'];
        $this->activities          = new FixedActivityTypeProvider();
        $this->billable            = new StubBillableDefaultProvider(true);
        $this->logger              = new RecordingLogger();
        $this->dispatcher          = new EventDispatcher();
        foreach ([TimeTrackEvents::TIMER_START, TimeTrackEvents::TIMER_START_WITHOUT_TASK, TimeTrackEvents::TIMER_STOP, TimeTrackEvents::TIMER_ALLOCATED, TimeTrackEvents::TIME_ENTRY_UNALLOCATED] as $name) {
            $this->dispatcher->addListener($name, function (object $event) use ($name): void {
                $this->events[] = [$name, $event];
            });
        }
    }

    public function testATaskAndASubjectAllowingTasksAreStoredOnTheTimerAndItsEntry(): void
    {
        $service = $this->service();
        $timer   = $service->start($this->user, TimeAllocation::task('task-1', $this->customer('c-1'), 'dev'), ClientType::Web);

        self::assertSame(['task-1', 'customer', 'c-1', 'dev'], [$timer->getTaskId(), $timer->subject()?->type, $timer->subject()?->id, $timer->getActivityTypeKey()]);
        // The subject is checked for the owner in the task's scope (I-6).
        self::assertSame([['actor' => 'user-1', 'scope' => 'org-a', 'id' => 'c-1']], $this->customers->canLogCalls);
        $start = $this->event(TimerStartEvent::class);
        self::assertSame(['c-1', 'dev'], [$start->getSubject()?->id, $start->getActivityTypeKey()]);
        self::assertSame(['subject_type' => 'customer', 'activity_type' => true], array_intersect_key($this->contextOf('TimeTrack timer started.'), ['subject_type' => 1, 'activity_type' => 1]));
        self::assertStringNotContainsString('c-1', (string) json_encode($this->logger->records), 'never the subject id in a log');

        $this->clock->modify('+1 hour');
        $entry = $service->stop($this->user);
        self::assertNotNull($entry);
        self::assertSame(['task-1', 'c-1', 'dev', true], [$entry->getTaskId(), $entry->subject()?->id, $entry->getActivityTypeKey(), $entry->getBillable()]);
        $stop = $this->event(TimerStopEvent::class);
        self::assertSame(['c-1', 'dev'], [$stop->getSubject()?->id, $stop->getActivityTypeKey()]);
        self::assertSame([], $this->billable->calls, 'the activity type default comes before the host provider (Q-TS-9)');

        // Start-replacing on a task and a subject (HostTimerApi::startAllocated()).
        $replacing = $service->startReplacing($this->user, TimeAllocation::task('task-3', $this->customer('c-2'), 'support'), ClientType::Web, 'mcp');
        self::assertSame(['task-3', 'c-2', 'support'], [$replacing['timer']->getTaskId(), $replacing['timer']->subject()?->id, $replacing['timer']->getActivityTypeKey()]);
        self::assertSame(['org-a', 'org-b'], array_column($this->customers->canLogCalls, 'scope'), 'task-3 is on board-2: org-b');
        self::assertSame(['c-2', 'support'], [$this->event(TimerStartEvent::class)->getSubject()?->id, $this->event(TimerStartEvent::class)->getActivityTypeKey()]);
    }

    public function testATaskWithASubjectTypeThatDisallowsTasksIsAConflictOnEveryStartPath(): void
    {
        $service    = $this->service();
        $allocation = TimeAllocation::task('task-1', $this->internal('i-1'));
        foreach ([
            fn (): mixed => $service->start($this->user, $allocation, ClientType::Web),
            fn (): mixed => $service->startReplacing($this->user, $allocation, ClientType::Web, 'api'),
        ] as $call) {
            $this->refused(TimeAllocationException::ALLOCATION_CONFLICT, $call);
        }
        self::assertSame([], $this->internal->canLogCalls, 'the type is refused before the provider is asked');
        self::assertNull($service->getActive($this->user));

        $running = $service->start($this->user, 'task-2', ClientType::Web);
        $this->refused(TimeAllocationException::ALLOCATION_CONFLICT, fn (): mixed => $service->switchTo($this->user, $allocation, ClientType::Web));
        $this->refused(TimeAllocationException::ALLOCATION_CONFLICT, fn (): mixed => $service->startReplacing($this->user, $allocation, ClientType::Web, 'api'));
        self::assertSame($running->getId(), $service->getActive($this->user)?->getId(), 'the running timer is untouched');
        self::assertSame([], $this->stored());
    }

    public function testUnknownTypeUnknownIdNoAccessAndAFailingProviderGiveOneAnswer(): void
    {
        $service = $this->service();
        foreach ([new TimeSubjectRef('vendor', 'c-1'), $this->customer('c-404'), $this->internal('c-1')] as $subject) {
            $this->refused(TimeAllocationException::SUBJECT_FORBIDDEN, fn (): mixed => $service->start($this->user, TimeAllocation::subject($subject), ClientType::Web));
        }
        $this->customers->fail = true;
        $this->refused(TimeAllocationException::SUBJECT_FORBIDDEN, fn (): mixed => $service->start($this->user, TimeAllocation::subject($this->customer('c-1')), ClientType::Web));
        self::assertNull($service->getActive($this->user));
        self::assertSame(['exception' => 'RuntimeException'], array_intersect_key($this->contextOf('TimeTrack subject provider failed; answered as denied.'), ['exception' => 1]));
        self::assertStringNotContainsString('secret customer', (string) json_encode($this->logger->records));
    }

    public function testASubjectTimerNeedsNoTaskOptionAndLandsInOneOfTheUsersScopes(): void
    {
        $service = $this->service(allowWithoutTask: false);
        $result  = $service->startAt($this->user, TimeAllocation::subject($this->internal('i-1'), 'meeting'), ClientType::Desktop, scopeKey: 'org-b');

        self::assertNull($result['task']);
        self::assertSame([false, 'org-b', 'i-1', 'meeting'], [$result['timer']->hasTask(), $result['timer']->getScopeKey(), $result['timer']->subject()?->id, $result['timer']->getActivityTypeKey()]);
        self::assertSame('org-b', $this->internal->canLogCalls[0]['scope']);
        $started = $this->event(TimerStartedWithoutTaskEvent::class);
        self::assertSame(['i-1', 'meeting'], [$started->getSubject()?->id, $started->getActivityTypeKey()]);
        self::assertNull($this->event(TimerStartEvent::class, false), 'TIMER_START stays task-only');

        $this->clock->modify('+30 minutes');
        $entry = $service->stop($this->user);
        self::assertSame([null, 'org-b', 'i-1', 'meeting', null], [$entry?->taskIdOrNull(), $entry?->getScopeKey(), $entry?->subject()?->id, $entry?->getActivityTypeKey(), $entry?->getBillable()], 'no default anywhere: unset');

        // A scope that is not one of the user's is the subject answer, not the task one.
        foreach (['org-c', 'ORG-A'] as $scope) {
            $this->refused(TimeAllocationException::SUBJECT_FORBIDDEN, fn (): mixed => $service->start($this->user, TimeAllocation::subject($this->internal('i-1')), ClientType::Web, scopeKey: $scope));
        }
        // Without a subject the 1.36 rule stays: the option is off.
        $this->expectException(TaskNotTrackableException::class);
        $service->start($this->user, TimeAllocation::none(), ClientType::Web);
    }

    public function testActivityTypesMustExistAndBeActiveAndNeedAProvider(): void
    {
        $service = $this->service();
        $this->refused(TimeAllocationException::ACTIVITY_TYPE_UNKNOWN, fn (): mixed => $service->start($this->user, TimeAllocation::task('task-1', null, 'nope'), ClientType::Web));
        $this->refused(TimeAllocationException::ACTIVITY_TYPE_INACTIVE, fn (): mixed => $service->start($this->user, TimeAllocation::task('task-1', null, 'legacy'), ClientType::Web));
        $this->activities->fail = true;
        $this->refused(TimeAllocationException::ACTIVITY_TYPE_UNKNOWN, fn (): mixed => $service->start($this->user, TimeAllocation::task('task-1', null, 'dev'), ClientType::Web));

        $off = $this->service(activities: false);
        $this->refused(TimeAllocationException::ACTIVITY_TYPE_UNKNOWN, fn (): mixed => $off->start($this->user, TimeAllocation::task('task-1', null, 'dev'), ClientType::Web));
        self::assertNull($service->getActive($this->user));
    }

    public function testWithoutAnyProviderEveryNewFieldIsRefusedAndTheColumnsStayNull(): void
    {
        $service = new TimerService(new StubTaskProvider(), $this->timers, $this->entries, $this->dispatcher, $this->clock, scopeProvider: $this->scopes(), allowWithoutTask: true);
        self::assertFalse($service->allocationGuard()->subjects()->isEnabled());
        $this->refused(TimeAllocationException::SUBJECT_FORBIDDEN, fn (): mixed => $service->start($this->user, TimeAllocation::subject($this->customer('c-1')), ClientType::Web));
        $this->refused(TimeAllocationException::ACTIVITY_TYPE_UNKNOWN, fn (): mixed => $service->start($this->user, TimeAllocation::none('dev'), ClientType::Web));

        $service->start($this->user, 'task-1', ClientType::Web);
        $this->refused(TimeAllocationException::SUBJECT_FORBIDDEN, fn (): mixed => $service->stop($this->user, null, null, TimeAllocationPatch::keep()->withSubject($this->customer('c-1'))));
        $this->refused(TimeAllocationException::ACTIVITY_TYPE_UNKNOWN, fn (): mixed => $service->stop($this->user, null, null, TimeAllocationPatch::keep()->withActivityType('dev')));
        $this->refused(TimeAllocationException::ACTIVITY_TYPE_UNKNOWN, fn (): mixed => $service->allocateRunning($this->user, TimeAllocationPatch::keep()->withActivityType('dev')));
        $entry = $service->stop($this->user, null, null, null, true);
        self::assertSame([null, null], [$entry?->subject(), $entry?->getActivityTypeKey()]);
    }

    public function testAnInteractiveStopWithoutAllocationIsRefusedInsideTheUnitAndTheTimerKeepsRunning(): void
    {
        $service = $this->service(requireAllocation: true);
        $timer   = $service->start($this->user, TimeAllocation::none('dev'), ClientType::Web, scopeKey: 'org-b');
        $this->clock->modify('+20 minutes');

        foreach ([
            fn (): mixed => $service->stop($this->user, null, 'note', null, true),
            fn (): mixed => $service->stopAt($this->user, new DateTimeImmutable('2026-11-24 10:15:00'), null, null, TimeAllocationPatch::keep()->withActivityType('support'), true),
        ] as $call) {
            try {
                $call();
                self::fail('expected allocation_required');
            } catch (TimeAllocationRequiredException $exception) {
                self::assertSame([TimeAllocationException::ALLOCATION_REQUIRED, 409], [$exception->errorCode, $exception->httpStatus()]);
                self::assertSame([$timer->getId(), true, 'org-b', 'dev'], [$exception->timer->timerId, $exception->timer->withoutTask, $exception->timer->scopeKey, $exception->timer->activityTypeKey]);
            }
        }
        self::assertSame($timer->getId(), $service->getActive($this->user)?->getId(), 'the timer keeps running');
        self::assertSame([], $this->stored(), 'nothing is written');
        self::assertSame([], $this->names(TimeTrackEvents::TIMER_STOP));

        // The second call carries the subject and the end the dialog showed.
        $result = $service->stopAt($this->user, new DateTimeImmutable('2026-11-24 10:15:00'), null, null, TimeAllocationPatch::keep()->withSubject($this->customer('c-1')), true);
        self::assertNotNull($result);
        self::assertSame([900, 'c-1', 'org-b', 'dev'], [$result['entry']->getDurationSeconds(), $result['entry']->subject()?->id, $result['entry']->getScopeKey(), $result['entry']->getActivityTypeKey()]);
        self::assertSame('org-b', $this->customers->canLogCalls[0]['scope'], 'checked in the timer\'s scope');
        self::assertSame([], $this->names(TimeTrackEvents::TIME_ENTRY_UNALLOCATED));
        self::assertNull($service->stopAt($this->user, null, null, null, null, true), 'no timer: nothing to refuse');
        self::assertNull($service->stop($this->user, null, null, null, true));
    }

    public function testSystemStopsAreNeverRefusedAndAnnounceTheUnallocatedEntry(): void
    {
        $service = $this->service(requireAllocation: true);

        // The stop of a 2.0 caller (not interactive).
        $service->start($this->user, null, ClientType::Web);
        $this->clock->modify('+5 minutes');
        $plain = $service->stop($this->user);
        self::assertNotNull($plain);
        $events = $this->of(TimeEntryUnallocatedEvent::class);
        self::assertSame([[$plain->getId(), 'user-1', 'org-a', null]], array_map(static fn (TimeEntryUnallocatedEvent $e): array => [$e->entryId, $e->userId, $e->scopeKey, $e->closedBy], $events));

        // Start-replacing and switch stop the unallocated timer without asking.
        $service->start($this->user, null, ClientType::Web);
        $this->clock->modify('+5 minutes');
        $replaced = $service->startReplacing($this->user, TimeAllocation::subject($this->customer('c-1')), ClientType::Web, 'api');
        self::assertCount(1, $replaced['stopped']);
        self::assertSame('c-1', $replaced['timer']->subject()?->id);
        $this->clock->modify('+5 minutes');
        $switched = $service->switchTo($this->user, TimeAllocation::none(), ClientType::Web);
        self::assertSame('c-1', $switched['entry']?->subject()?->id, 'the switch stores the old timer\'s subject');
        $this->clock->modify('+5 minutes');

        // User deletion.
        $deleted = $service->stopForUserDeletion($this->user);
        self::assertNotNull($deleted);
        $closedBy = array_map(static fn (TimeEntryUnallocatedEvent $e): ?string => $e->closedBy, $this->of(TimeEntryUnallocatedEvent::class));
        self::assertSame([null, 'replaced', TimeEntry::CLOSED_BY_USER_DELETION], $closedBy, 'one event per unallocated system stop (marked as the entry is), none for the subject entry');

        // Without require_allocation the event is never sent.
        $this->events = [];
        $relaxed      = $this->service();
        $relaxed->start($this->user, null, ClientType::Web);
        $relaxed->stop($this->user, null, null, null, true);
        self::assertSame([], $this->of(TimeEntryUnallocatedEvent::class));
    }

    public function testRequireActivityTypeRefusesAnInteractiveStopWithoutOne(): void
    {
        $service = $this->service(requireActivityType: true);
        $service->start($this->user, 'task-1', ClientType::Web);
        try {
            $service->stop($this->user, null, null, null, true);
            self::fail('expected activity_type_required');
        } catch (TimeAllocationRequiredException $exception) {
            self::assertSame(TimeAllocationException::ACTIVITY_TYPE_REQUIRED, $exception->errorCode);
        }
        self::assertSame('support', $service->stop($this->user, null, null, TimeAllocationPatch::keep()->withActivityType('support'), true)?->getActivityTypeKey());

        // Effective only with a provider.
        $off = $this->service(requireActivityType: true, activities: false);
        $off->start($this->user, 'task-1', ClientType::Web);
        self::assertNotNull($off->stop($this->user, null, null, null, true));
    }

    public function testAStopPatchSetsChangesOrClearsTheSubjectAndTheActivityTypeButNeverTheTask(): void
    {
        $service = $this->service();
        $service->start($this->user, TimeAllocation::task('task-1', $this->customer('c-1'), 'dev'), ClientType::Web);
        $this->refused(TimeAllocationException::ALLOCATION_CONFLICT, fn (): mixed => $service->stop($this->user, null, null, TimeAllocationPatch::keep()->withSubject($this->internal('i-1'))));
        $this->refused(TimeAllocationException::SUBJECT_FORBIDDEN, fn (): mixed => $service->stop($this->user, null, null, TimeAllocationPatch::keep()->withSubject($this->customer('c-9'))));
        $this->refused(TimeAllocationException::ACTIVITY_TYPE_INACTIVE, fn (): mixed => $service->stop($this->user, null, null, TimeAllocationPatch::keep()->withActivityType('legacy')));
        self::assertNotNull($service->getActive($this->user));
        $calls = count($this->customers->canLogCalls);

        // The same subject again is no change: not asked again.
        $entry = $service->stop($this->user, null, null, TimeAllocationPatch::keep()->withSubject($this->customer('c-1'))->withoutActivityType(), true);
        self::assertSame(['task-1', 'c-1', null, true], [$entry?->getTaskId(), $entry?->subject()?->id, $entry?->getActivityTypeKey(), $entry?->getBillable()], 'no activity type: the host default');
        self::assertCount($calls, $this->customers->canLogCalls);

        // Clearing the subject of a task timer is always allowed (the task allocates it).
        $service->start($this->user, TimeAllocation::task('task-1', $this->customer('c-1')), ClientType::Web);
        self::assertSame(['task-1', null], [$service->stop($this->user, null, null, TimeAllocationPatch::keep()->withoutSubject(), true)?->getTaskId(), $this->stored()[1]->subject()]);
    }

    public function testBillablePrecedenceIsActivityTypeThenHostThenNone(): void
    {
        $service = $this->service();
        $results = [];
        foreach (['support', 'meeting', null] as $key) {
            $service->start($this->user, TimeAllocation::task('task-1', null, $key), ClientType::Web);
            $this->clock->modify('+10 minutes');
            $results[] = $service->stop($this->user)?->getBillable();
        }
        self::assertSame([false, true, true], $results);
        // The activity type's list is read for the timer's scope (task-2 is on board-1: org-a).
        self::assertContains('org-a', $this->activities->asked);

        // A subject entry without a task asks no host default.
        $this->billable->calls = [];
        $service->start($this->user, TimeAllocation::subject($this->customer('c-1')), ClientType::Web);
        self::assertNull($service->stop($this->user)?->getBillable());
        self::assertSame([], $this->billable->calls);

        // A key the provider no longer lists (retired between start and stop) stores the key, with the host default.
        $service->start($this->user, TimeAllocation::task('task-1', null, 'meeting'), ClientType::Web);
        $this->activities->perScope['org-a'] = [];
        self::assertSame(['meeting', true], [$service->stop($this->user)?->getActivityTypeKey(), $this->stored()[4]->getBillable()]);
    }

    public function testAllocateRunningSetsChangesAndClearsUnderTheRules(): void
    {
        $service = $this->service();
        self::assertNull($service->allocateRunning($this->user, TimeAllocationPatch::keep()->withSubject($this->customer('c-1'))), 'no timer');
        $timer = $service->start($this->user, 'task-1', ClientType::Web);
        self::assertSame($timer, $service->allocateRunning($this->user, TimeAllocationPatch::keep()));

        $after = $service->allocateRunning($this->user, TimeAllocationPatch::keep()->withSubject($this->customer('c-1'))->withActivityType('dev'));
        self::assertSame([$timer->getId(), 'c-1', 'dev'], [$after?->getId(), $after?->subject()?->id, $after?->getActivityTypeKey()]);
        $event = $this->event(TimerAllocatedEvent::class);
        self::assertSame(['user-1', $timer->getId(), 'task-1', null, null, 'c-1', 'dev'], [$event->userId, $event->timerId, $event->taskId, $event->previousSubject, $event->previousActivityTypeKey, $event->subject?->id, $event->activityTypeKey]);

        // The same values again: nothing changed, no event.
        $service->allocateRunning($this->user, TimeAllocationPatch::keep()->withSubject($this->customer('c-1'))->withActivityType('dev'));
        self::assertCount(1, $this->of(TimerAllocatedEvent::class));

        $this->refused(TimeAllocationException::ALLOCATION_CONFLICT, fn (): mixed => $service->allocateRunning($this->user, TimeAllocationPatch::keep()->withSubject($this->internal('i-1'))));
        $this->refused(TimeAllocationException::SUBJECT_FORBIDDEN, fn (): mixed => $service->allocateRunning($this->user, TimeAllocationPatch::keep()->withSubject($this->customer('c-9'))));
        $this->refused(TimeAllocationException::ACTIVITY_TYPE_INACTIVE, fn (): mixed => $service->allocateRunning($this->user, TimeAllocationPatch::keep()->withActivityType('legacy')));
        $cleared = $service->allocateRunning($this->user, TimeAllocationPatch::keep()->withoutSubject());
        self::assertSame(['task-1', null, 'dev'], [$cleared?->getTaskId(), $cleared?->subject(), $cleared?->getActivityTypeKey()]);
        self::assertSame(['c-1', null], [$this->of(TimerAllocatedEvent::class)[1]->previousSubject?->id, $this->of(TimerAllocatedEvent::class)[1]->subject]);
    }

    public function testClearingTheSubjectOfATaskLessTimerFollowsTheClearingRule(): void
    {
        $clear = TimeAllocationPatch::keep()->withoutSubject();
        foreach ([[true, true, false], [false, false, false], [false, true, true]] as [$requireAllocation, $allowWithoutTask, $allowed]) {
            $this->timers = new InMemoryActiveTimerRepository();
            $service      = $this->service(requireAllocation: $requireAllocation, allowWithoutTask: $allowWithoutTask);
            $service->start($this->user, TimeAllocation::subject($this->customer('c-1')), ClientType::Web);
            if ($allowed) {
                self::assertNull($service->allocateRunning($this->user, $clear)?->subject());
                continue;
            }
            $this->refused(TimeAllocationException::ALLOCATION_REQUIRED, fn (): mixed => $service->allocateRunning($this->user, $clear));
            self::assertSame('c-1', $service->getActive($this->user)?->subject()?->id);
        }
    }

    public function testAllocateRunningChecksAgainWhenASwitchReplacedTheTimer(): void
    {
        $timers       = new HookedActiveTimerRepository();
        $this->timers = $timers;
        $service      = $this->service();
        $first        = $service->start($this->user, 'task-1', ClientType::Web);
        $second       = null;
        $replace      = function (HookedActiveTimerRepository $repository) use ($first, &$second): void {
            $repository->remove($first);
            $second = new ActiveTimer($this->user, 'task-2', 'Write unit tests', 'board-1', ClientType::Desktop, null, $this->clock->now());
            $repository->save($second);
        };
        // Read 1 = before the checks (first timer), read 2 = under the lock (replaced), read 3 = the new attempt, read 4 = its lock.
        $timers->beforeFinds(null, $replace, null, null);
        $calls = count($this->customers->canLogCalls);

        $after = $service->allocateRunning($this->user, TimeAllocationPatch::keep()->withSubject($this->customer('c-1')));
        self::assertNotNull($second);
        self::assertSame([$second->getId(), 'c-1'], [$after?->getId(), $after?->subject()?->id], 'lands on the running timer');
        self::assertCount($calls + 2, $this->customers->canLogCalls, 'checked again for the new timer');
        self::assertNull($first->subject());

        // Stopped meanwhile: null, no event.
        $events = count($this->of(TimerAllocatedEvent::class));
        $timers->beforeFinds(null, static function (HookedActiveTimerRepository $repository) use ($second): void {
            $repository->remove($second);
        });
        self::assertNull($service->allocateRunning($this->user, TimeAllocationPatch::keep()->withActivityType('dev')));
        self::assertCount($events, $this->of(TimerAllocatedEvent::class));

        // Replaced on every attempt: a conflict.
        $current = $service->start($this->user, 'task-1', ClientType::Web);
        $swap    = function (HookedActiveTimerRepository $repository) use (&$current): void {
            $repository->remove($current);
            $current = new ActiveTimer($this->user, 'task-1', 'Implement login API', 'board-1', ClientType::Web, null, $this->clock->now());
            $repository->save($current);
        };
        $timers->beforeFinds(null, $swap, null, $swap, null, $swap, null, $swap);
        $this->expectException(ActiveTimerConflictException::class);
        $service->allocateRunning($this->user, TimeAllocationPatch::keep()->withActivityType('dev'));
    }

    public function testIdleSpansMayBeReassignedToASubject(): void
    {
        $service = $this->service(requireAllocation: true);
        $timer   = $service->start($this->user, TimeAllocation::task('task-1', null, 'dev'), ClientType::Desktop);
        $this->clock->modify('+1 hour');

        $result = $service->resolveIdle($this->user, new DateTimeImmutable('2026-11-24 10:30:00'), new DateTimeImmutable('2026-11-24 10:45:00'), IdleResolution::Reassign, TimeAllocation::subject($this->customer('c-1'), 'support'));
        self::assertNotNull($result);
        $reassigned = $result['reassigned'];
        self::assertNotNull($reassigned);
        self::assertSame([null, 'c-1', 'support', 'org-a', false, 900], [$reassigned->taskIdOrNull(), $reassigned->subject()?->id, $reassigned->getActivityTypeKey(), $reassigned->getScopeKey(), $reassigned->getBillable(), $reassigned->getDurationSeconds()], 'the subject lands in the running timer\'s scope');
        self::assertSame(['task-1', 'dev'], [$result['entry']?->getTaskId(), $result['entry']?->getActivityTypeKey()]);
        // The follow-up continues what the stopped timer tracked, with its activity type.
        self::assertSame(['task-1', null, 'dev'], [$result['timer']?->getTaskId(), $result['timer']?->subject(), $result['timer']?->getActivityTypeKey()]);
        self::assertNotSame($timer->getId(), $result['timer']?->getId());
        self::assertSame([], $this->of(TimeEntryUnallocatedEvent::class));

        // Switch: the follow-up timer runs on the subject alone, in the same scope.
        $this->clock->modify('+1 hour');
        $switched = $service->resolveIdle($this->user, new DateTimeImmutable('2026-11-24 11:30:00'), new DateTimeImmutable('2026-11-24 11:40:00'), IdleResolution::Reassign, TimeAllocation::subject($this->customer('c-2'), 'meeting'), IdleFollowUp::Switch, ClientType::Web);
        $next     = $switched['timer'] ?? null;
        self::assertNotNull($next);
        self::assertSame([false, 'c-2', 'meeting', 'org-a', ClientType::Web], [$next->hasTask(), $next->subject()?->id, $next->getActivityTypeKey(), $next->getScopeKey(), $next->getClientType()]);
        $withoutTask = $this->of(TimerStartedWithoutTaskEvent::class);
        self::assertSame('c-2', $withoutTask[count($withoutTask) - 1]->getSubject()?->id);

        // Split continues the subject timer with its subject and activity type.
        $this->clock->modify('+30 minutes');
        $split = $service->resolveIdle($this->user, new DateTimeImmutable('2026-11-24 12:00:00'), new DateTimeImmutable('2026-11-24 12:05:00'), IdleResolution::Split);
        self::assertNotNull($split);
        self::assertSame(['c-2', 'meeting', 'org-a'], [$split['timer']?->subject()?->id, $split['timer']?->getActivityTypeKey(), $split['timer']?->getScopeKey()]);
        self::assertSame('c-2', $split['entry']?->subject()?->id);
    }

    public function testAnIdleReassignTargetIsCheckedBeforeAnyWrite(): void
    {
        $service = $this->service();
        $service->start($this->user, TimeAllocation::task('task-1', $this->customer('c-1')), ClientType::Desktop);
        $this->clock->modify('+1 hour');
        $from = new DateTimeImmutable('2026-11-24 10:30:00');
        $to   = new DateTimeImmutable('2026-11-24 10:45:00');

        foreach ([null, '', TimeAllocation::none('dev')] as $target) {
            try {
                $service->resolveIdle($this->user, $from, $to, IdleResolution::Reassign, $target);
                self::fail('expected a refusal');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('taskId is required to reassign an idle span.', $exception->getMessage());
            }
        }
        try {
            $service->resolveIdle($this->user, $from, $to, IdleResolution::Reassign, TimeAllocation::task('task-1', $this->customer('c-1')));
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('The idle span is already on this task: use keep or split.', $exception->getMessage());
        }
        $this->refused(TimeAllocationException::SUBJECT_FORBIDDEN, fn (): mixed => $service->resolveIdle($this->user, $from, $to, IdleResolution::Reassign, TimeAllocation::subject($this->customer('c-9'))));
        $this->refused(TimeAllocationException::ALLOCATION_CONFLICT, fn (): mixed => $service->resolveIdle($this->user, $from, $to, IdleResolution::Reassign, TimeAllocation::task('task-2', $this->internal('i-1'))));
        self::assertSame([], $this->stored());

        // The same task with another subject is another allocation; a task target with a subject is checked in its scope.
        $result = $service->resolveIdle($this->user, $from, $to, IdleResolution::Reassign, TimeAllocation::task('task-3', $this->customer('c-2')), IdleFollowUp::Stop);
        self::assertNotNull($result);
        self::assertSame(['task-3', 'c-2', 'org-b'], [$result['reassigned']?->getTaskId(), $result['reassigned']?->subject()?->id, $result['reassigned']?->getScopeKey()]);
        self::assertNull($result['timer']);
        self::assertSame('org-b', $this->customers->canLogCalls[count($this->customers->canLogCalls) - 1]['scope']);
    }

    public function testScopeHelpers(): void
    {
        $service = $this->service(allowWithoutTask: false);
        self::assertSame('org-b', $service->userScope($this->user, 'org-b'));
        self::assertSame('org-a', $service->userScope($this->user, null));
        $this->refused(TimeAllocationException::SUBJECT_FORBIDDEN, fn (): mixed => $service->userScope($this->user, 'org-c'));
        $this->refused(TimeAllocationException::SUBJECT_FORBIDDEN, static fn (): mixed => $service->userScope(new TestUser('nobody', 'nobody@example.com'), null));
        self::assertSame('org-b', $service->scopeKeyOfTaskless($this->user, 'org-b', $this->customer('c-1')));
        try {
            $service->scopeKeyOfTaskless($this->user, 'org-b', null);
            self::fail('the option is off');
        } catch (TaskNotTrackableException $exception) {
            self::assertSame('User cannot track time without a task here.', $exception->getMessage());
        }

        $task = $service->start($this->user, 'task-3', ClientType::Web);
        self::assertSame('org-b', $service->scopeKeyOfTimer($this->user, $task), 'a task timer: the task\'s scope');
        $service->stop($this->user);
        $subject = $service->start($this->user, TimeAllocation::subject($this->customer('c-1')), ClientType::Web, scopeKey: 'org-b');
        self::assertSame('org-b', $service->scopeKeyOfTimer($this->user, $subject));
        $same = TimeAllocation::none();
        self::assertSame($same, TimerService::allocationOf($same));
        self::assertSame(['task-1', null], [TimerService::allocationOf('task-1')->taskId, TimerService::allocationOf(null)->taskId]);
    }

    /**
     * The service under test.
     */
    private function service(bool $requireAllocation = false, bool $requireActivityType = false, bool $allowWithoutTask = true, bool $activities = true): TimerService
    {
        $guard = AllocationFixtures::guard([$this->customers, $this->internal], $activities ? $this->activities : null, $requireAllocation, $requireActivityType, $allowWithoutTask, $this->logger);

        return new TimerService(new StubTaskProvider(), $this->timers, $this->entries, $this->dispatcher, $this->clock, $this->logger, scopeProvider: $this->scopes(), billableDefaults: $this->billable, allowWithoutTask: $allowWithoutTask, allocationGuard: $guard);
    }

    /**
     * The stored entries of the test user, in write order.
     *
     * @return list<TimeEntry>
     */
    private function stored(): array
    {
        return $this->entries->findByUserAndPeriod('user-1', new DateTimeImmutable('2000-01-01'), new DateTimeImmutable('2100-01-01'));
    }

    /**
     * The context of the last log record with the message.
     *
     * @return array<mixed>
     */
    private function contextOf(string $message): array
    {
        $context = null;
        foreach ($this->logger->records as $record) {
            if ($record['message'] === $message) {
                $context = $record['context'];
            }
        }

        return $context ?? self::fail('no log record "' . $message . '"');
    }

    /**
     * The scopes of the test user.
     */
    private function scopes(): FixedScopeProvider
    {
        return new FixedScopeProvider(['user-1' => ['org-a', 'org-b']]);
    }

    /**
     * A customer subject.
     */
    private function customer(string $id): TimeSubjectRef
    {
        return new TimeSubjectRef('customer', $id);
    }

    /**
     * An internal subject.
     */
    private function internal(string $id): TimeSubjectRef
    {
        return new TimeSubjectRef('internal', $id);
    }

    /**
     * Asserts the call is refused with the code.
     *
     * @param callable(): mixed $call
     */
    private function refused(string $code, callable $call): void
    {
        try {
            $call();
            self::fail('expected ' . $code);
        } catch (TimeAllocationException $exception) {
            self::assertSame($code, $exception->errorCode);
        }
    }

    /**
     * The last event of the class.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return ($required is true ? T : T|null)
     */
    private function event(string $class, bool $required = true): ?object
    {
        $events = $this->of($class);
        if ($events === [] && $required) {
            self::fail('no ' . $class);
        }

        return $events === [] ? null : $events[count($events) - 1];
    }

    /**
     * The events of the class, in order.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    private function of(string $class): array
    {
        /** @var list<T> $events */
        $events = array_values(array_filter(array_column($this->events, 1), static fn (object $event): bool => $event instanceof $class));

        return $events;
    }

    /**
     * The events dispatched under the name.
     *
     * @return list<object>
     */
    private function names(string $name): array
    {
        return array_values(array_column(array_filter($this->events, static fn (array $event): bool => $event[0] === $name), 1));
    }
}
