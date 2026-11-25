<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Timer;

use Closure;
use DateTimeImmutable;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Event\TimerAllocatedEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Exception\TaskNotTrackableException;
use Nowo\TimeTrackBundle\Exception\TimeAllocationException;
use Nowo\TimeTrackBundle\Exception\TimeAllocationRequiredException;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefFactory;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Subject\TimeAllocation;
use Nowo\TimeTrackBundle\Subject\TimeAllocationPatch;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;
use Nowo\TimeTrackBundle\Tests\Stub\RecordingTaskAccessChecker;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\AllocationFixtures;
use Nowo\TimeTrackBundle\Tests\Support\CustomerTestSubjectProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedActivityTypeProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryActiveTimerRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimeEntryRepository;
use Nowo\TimeTrackBundle\Tests\Support\InternalTestSubjectProvider;
use Nowo\TimeTrackBundle\Tests\Support\RevocableTaskProvider;
use Nowo\TimeTrackBundle\Timer\HostTimerApi;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function array_map;

/**
 * Since 2.1.0 (Beacon spec 256, audit ZBQ): {@see HostTimerApi::allocate()} (sets, changes and clears the subject and
 * activity type of the actor's running timer with the refusals of `timer/allocate`) and the task re-check of
 * {@see HostTimerApi::stop()} / `allocate()` when the subject of a task timer changes (ZBQ-L3), plus the clearing rule
 * of a stop patch (ZBQ-L1).
 */
final class HostTimerApiAllocationTest extends TestCase
{
    private TestUser $user;

    private TimeTrackUserRef $actor;

    private CustomerTestSubjectProvider $customers;

    private InternalTestSubjectProvider $internal;

    private RecordingTaskAccessChecker $access;

    private RevocableTaskProvider $tasks;

    private InMemoryTimeEntryRepository $entries;

    /** @var list<TimerAllocatedEvent> */
    private array $allocated = [];

    protected function setUp(): void
    {
        $this->user                = new TestUser('user-1', 'person@example.com');
        $this->actor               = TimeTrackUserRefFactory::build($this->user);
        $this->customers           = new CustomerTestSubjectProvider();
        $this->customers->loggable = ['c-1', 'c-2'];
        $this->internal            = new InternalTestSubjectProvider();
        $this->internal->loggable  = ['i-1'];
        $this->access              = new RecordingTaskAccessChecker();
        $this->tasks               = new RevocableTaskProvider();
        $this->entries             = new InMemoryTimeEntryRepository();
    }

    public function testAllocateSetsChangesAndClearsTheRunningTimer(): void
    {
        [$api, $timers] = $this->api();
        self::assertNull($api->allocate($this->actor, TimeAllocationPatch::keep()->withSubject($this->customer('c-1'))), 'no timer');

        $timers->start($this->user, 'task-1', ClientType::Web);
        $set = $api->allocate($this->actor, TimeAllocationPatch::keep()->withSubject($this->customer('c-1'))->withActivityType('dev'));
        self::assertSame(['task-1', 'c-1', 'dev'], [$set?->taskId, $set?->subject?->id, $set?->activityTypeKey]);
        self::assertSame([['actor' => 'user-1', 'scope' => 'org-a', 'id' => 'c-1']], $this->customers->canLogCalls, 'canLog for the actor in the timer\'s scope');

        foreach ([
            [TimeAllocationPatch::keep()->withSubject($this->internal('i-1')), TimeAllocationException::ALLOCATION_CONFLICT],
            [TimeAllocationPatch::keep()->withSubject($this->customer('c-9')), TimeAllocationException::SUBJECT_FORBIDDEN],
            [TimeAllocationPatch::keep()->withActivityType('nope'), TimeAllocationException::ACTIVITY_TYPE_UNKNOWN],
            [TimeAllocationPatch::keep()->withActivityType('legacy'), TimeAllocationException::ACTIVITY_TYPE_INACTIVE],
        ] as [$patch, $code]) {
            $this->refused($code, fn (): mixed => $api->allocate($this->actor, $patch));
        }

        $cleared = $api->allocate($this->actor, TimeAllocationPatch::keep()->withoutSubject()->withoutActivityType());
        self::assertSame(['task-1', null, null], [$cleared?->taskId, $cleared?->subject, $cleared?->activityTypeKey]);
        self::assertSame([[null, 'c-1'], ['c-1', null]], array_map(static fn (TimerAllocatedEvent $event): array => [$event->previousSubject?->id, $event->subject?->id], $this->allocated));
        self::assertSame(['task-1', 'task-1', 'task-1', 'task-1'], $this->access->checked, 'asked on every subject change of the task timer, never for an activity type alone');
    }

    public function testAllocateOnATaskLessTimerFollowsTheClearingRule(): void
    {
        [$api, $timers] = $this->api(allowWithoutTask: false);
        $timers->start($this->user, TimeAllocation::subject($this->customer('c-1')), ClientType::Web);

        $this->refused(TimeAllocationException::ALLOCATION_REQUIRED, fn (): mixed => $api->allocate($this->actor, TimeAllocationPatch::keep()->withoutSubject()));
        self::assertSame('c-2', $api->allocate($this->actor, TimeAllocationPatch::keep()->withSubject($this->customer('c-2')))?->subject?->id);
        self::assertSame([], $this->access->checked, 'no task: no task check');
    }

