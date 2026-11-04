<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Nowo\TimeTrackBundle\Dto\BackdateLimits;
use Nowo\TimeTrackBundle\Dto\ClampedTime;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\IdleFollowUp;
use Nowo\TimeTrackBundle\Enum\IdleResolution;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Event\IdleResolvedEvent;
use Nowo\TimeTrackBundle\Event\TimerStartEvent;
use Nowo\TimeTrackBundle\Event\TimerStopEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Exception\TaskNotTrackableException;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryActiveTimerRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimeEntryRepository;
use Nowo\TimeTrackBundle\Tests\Support\RacingActiveTimerRepository;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * 1.10 service layer: backdated start / stop, the fallback "latest entry" lookup, idle reassign events and logs
 * without personal data (REQ-OBS-001).
 */
final class TimerServiceBackdateIdleTest extends TestCase
{
    private TestUser $user;

    private MockClock $clock;

    private RecordingLogger $logger;

    private EventDispatcher $dispatcher;

    /** @var list<object> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->user       = new TestUser('user-1', 'demo@example.com');
        $this->clock      = new MockClock('2026-11-02 10:00:00');
        $this->logger     = new RecordingLogger();
        $this->dispatcher = new EventDispatcher();
        foreach ([TimeTrackEvents::TIMER_START, TimeTrackEvents::TIMER_STOP, TimeTrackEvents::IDLE_RESOLVED] as $name) {
            $this->dispatcher->addListener($name, function (object $event): void {
                $this->events[] = $event;
            });
        }
    }

    public function testFallbackLookupFindsAnEntryThatStartedBeforeTheWindow(): void
    {
        $entries = new InMemoryTimeEntryRepository();
        $entries->save(new TimeEntry($this->user, 'task-1', 'T', null, new DateTimeImmutable('2026-11-01 09:30:00'), new DateTimeImmutable('2026-11-02 08:00:00'), 0, TimeEntrySource::Web));
        $service = $this->service($entries);

        $result = $service->startAt($this->user, 'task-1', ClientType::Desktop, [], new DateTimeImmutable('2026-11-02 07:00:00'), new BackdateLimits(7200));

        self::assertInstanceOf(ClampedTime::class, $result['startTime']);
        self::assertSame(ClampedTime::REASON_OVERLAP, $result['startTime']->reason);
        self::assertSame('2026-11-02 08:00:00', $result['timer']->getStartedAt()->format('Y-m-d H:i:s'));
        self::assertSame('task-1', $result['task']->id);
    }

    public function testFutureLastEntryClampsTheStartToNow(): void
    {
        $entries = new InMemoryTimeEntryRepository();
        $entries->save(new TimeEntry($this->user, 'task-1', 'T', null, new DateTimeImmutable('2026-11-02 09:00:00'), new DateTimeImmutable('2026-11-02 11:00:00'), 0, TimeEntrySource::Web));

        $result = $this->service($entries)->startAt($this->user, 'task-1', ClientType::Desktop, [], new DateTimeImmutable('2026-11-02 09:30:00'));

        $startTime = $result['startTime'];
        self::assertInstanceOf(ClampedTime::class, $startTime);
        self::assertSame('2026-11-02 10:00:00', $startTime->applied->format('Y-m-d H:i:s'));
        self::assertSame(ClampedTime::REASON_OVERLAP, $startTime->reason);
    }

    public function testStartAndStopEventsCarryTheClampAndLogsCarryNoData(): void
    {
        $service = $this->service(new InMemoryTimeEntryRepository());

        $service->startAt($this->user, 'task-1', ClientType::Desktop, [], new DateTimeImmutable('2026-11-02 12:00:00'));
        $service->stopAt($this->user, new DateTimeImmutable('2026-11-02 08:00:00'), null, 'note');

        $start = $this->events[0];
        self::assertInstanceOf(TimerStartEvent::class, $start);
        self::assertSame(ClampedTime::REASON_FUTURE, $start->getStartTime()?->reason);
        $stop = $this->events[1];
        self::assertInstanceOf(TimerStopEvent::class, $stop);
        self::assertSame(ClampedTime::REASON_BEFORE_START, $stop->getEndTime()?->reason);

        self::assertSame(['timer_start', 'timer_start_clamped', 'timer_stop', 'timer_stop_clamped'], array_column(array_column($this->logger->records, 'context'), 'action'));
        self::assertTrue($this->logger->records[0]['context']['backdated']);
        self::assertSame('future', $this->logger->records[1]['context']['reason']);
        foreach ($this->logger->contextValues() as $value) {
            self::assertNotContains($value, ['user-1', 'demo@example.com', 'task-1', 'Implement login API', 'note', '2026-11-02T12:00:00+00:00']);
        }
    }

    public function testPlainStartAndStopKeepThe19Behaviour(): void
    {
        $service = $this->service(new InMemoryTimeEntryRepository());

        $started = $service->startAt($this->user, 'task-1', ClientType::Web);
        self::assertNull($started['startTime']);
        $stopped = $service->stopAt($this->user);
        self::assertNotNull($stopped);
        self::assertNull($stopped['endTime']);
        self::assertNull($service->stopAt($this->user));
        self::assertFalse($this->logger->records[0]['context']['backdated']);

        $start = $this->events[0];
        self::assertInstanceOf(TimerStartEvent::class, $start);
        self::assertNull($start->getStartTime());
    }

    public function testReassignValidationInTheService(): void
    {
        $service = $this->service(new InMemoryTimeEntryRepository());
        $from    = new DateTimeImmutable('2026-11-02 09:30:00');
        $to      = new DateTimeImmutable('2026-11-02 09:40:00');

        try {
            $service->resolveIdle($this->user, $from, $to, IdleResolution::Reassign);
            self::fail('taskId is required');
        } catch (InvalidArgumentException $e) {
            self::assertNotInstanceOf(TaskNotTrackableException::class, $e);
        }

        $this->expectException(TaskNotTrackableException::class);
        $service->resolveIdle($this->user, $from, $to, IdleResolution::Reassign, 'unknown');
    }

    public function testKeepDiscardAndSplitDispatchTheIdleEvent(): void
    {
        $service = $this->service(new InMemoryTimeEntryRepository());
        $service->startAt($this->user, 'task-1', ClientType::Desktop, [], new DateTimeImmutable('2026-11-02 09:00:00'));

        $service->resolveIdle($this->user, new DateTimeImmutable('2026-11-02 09:10:00'), new DateTimeImmutable('2026-11-02 09:20:00'), IdleResolution::Keep);
        $service->resolveIdle($this->user, new DateTimeImmutable('2026-11-02 09:10:00'), new DateTimeImmutable('2026-11-02 09:20:00'), IdleResolution::Split);
        $service->resolveIdle($this->user, new DateTimeImmutable('2026-11-02 09:30:00'), new DateTimeImmutable('2026-11-02 09:40:00'), IdleResolution::Discard, null, IdleFollowUp::Stop, ClientType::Extension);

        $idle = array_values(array_filter($this->events, static fn (object $e): bool => $e instanceof IdleResolvedEvent));
        self::assertSame([IdleResolution::Keep, IdleResolution::Split, IdleResolution::Discard], array_map(static fn (IdleResolvedEvent $e): IdleResolution => $e->getResolution(), $idle));
        self::assertSame(ClientType::Desktop, $idle[0]->getClientType());
        self::assertSame(ClientType::Extension, $idle[2]->getClientType());
        self::assertNull($idle[0]->getTargetTaskId());
        self::assertNull($idle[0]->getFollowUp());
        self::assertSame($this->user, $idle[0]->getUser());
        self::assertSame('2026-11-02 09:10:00', $idle[0]->getFrom()->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-02 09:20:00', $idle[0]->getTo()->format('Y-m-d H:i:s'));
    }

    public function testReassignLogsCarryNoData(): void
    {
        $service = $this->service(new InMemoryTimeEntryRepository());
        $service->startAt($this->user, 'task-1', ClientType::Desktop, [], new DateTimeImmutable('2026-11-02 09:00:00'));
        $this->logger->records = [];

        $result = $service->resolveIdle($this->user, new DateTimeImmutable('2026-11-02 09:10:00'), new DateTimeImmutable('2026-11-02 09:20:00'), IdleResolution::Reassign, 'task-2', IdleFollowUp::Switch, null, 'secret note');

        self::assertNotNull($result);
        self::assertSame('task-2', $result['timer']?->getTaskId());
        $records = $this->records();
        self::assertSame(['timer_stop', 'timer_idle_reassign'], array_column(array_column($records, 'context'), 'action'));
        self::assertSame('switch', $records[1]['context']['then'] ?? null);
        foreach ($this->logger->contextValues() as $value) {
            self::assertNotContains($value, ['user-1', 'task-1', 'task-2', 'secret note', 'Write unit tests']);
        }
    }

    public function testConcurrentStopAtRecordsNothing(): void
    {
        $timers  = new RacingActiveTimerRepository();
        $service = new TimerService(new StubTaskProvider(), $timers, new InMemoryTimeEntryRepository(), $this->dispatcher, $this->clock, $this->logger);
        $timer   = $service->start($this->user, 'task-1', ClientType::Desktop);
        $timers->staleReads(true);
        $timers->remove($timer);

        self::assertNull($service->stopAt($this->user, new DateTimeImmutable('2026-11-02 09:59:00')));
    }

    public function testFindTaskPassesThroughTheProvider(): void
    {
        $service = $this->service(new InMemoryTimeEntryRepository());

        self::assertSame('Demo project', $service->findTask($this->user, 'task-1')?->projectTitle);
        self::assertNull($service->findTask($this->user, 'nope'));
    }

    /**
     * @return list<array{level: string, message: string, context: array<mixed>}>
     */
    private function records(): array
    {
        return $this->logger->records;
    }

    private function service(TimeEntryRepositoryInterface $entries): TimerService
    {
        return new TimerService(new StubTaskProvider(), new InMemoryActiveTimerRepository(), $entries, $this->dispatcher, $this->clock, $this->logger);
    }
}
