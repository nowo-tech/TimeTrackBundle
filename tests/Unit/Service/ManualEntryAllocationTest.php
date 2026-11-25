<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Event\TimeEntryCreatedEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Exception\ManualEntryException;
use Nowo\TimeTrackBundle\Exception\TimeAllocationException;
use Nowo\TimeTrackBundle\Security\TaskProviderTaskAccessChecker;
use Nowo\TimeTrackBundle\Service\ManualEntryService;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Subject\TimeAllocation;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;
use Nowo\TimeTrackBundle\Tests\Stub\StubBillableDefaultProvider;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\AllocationFixtures;
use Nowo\TimeTrackBundle\Tests\Support\CustomerTestSubjectProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedActivityTypeProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryActiveTimerRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimeEntryRepository;
use Nowo\TimeTrackBundle\Tests\Support\InternalTestSubjectProvider;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function json_encode;

/**
 * Since 2.1.0 (Beacon spec 256, design §7.1–§7.3): manual entries with a subject and an activity type — checked for the
 * owner in the entry's scope before anything is written, interactive (the requirements refuse them), and the billable
 * precedence explicit > activity type > host provider (Q-TS-9).
 */
final class ManualEntryAllocationTest extends TestCase
{
    private TestUser $user;

    private InMemoryTimeEntryRepository $entries;

    private CustomerTestSubjectProvider $customers;

    private InternalTestSubjectProvider $internal;

    private StubBillableDefaultProvider $billable;

    private RecordingLogger $logger;

    /** @var list<TimeEntryCreatedEvent> */
    private array $created = [];

    protected function setUp(): void
    {
        $this->user                = new TestUser('user-1', 'person@example.com');
        $this->entries             = new InMemoryTimeEntryRepository();
        $this->customers           = new CustomerTestSubjectProvider();
        $this->customers->loggable = ['c-1'];
        $this->internal            = new InternalTestSubjectProvider();
        $this->internal->loggable  = ['i-1'];
        $this->billable            = new StubBillableDefaultProvider(true);
        $this->logger              = new RecordingLogger();
    }

    public function testATaskAndASubjectAreCheckedInTheTasksScopeAndStored(): void
    {
        $service = $this->service();
        $entry   = $service->create($this->user, TimeAllocation::task('task-3', $this->customer('c-1'), 'support'), $this->at(8), $this->at(9), TimeEntrySource::Manual);

        self::assertSame(['task-3', 'org-b', 'c-1', 'support', false], [$entry->getTaskId(), $entry->getScopeKey(), $entry->subject()?->id, $entry->getActivityTypeKey(), $entry->getBillable()]);
        self::assertSame([['actor' => 'user-1', 'scope' => 'org-b', 'id' => 'c-1']], $this->customers->canLogCalls);
        self::assertSame([], $this->billable->calls, 'the activity type default first');
        self::assertSame(['c-1', 'support'], [$this->created[0]->getSubject()?->id, $this->created[0]->getActivityTypeKey()]);
        self::assertContains('customer', $this->logger->contextValues());
        self::assertStringNotContainsString('c-1', (string) json_encode($this->logger->records));

        // Explicit wins over the activity type; an activity type without a default falls back to the host's.
        self::assertTrue($service->create($this->user, TimeAllocation::task('task-1', null, 'support'), $this->at(9), $this->at(10), TimeEntrySource::Manual, billable: true)->getBillable());
        self::assertTrue($service->create($this->user, TimeAllocation::task('task-1', null, 'meeting'), $this->at(10), $this->at(11), TimeEntrySource::Manual)->getBillable());
        self::assertSame([['org-a', 'task-1']], $this->billable->calls);
        self::assertSame($service->allocationGuard(), $service->allocationGuard());
    }