    public function testChangingTheSubjectOfATaskTimerAsksTheAccessPolicyAgain(): void
    {
        [$api, $timers] = $this->api();
        $timers->start($this->user, TimeAllocation::task('task-1', $this->customer('c-1')), ClientType::Web);
        $this->access->denied = ['task-1'];
        $this->tasks->asked   = [];

        foreach ([
            fn (): mixed => $api->allocate($this->actor, TimeAllocationPatch::keep()->withSubject($this->customer('c-2'))),
            fn (): mixed => $api->allocate($this->actor, TimeAllocationPatch::keep()->withoutSubject()),
            fn (): mixed => $api->stop($this->actor, 'done', TimeAllocationPatch::keep()->withSubject($this->customer('c-2'))),
            fn (): mixed => $api->stop($this->actor, null, TimeAllocationPatch::keep()->withoutSubject()),
        ] as $call) {
            $this->notTrackable($call);
        }
        self::assertSame(['task-1', 'task-1', 'task-1', 'task-1'], $this->access->checked);
        self::assertSame([], $this->tasks->asked, 'refused before the task provider');
        self::assertSame('c-1', $api->current($this->actor)?->subject?->id);
        self::assertSame([], $this->allocated);

        // No subject change: not asked (same subject, an activity type alone, no patch).
        self::assertSame('dev', $api->allocate($this->actor, TimeAllocationPatch::keep()->withSubject($this->customer('c-1'))->withActivityType('dev'))?->activityTypeKey);
        self::assertSame('support', $api->stop($this->actor, null, TimeAllocationPatch::keep()->withActivityType('support'))?->activityTypeKey);
        self::assertCount(4, $this->access->checked);

        // The policy allows it but the task provider no longer does: the timer service refuses it too.
        $this->access->denied = [];
        $timers->start($this->user, TimeAllocation::task('task-1', $this->customer('c-1')), ClientType::Web);
        $this->tasks->revoked = ['task-1'];
        $this->tasks->asked   = [];
        $this->notTrackable(fn (): mixed => $api->allocate($this->actor, TimeAllocationPatch::keep()->withSubject($this->customer('c-2'))));
        $this->notTrackable(fn (): mixed => $api->stop($this->actor, null, TimeAllocationPatch::keep()->withSubject($this->customer('c-2'))));
        self::assertSame(['task-1', 'task-1'], $this->tasks->asked);
        self::assertSame('c-1', $api->current($this->actor)?->subject?->id);

        // Allowed again: the change goes through.
        $this->tasks->revoked = [];
        self::assertSame('c-2', $api->stop($this->actor, null, TimeAllocationPatch::keep()->withSubject($this->customer('c-2')))?->subject?->id);
        self::assertNull($api->stop($this->actor, null, TimeAllocationPatch::keep()->withSubject($this->customer('c-2'))), 'no timer: nothing to check');
    }

    public function testAStopPatchClearingTheSubjectOfTimeWithoutATaskIsRefused(): void
    {
        [$api, $timers] = $this->api(allowWithoutTask: false);
        $timers->start($this->user, TimeAllocation::subject($this->customer('c-1')), ClientType::Web);

        try {
            $api->stop($this->actor, null, TimeAllocationPatch::keep()->withoutSubject());
            self::fail('expected allocation_required');
        } catch (TimeAllocationRequiredException $exception) {
            self::assertSame([TimeAllocationException::ALLOCATION_REQUIRED, 'c-1'], [$exception->errorCode, $exception->timer->subject?->id]);
        }
        self::assertSame('c-1', $api->current($this->actor)?->subject?->id, 'the timer keeps running');
        self::assertSame([], $this->entries->findByUserAndPeriod('user-1', new DateTimeImmutable('2000-01-01'), new DateTimeImmutable('2100-01-01')));

        // allow_without_task on and require_allocation off: the entry is saved unallocated.
        [$relaxed, $relaxedTimers] = $this->api(allowWithoutTask: true);
        $relaxedTimers->start($this->user, TimeAllocation::subject($this->customer('c-1')), ClientType::Web);
        $entry = $relaxed->stop($this->actor, null, TimeAllocationPatch::keep()->withoutSubject());
        self::assertSame([null, true], [$entry?->subject, $entry?->withoutTask]);
    }

    /**
     * The API under test and its timer service (fresh stores).
     *
     * @return array{0: HostTimerApi, 1: TimerService}
     */
    private function api(bool $allowWithoutTask = true): array
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(TimeTrackEvents::TIMER_ALLOCATED, function (TimerAllocatedEvent $event): void {
            $this->allocated[] = $event;
        });
        $guard  = AllocationFixtures::guard([$this->customers, $this->internal], new FixedActivityTypeProvider(), allowWithoutTask: $allowWithoutTask);
        $timers = new TimerService($this->tasks, new InMemoryActiveTimerRepository(), $this->entries, $dispatcher, new MockClock('2026-11-24 10:00:00'), scopeProvider: new FixedScopeProvider(['user-1' => ['org-a', 'org-b']]), allowWithoutTask: $allowWithoutTask, allocationGuard: $guard);

        return [new HostTimerApi($timers, $this->access), $timers];
    }

    /**
     * Asserts the call is refused with the allocation code.
     *
     * @param Closure(): mixed $call
     */
    private function refused(string $code, Closure $call): void
    {
        try {
            $call();
            self::fail('expected ' . $code);
        } catch (TimeAllocationException $exception) {
            self::assertSame($code, $exception->errorCode);
        }
    }

    /**
     * Asserts the call is refused as a task the actor may not track.
     *
     * @param Closure(): mixed $call
     */
    private function notTrackable(Closure $call): void
    {
        try {
            $call();
            self::fail('expected TaskNotTrackableException');
        } catch (TaskNotTrackableException $exception) {
            self::assertStringContainsString('task-1', $exception->getMessage());
        }
    }

    private function customer(string $id): TimeSubjectRef
    {
        return new TimeSubjectRef('customer', $id);
    }

    private function internal(string $id): TimeSubjectRef
    {
        return new TimeSubjectRef('internal', $id);
    }
}
