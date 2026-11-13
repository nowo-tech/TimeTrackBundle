<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Timer;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Event\TimerStartEvent;
use Nowo\TimeTrackBundle\Event\TimerStopEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Exception\ActiveTimerConflictException;
use Nowo\TimeTrackBundle\Exception\TaskNotTrackableException;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefFactory;
use Nowo\TimeTrackBundle\Repository\ActiveTimerRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\UserSerialisedTimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Tests\Stub\StubServiceAccountPolicy;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\ConcurrentStartActiveTimerRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryActiveTimerRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimeEntryRepository;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use Nowo\TimeTrackBundle\Timer\HostTimerApi;
use Nowo\TimeTrackBundle\Timer\HostTimerStart;
use Nowo\TimeTrackBundle\Timer\TimerEntrySnapshot;
use Nowo\TimeTrackBundle\Timer\TimerSnapshot;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function array_map;

/**
 * Since 1.25.0 (Beacon spec 234 FR-023 / FR-024, SC-005): the host timer API for token principals.
 */
final class HostTimerApiTest extends TestCase
{
    private TestUser $person;

    private TestUser $agent;

    private TestUser $otherAgent;

    private InMemoryTimeEntryRepository $entries;

    private MockClock $clock;

    private RecordingLogger $logger;

    private StubServiceAccountPolicy $policy;

    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->person     = new TestUser('1', 'person@example.com');
        $this->agent      = new TestUser('2', 'agent-executor@example.com');
        $this->otherAgent = new TestUser('3', 'agent-verifier@example.com');
        $this->entries    = new InMemoryTimeEntryRepository();
        $this->clock      = new MockClock('2026-11-13 10:00:00');
        $this->logger     = new RecordingLogger();
        $this->policy     = new StubServiceAccountPolicy();
        $this->dispatcher = new EventDispatcher();
    }

    public function testStartStopAndCurrentForAnAgent(): void
    {
        $api = $this->api();
        self::assertNull($api->current($this->ref($this->agent)));
        self::assertNull($api->stop($this->ref($this->agent)), 'nothing runs');

        $start = $api->start($this->ref($this->agent), 'task-1', 'Executing C-12', 'mcp');
        self::assertNull($start->stopped);
        self::assertFalse($start->replacedARunningTimer());
        self::assertSame(['task-1', 'Implement login API', 'board-1', ClientType::Web, 'mcp', 'Executing C-12'], [$start->timer->taskId, $start->timer->taskTitle, $start->timer->boardId, $start->timer->clientType, $start->timer->channel, $start->timer->note]);

        $current = $api->current($this->ref($this->agent));
        self::assertInstanceOf(TimerSnapshot::class, $current);
        self::assertSame([$start->timer->timerId, 'mcp'], [$current->timerId, $current->channel]);

        $this->clock->sleep(1_500);
        $entry = $api->stop($this->ref($this->agent), 'done');
        self::assertNotNull($entry);
        self::assertSame(['task-1', 1_500, TimeEntrySource::Web, 'mcp', true], [$entry->taskId, $entry->durationSeconds, $entry->source, $entry->channel, $entry->serviceAccount]);
        self::assertSame('2026-11-13 10:00:00', $entry->startedAt->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-13 10:25:00', $entry->endedAt->format('Y-m-d H:i:s'));
        self::assertNull($api->current($this->ref($this->agent)));

        $stored = $this->entries->findByUserAndPeriod('2', new DateTimeImmutable('2026-11-13'), new DateTimeImmutable('2026-11-14'));
        self::assertCount(1, $stored);
        self::assertSame(['mcp', true, ['note' => "Executing C-12\ndone"]], [$stored[0]->channel(), $stored[0]->isServiceAccount(), $stored[0]->getMetadata()], 'the channel moves from the timer metadata to the column');
    }

    public function testASecondStartStopsTheFirstAndReportsIt(): void
    {
        $api = $this->api();
        $api->start($this->ref($this->agent), 'task-1', null, 'api');
        $this->clock->sleep(600);

        $second = $api->start($this->ref($this->agent), 'task-2', null, 'mcp');
        self::assertTrue($second->replacedARunningTimer());
        self::assertNotNull($second->stopped);
        self::assertSame(['task-1', 600, 'api'], [$second->stopped->taskId, $second->stopped->durationSeconds, $second->stopped->channel]);
        self::assertSame(['task-2', 'mcp'], [$second->timer->taskId, $second->timer->channel]);
        self::assertSame('task-2', $api->current($this->ref($this->agent))?->taskId);

        // Spec 234 (Beacon review): the replaced timer is logged at info with the entry id and the account id only.
        $replace = $this->records('timer_replace');
        self::assertCount(1, $replace);
        self::assertSame('info', $replace[0]['level']);
        self::assertSame(['bundle' => 'time_track', 'action' => 'timer_replace', 'client' => 'web', 'channel' => 'mcp', 'entry_id' => $second->stopped->entryId, 'account_id' => '2'], $replace[0]['context']);
        self::assertNotContains('agent-executor@example.com', $this->logger->contextValues());
        self::assertNotContains('Implement login API', $this->logger->contextValues());
    }

    public function testAnAgentNeverStopsAPersonsTimer(): void
    {
        $timers = new InMemoryActiveTimerRepository();
        $api    = $this->api($timers);
        $api->start($this->ref($this->person), 'task-1', null, 'api');
        $personTimer = $api->current($this->ref($this->person));
        self::assertNotNull($personTimer);

        $agentStart = $api->start($this->ref($this->agent), 'task-1', null, 'mcp');
        self::assertNull($agentStart->stopped, 'the person\'s timer is not the agent\'s');
        $api->start($this->ref($this->agent), 'task-2', null, 'mcp');
        $api->stop($this->ref($this->agent));
        self::assertSame($personTimer->timerId, $api->current($this->ref($this->person))?->timerId, 'the person\'s timer still runs');

        // Two agents' timers run at once, and both entries are kept (SC-005).
        $api->start($this->ref($this->agent), 'task-1', null, 'mcp');
        $api->start($this->ref($this->otherAgent), 'task-1', null, 'mcp');
        self::assertNotNull($api->current($this->ref($this->agent)));
        self::assertNotNull($api->current($this->ref($this->otherAgent)));
        $this->clock->sleep(60);
        self::assertNotNull($api->stop($this->ref($this->agent)));
        self::assertNotNull($api->stop($this->ref($this->otherAgent)));
        $period = [new DateTimeImmutable('2026-11-13'), new DateTimeImmutable('2026-11-14')];
        self::assertCount(3, $this->entries->findByUserAndPeriod('2', ...$period));
        self::assertCount(1, $this->entries->findByUserAndPeriod('3', ...$period));
        self::assertSame($personTimer->timerId, $api->current($this->ref($this->person))?->timerId);

        // A person's own start through the API replaces only their own timer, and the entry is a person's.
        $person = $api->start($this->ref($this->person), 'task-2', null, 'api');
        self::assertSame(['task-1', false], [$person->stopped?->taskId, $person->stopped?->serviceAccount]);
    }

    public function testARefusedStartLeavesTheRunningTimerUntouched(): void
    {
        $api = $this->api();
        $api->start($this->ref($this->agent), 'task-1', null, 'mcp');

        foreach ([
            static fn (HostTimerApi $api, TimeTrackUserRef $ref): HostTimerStart => $api->start($ref, 'missing-task', null, 'mcp'),
            static fn (HostTimerApi $api, TimeTrackUserRef $ref): HostTimerStart => $api->start($ref, 'task-2', null, 'MCP'),
            static fn (HostTimerApi $api, TimeTrackUserRef $ref): HostTimerStart => $api->start($ref, 'task-2', null, 'mcp '),
            static fn (HostTimerApi $api, TimeTrackUserRef $ref): HostTimerStart => $api->start($ref, 'task-2', null, ''),
            static fn (HostTimerApi $api, TimeTrackUserRef $ref): HostTimerStart => $api->start($ref, 'task-2', null, 'a_channel_too_long'),
        ] as $i => $call) {
            try {
                $call($api, $this->ref($this->agent));
                self::fail('refused #' . $i);
            } catch (TaskNotTrackableException|InvalidArgumentException) {
            }
            self::assertSame('task-1', $api->current($this->ref($this->agent))?->taskId, 'untouched after refusal #' . $i);
        }
    }

    public function testAReferenceWithoutTheSecurityUserIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('HostTimerApi');
        $this->api()->current(TimeTrackUserRef::ofId(TestUser::class, '2'));
    }

    public function testAClientCannotForgeTheChannelThroughMetadata(): void
    {
        $timers  = new InMemoryActiveTimerRepository();
        $service = $this->service($timers);
        $service->start($this->person, 'task-1', ClientType::Desktop, ['channel' => 'mcp', 'note' => 'n']);
        self::assertSame(['note' => 'n'], $service->getActive($this->person)?->getMetadata());
        $entry = $service->stop($this->person);
        self::assertSame([null, false], [$entry?->channel(), $entry?->isServiceAccount()]);
        self::assertNull((new HostTimerApi($service))->current($this->ref($this->person)));
    }

    public function testAStoredChannelThatIsNotALabelIsIgnored(): void
    {
        $timers = new InMemoryActiveTimerRepository();
        $timers->save(new ActiveTimer($this->agent, 'task-1', 'T', null, ClientType::Web, ['channel' => 'Bad Value']));
        $api = $this->api($timers);
        self::assertNull($api->current($this->ref($this->agent))?->channel);
        self::assertNull($api->stop($this->ref($this->agent))?->channel);
    }

    public function testTwoConcurrentStartsTheLastCallWinsAndEveryStoppedTimerIsRecordedOnce(): void
    {
        $timers = new ConcurrentStartActiveTimerRepository();
        $api    = $this->api($timers);
        $api->start($this->ref($this->agent), 'task-1', null, 'api');

        // A concurrent start (client API, no account lock) inserts its timer between our stop and our insert.
        $timers->raceNextSave($this->agent, 'task-3');
        $start = $api->start($this->ref($this->agent), 'task-2', null, 'mcp');

        self::assertSame(['task-1', 'task-3'], array_map(static fn (TimerEntrySnapshot $entry): string => $entry->taskId, $start->stoppedEntries), 'ours, then the racer\'s');
        self::assertSame('task-3', $start->stopped?->taskId);
        self::assertSame('task-2', $api->current($this->ref($this->agent))?->taskId, 'the last call wins');
        $stored = $this->entries->findByUserAndPeriod('2', new DateTimeImmutable('2026-11-13'), new DateTimeImmutable('2026-11-14'));
        self::assertSame(['task-1', 'task-3'], array_map(static fn (TimeEntry $entry): string => $entry->getTaskId(), $stored), 'each stopped timer recorded once');
        self::assertCount(1, $this->records('timer_replace', 'warning'));
        self::assertCount(2, $this->records('timer_replace'));
    }

    public function testConcurrentStartsOnEveryAttemptEndInAConflict(): void
    {
        $timers = new ConcurrentStartActiveTimerRepository();
        $api    = $this->api($timers);
        for ($i = 0; $i < TimerService::REPLACE_ATTEMPTS; ++$i) {
            $timers->raceNextSave($this->agent);
        }

        try {
            $api->start($this->ref($this->agent), 'task-2', null, 'mcp');
            self::fail('conflict expected');
        } catch (ActiveTimerConflictException $e) {
            self::assertNotNull($e->getPrevious());
        }
        self::assertSame(TimerService::REPLACE_ATTEMPTS, $timers->saves);
        self::assertSame('task-3', $api->current($this->ref($this->agent))?->taskId, 'the last racer\'s timer runs');
        self::assertCount(TimerService::REPLACE_ATTEMPTS - 1, $this->entries->findByUserAndPeriod('2', new DateTimeImmutable('2026-11-13'), new DateTimeImmutable('2026-11-14')));
    }

    public function testUnderTheAccountLockALaterStartIsSeenAndStopped(): void
    {
        // A store with the account lock: another start of the account committed while this call waited for the lock.
        $timers  = new InMemoryActiveTimerRepository();
        $entries = new class($this->entries, $timers, $this->agent) implements TimeEntryRepositoryInterface, UserSerialisedTimeEntryRepositoryInterface {
            public int $locked = 0;

            public int $sneakIns = 1;

            public function __construct(private readonly InMemoryTimeEntryRepository $inner, private readonly ActiveTimerRepositoryInterface $timers, private readonly TestUser $agent)
            {
            }

            public function save(TimeEntry $entry): void
            {
                $this->inner->save($entry);
            }

            public function findByUserAndPeriod(string $userId, DateTimeImmutable $from, DateTimeImmutable $to): array
            {
                return $this->inner->findByUserAndPeriod($userId, $from, $to);
            }

            public function inUserTransaction(object $user, callable $operation): mixed
            {
                ++$this->locked;
                if ($this->sneakIns-- > 0) {
                    $this->timers->save(new ActiveTimer($this->agent, 'task-3', 'Sneak', null, ClientType::Web, ['channel' => 'api']));
                }

                return $operation();
            }
        };
        $api   = new HostTimerApi(new TimerService(new StubTaskProvider(), $timers, $entries, $this->dispatcher, $this->clock, $this->logger, serviceAccounts: $this->policy));
        $start = $api->start($this->ref($this->agent), 'task-1', null, 'mcp');
        self::assertSame(['task-3'], array_map(static fn (TimerEntrySnapshot $entry): string => $entry->taskId, $start->stoppedEntries));
        self::assertSame(2, $entries->locked);
        self::assertSame('task-1', $api->current($this->ref($this->agent))?->taskId);

        // A store that keeps answering "taken" ends in a conflict after the attempts.
        $entries->sneakIns = 99;
        $this->expectException(ActiveTimerConflictException::class);
        $api->start($this->ref($this->agent), 'task-2', null, 'mcp');
    }

    public function testEventsFireForTheStopAndTheStart(): void
    {
        $seen = [];
        $this->dispatcher->addListener(TimeTrackEvents::TIMER_STOP, static function (TimerStopEvent $event) use (&$seen): void {
            $seen[] = 'stop:' . $event->getEntry()->getTaskId();
        });
        $this->dispatcher->addListener(TimeTrackEvents::TIMER_START, static function (TimerStartEvent $event) use (&$seen): void {
            $seen[] = 'start:' . $event->getTask()->id;
        });
        $api = $this->api();
        $api->start($this->ref($this->agent), 'task-1', null, 'api');
        $api->start($this->ref($this->agent), 'task-2', null, 'api');
        self::assertSame(['start:task-1', 'stop:task-1', 'start:task-2'], $seen);
    }

    public function testThePolicyIsAskedOnEveryWriteAndNeverMemoised(): void
    {
        $api = $this->api();
        $api->start($this->ref($this->person), 'task-1', null, 'api');
        $first = $api->stop($this->ref($this->person));
        self::assertNotNull($first);
        self::assertFalse($first->serviceAccount);
        $this->policy->agentIds = ['1'];
        $api->start($this->ref($this->person), 'task-1', null, 'api');
        $second = $api->stop($this->ref($this->person));
        self::assertNotNull($second);
        self::assertTrue($second->serviceAccount, 'the next answer applies at once');
        self::assertSame(['1', '1'], $this->policy->asked);
    }

    private function api(?ActiveTimerRepositoryInterface $timers = null): HostTimerApi
    {
        return new HostTimerApi($this->service($timers ?? new InMemoryActiveTimerRepository()));
    }

    private function service(ActiveTimerRepositoryInterface $timers): TimerService
    {
        return new TimerService(new StubTaskProvider(), $timers, $this->entries, $this->dispatcher, $this->clock, $this->logger, serviceAccounts: $this->policy);
    }

    private function ref(TestUser $user): TimeTrackUserRef
    {
        return TimeTrackUserRefFactory::build($user);
    }

    /**
     * @return list<array{level: string, message: string, context: array<mixed>}>
     */
    private function records(string $action, string $level = 'info'): array
    {
        $kept = [];
        foreach ($this->logger->records as $record) {
            if (($record['context']['action'] ?? null) === $action && $record['level'] === $level) {
                $kept[] = $record;
            }
        }

        return $kept;
    }
}