    public function testRefusalsWriteNothing(): void
    {
        $service = $this->service();
        foreach ([
            [TimeAllocationException::ALLOCATION_CONFLICT, TimeAllocation::task('task-1', $this->internal('i-1'))],
            [TimeAllocationException::SUBJECT_FORBIDDEN, TimeAllocation::task('task-1', $this->customer('c-9'))],
            [TimeAllocationException::SUBJECT_FORBIDDEN, TimeAllocation::subject(new TimeSubjectRef('vendor', 'c-1'))],
            [TimeAllocationException::ACTIVITY_TYPE_UNKNOWN, TimeAllocation::task('task-1', null, 'nope')],
            [TimeAllocationException::ACTIVITY_TYPE_INACTIVE, TimeAllocation::subject($this->internal('i-1'), 'legacy')],
        ] as [$code, $allocation]) {
            $this->refused($code, fn (): TimeEntry => $service->create($this->user, $allocation, $this->at(8), $this->at(9), TimeEntrySource::Manual));
        }
        // A subject entry in a scope that is not the user's.
        $this->refused(TimeAllocationException::SUBJECT_FORBIDDEN, fn (): TimeEntry => $service->create($this->user, TimeAllocation::subject($this->internal('i-1')), $this->at(8), $this->at(9), TimeEntrySource::Manual, scopeKey: 'org-c'));
        // An unknown task stays the task answer, before the subject is asked.
        $asked = $this->customers->canLogCalls;
        try {
            $service->create($this->user, TimeAllocation::task('task-404', $this->customer('c-1')), $this->at(8), $this->at(9), TimeEntrySource::Manual);
            self::fail('expected not_trackable');
        } catch (ManualEntryException $exception) {
            self::assertSame(ManualEntryException::NOT_TRACKABLE, $exception->errorCode);
        }
        self::assertSame($asked, $this->customers->canLogCalls, 'no canLog for an unknown task');
        self::assertSame([], $this->stored());
        self::assertSame([], $this->created);
    }

    public function testASubjectEntryNeedsNoTaskOptionButNothingStillNeedsIt(): void
    {
        $service = $this->service(allowWithoutTask: false);
        $entry   = $service->create($this->user, TimeAllocation::subject($this->internal('i-1'), 'dev'), $this->at(8), $this->at(9), TimeEntrySource::Manual, description: 'Retro', scopeKey: 'org-b');
        self::assertSame([null, 'org-b', 'i-1', 'dev', true, 'Retro'], [$entry->taskIdOrNull(), $entry->getScopeKey(), $entry->subject()?->id, $entry->getActivityTypeKey(), $entry->getBillable(), $entry->getDescription()]);

        try {
            $service->create($this->user, TimeAllocation::none(), $this->at(9), $this->at(10), TimeEntrySource::Manual);
            self::fail('expected not_trackable');
        } catch (ManualEntryException $exception) {
            self::assertSame(ManualEntryException::NOT_TRACKABLE, $exception->errorCode);
        }
    }

    public function testAManualEntryIsInteractive(): void
    {
        $service = $this->service(requireAllocation: true, requireActivityType: true);
        $this->refused(TimeAllocationException::ALLOCATION_REQUIRED, fn (): TimeEntry => $service->create($this->user, TimeAllocation::none('dev'), $this->at(8), $this->at(9), TimeEntrySource::Manual));
        $this->refused(TimeAllocationException::ACTIVITY_TYPE_REQUIRED, fn (): TimeEntry => $service->create($this->user, 'task-1', $this->at(8), $this->at(9), TimeEntrySource::Manual));
        self::assertSame([], $this->stored());

        self::assertSame('dev', $service->create($this->user, TimeAllocation::task('task-1', null, 'dev'), $this->at(8), $this->at(9), TimeEntrySource::Manual)->getActivityTypeKey());
    }

    /**
     * The service under test.
     */
    private function service(bool $requireAllocation = false, bool $requireActivityType = false, bool $allowWithoutTask = true): ManualEntryService
    {
        $clock      = new MockClock('2026-11-24 18:00:00');
        $timers     = new InMemoryActiveTimerRepository();
        $tasks      = new StubTaskProvider();
        $scopes     = new FixedScopeProvider(['user-1' => ['org-a', 'org-b']]);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(TimeTrackEvents::TIME_ENTRY_CREATED, function (TimeEntryCreatedEvent $event): void {
            $this->created[] = $event;
        });
        $guard = AllocationFixtures::guard([$this->customers, $this->internal], new FixedActivityTypeProvider(), $requireAllocation, $requireActivityType, $allowWithoutTask);
        $timer = new TimerService($tasks, $timers, $this->entries, $dispatcher, $clock, $this->logger, scopeProvider: $scopes, allowWithoutTask: $allowWithoutTask, allocationGuard: $guard);

        return new ManualEntryService($timer, $this->entries, $timers, new TaskProviderTaskAccessChecker($tasks), $dispatcher, true, scopeProvider: $scopes, clock: $clock, logger: $this->logger, billableDefaults: $this->billable);
    }

    /**
     * A time today.
     */
    private function at(int $hour): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-24 ' . $hour . ':00:00');
    }

    /**
     * The stored entries.
     *
     * @return list<TimeEntry>
     */
    private function stored(): array
    {
        return $this->entries->findByUserAndPeriod('user-1', new DateTimeImmutable('2000-01-01'), new DateTimeImmutable('2100-01-01'));
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
}
