<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Nowo\TimeTrackBundle\Dto\BackdateLimits;
use Nowo\TimeTrackBundle\Dto\ClampedTime;
use Nowo\TimeTrackBundle\Dto\TimeEntryView;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\IdleFollowUp;
use Nowo\TimeTrackBundle\Enum\IdleResolution;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Exception\ManualEntryException;
use Nowo\TimeTrackBundle\Integration\NoServiceAccountPolicy;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefFactory;
use Nowo\TimeTrackBundle\Repository\TimeEntryOriginFilter;
use Nowo\TimeTrackBundle\Security\TaskProviderTaskAccessChecker;
use Nowo\TimeTrackBundle\Service\ManualEntryService;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Support\TimeEntryCsv;
use Nowo\TimeTrackBundle\Tests\Stub\StubServiceAccountPolicy;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryActiveTimerRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimeEntryRepository;
use Nowo\TimeTrackBundle\Timer\TimerChannel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function explode;
use function trim;

/**
 * Since 1.25.0 (Beacon spec 234 FR-023): a service account's entries may overlap (per account and across accounts),
 * people keep every overlap rule, and entries carry the channel and the agent mark.
 */
final class ServiceAccountEntriesTest extends TestCase
{
    private TestUser $person;

    private TestUser $agent;

    private InMemoryTimeEntryRepository $entries;

    private InMemoryActiveTimerRepository $timers;

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->person  = new TestUser('1', 'person@example.com');
        $this->agent   = new TestUser('2', 'agent-executor@example.com');
        $this->entries = new InMemoryTimeEntryRepository();
        $this->timers  = new InMemoryActiveTimerRepository();
        $this->clock   = new MockClock('2026-11-13 12:00:00');
    }

    public function testAServiceAccountsManualEntriesMayOverlapItsEntriesAndItsTimer(): void
    {
        $service = $this->manual(new StubServiceAccountPolicy());
        $first   = $service->create($this->agent, 'task-1', new DateTimeImmutable('2026-11-13 08:00'), new DateTimeImmutable('2026-11-13 09:00'), TimeEntrySource::Manual, channel: 'mcp');
        $overlap = $service->create($this->agent, 'task-2', new DateTimeImmutable('2026-11-13 08:30'), new DateTimeImmutable('2026-11-13 09:30'), TimeEntrySource::Manual, channel: 'api');
        $this->timerService(new StubServiceAccountPolicy())->start($this->agent, 'task-3', ClientType::Web);
        $this->clock->sleep(600);
        $underTimer = $service->create($this->agent, 'task-1', new DateTimeImmutable('2026-11-13 12:00'), new DateTimeImmutable('2026-11-13 12:05'), TimeEntrySource::Manual);

        self::assertSame([['mcp', true], ['api', true], [null, true]], [[$first->channel(), $first->isServiceAccount()], [$overlap->channel(), $overlap->isServiceAccount()], [$underTimer->channel(), $underTimer->isServiceAccount()]]);
    }

    public function testAPersonKeepsEveryOverlapRule(): void
    {
        $service = $this->manual(new StubServiceAccountPolicy());
        $entry   = $service->create($this->person, 'task-1', new DateTimeImmutable('2026-11-13 08:00'), new DateTimeImmutable('2026-11-13 09:00'), TimeEntrySource::Manual, channel: 'api');
        self::assertSame(['api', false], [$entry->channel(), $entry->isServiceAccount()]);
        $this->expectOverlap(fn (): TimeEntry => $service->create($this->person, 'task-2', new DateTimeImmutable('2026-11-13 08:30'), new DateTimeImmutable('2026-11-13 09:30'), TimeEntrySource::Manual));

        // An agent's entry over the same span does not block the person, nor the other way round (across accounts).
        $service->create($this->agent, 'task-2', new DateTimeImmutable('2026-11-13 08:30'), new DateTimeImmutable('2026-11-13 09:30'), TimeEntrySource::Manual);
        $service->create($this->person, 'task-2', new DateTimeImmutable('2026-11-13 09:00'), new DateTimeImmutable('2026-11-13 09:30'), TimeEntrySource::Manual);

        $this->timerService(new StubServiceAccountPolicy())->start($this->person, 'task-3', ClientType::Web);
        $this->clock->sleep(600);
        $this->expectOverlap(fn (): TimeEntry => $service->create($this->person, 'task-1', new DateTimeImmutable('2026-11-13 12:00'), new DateTimeImmutable('2026-11-13 12:05'), TimeEntrySource::Manual));
    }

    public function testWithoutAPolicyOrWithTheDefaultEveryoneIsAPerson(): void
    {
        foreach ([null, new NoServiceAccountPolicy()] as $policy) {
            $this->entries = new InMemoryTimeEntryRepository();
            $service       = $this->manual($policy);
            $service->create($this->agent, 'task-1', new DateTimeImmutable('2026-11-13 08:00'), new DateTimeImmutable('2026-11-13 09:00'), TimeEntrySource::Manual);
            $this->expectOverlap(fn (): TimeEntry => $service->create($this->agent, 'task-2', new DateTimeImmutable('2026-11-13 08:30'), new DateTimeImmutable('2026-11-13 09:30'), TimeEntrySource::Manual));
        }
        self::assertFalse((new NoServiceAccountPolicy())->isServiceAccount(TimeTrackUserRefFactory::build($this->agent)));
    }

    public function testAManualEntryCannotForgeTheClosedByMark(): void
    {
        $entry = $this->manual(null)->create($this->person, 'task-1', new DateTimeImmutable('2026-11-13 08:00'), new DateTimeImmutable('2026-11-13 09:00'), TimeEntrySource::Manual, null, ['closed_by' => 'replaced', 'origin' => 'mcp']);
        self::assertSame([null, ['origin' => 'mcp']], [$entry->closedBy(), $entry->getMetadata()]);
    }

    public function testAnInvalidChannelIsRefusedBeforeAnyWrite(): void
    {
        $service = $this->manual(new StubServiceAccountPolicy());
        $this->expectException(InvalidArgumentException::class);
        try {
            $service->create($this->agent, 'task-1', new DateTimeImmutable('2026-11-13 08:00'), new DateTimeImmutable('2026-11-13 09:00'), TimeEntrySource::Manual, channel: 'Api');
        } finally {
            self::assertSame([], $this->entries->findByUserAndPeriod('2', new DateTimeImmutable('2026-11-13'), new DateTimeImmutable('2026-11-14')));
        }
    }

    public function testABackdatedStartOfAServiceAccountIsNotClampedToItsLatestEntry(): void
    {
        $policy = new StubServiceAccountPolicy();
        $this->entries->save(new TimeEntry($this->agent, 'task-1', 'T', null, new DateTimeImmutable('2026-11-13 11:00'), new DateTimeImmutable('2026-11-13 11:50'), 3000, TimeEntrySource::Web));
        $this->entries->save(new TimeEntry($this->person, 'task-1', 'T', null, new DateTimeImmutable('2026-11-13 11:00'), new DateTimeImmutable('2026-11-13 11:50'), 3000, TimeEntrySource::Web));
        $service = $this->timerService($policy);

        $agent = $service->startAt($this->agent, 'task-2', ClientType::Web, [], new DateTimeImmutable('2026-11-13 11:30'), new BackdateLimits(7200));
        self::assertFalse($agent['startTime']?->clamped);
        self::assertSame('2026-11-13 11:30', $agent['timer']->getStartedAt()->format('Y-m-d H:i'));

        $person = $service->startAt($this->person, 'task-2', ClientType::Web, [], new DateTimeImmutable('2026-11-13 11:30'), new BackdateLimits(7200));
        self::assertSame(ClampedTime::REASON_OVERLAP, $person['startTime']?->reason);
        self::assertSame('2026-11-13 11:50', $person['timer']->getStartedAt()->format('Y-m-d H:i'));
    }

    public function testAReassignedIdleSpanCarriesTheChannelAndTheMark(): void
    {
        $service = $this->timerService(new StubServiceAccountPolicy());
        $service->startReplacing($this->agent, 'task-1', ClientType::Web, 'mcp');
        $this->clock->sleep(3600);
        $result = $service->resolveIdle($this->agent, new DateTimeImmutable('2026-11-13 12:20'), new DateTimeImmutable('2026-11-13 12:40'), IdleResolution::Reassign, 'task-2', IdleFollowUp::Stop);
        self::assertNotNull($result);
        self::assertSame(['mcp', true], [$result['entry']?->channel(), $result['entry']?->isServiceAccount()]);
        self::assertSame(['mcp', true], [$result['reassigned']?->channel(), $result['reassigned']?->isServiceAccount()]);
    }

    public function testEntryAccessorsViewArrayAndCsv(): void
    {
        $entry = new TimeEntry($this->agent, 'task-1', 'T', null, new DateTimeImmutable('2026-11-13 08:00'), new DateTimeImmutable('2026-11-13 09:00'), 3600, TimeEntrySource::Web);
        self::assertSame([null, false], [$entry->channel(), $entry->isServiceAccount()]);
        self::assertSame(['channel' => null, 'serviceAccount' => false], ['channel' => $entry->toArray()['channel'], 'serviceAccount' => $entry->toArray()['serviceAccount']]);
        self::assertSame($entry, $entry->markOrigin('mcp', true));
        self::assertSame(['mcp', true], [$entry->toArray()['channel'], $entry->toArray()['serviceAccount']]);
        $view = TimeEntryView::fromEntry($entry);
        self::assertSame(['mcp', true], [$view->channel, $view->serviceAccount]);

        $csv = explode("\n", trim(TimeEntryCsv::render([$entry])));
        self::assertStringEndsWith(',channel,service_account', $csv[0]);
        self::assertStringEndsWith(',mcp,yes', $csv[1]);
        $entry->markOrigin(null, false);
        self::assertStringEndsWith(',,no', explode("\n", trim(TimeEntryCsv::render([$entry])))[1]);

        $this->expectException(InvalidArgumentException::class);
        $entry->markOrigin("mcp\u{200B}", true);
    }

    public function testTheOriginFilter(): void
    {
        $agentMcp  = (new TimeEntry($this->agent, 'task-1', 'T', null, new DateTimeImmutable(), new DateTimeImmutable(), 0, TimeEntrySource::Web))->markOrigin('mcp', true);
        $personApi = (new TimeEntry($this->person, 'task-1', 'T', null, new DateTimeImmutable(), new DateTimeImmutable(), 0, TimeEntrySource::Web))->markOrigin('api', false);
        $personApp = new TimeEntry($this->person, 'task-1', 'T', null, new DateTimeImmutable(), new DateTimeImmutable(), 0, TimeEntrySource::Desktop);

        $cases = [
            [new TimeEntryOriginFilter(), [true, true, true]],
            [TimeEntryOriginFilter::serviceAccounts(), [true, false, false]],
            [TimeEntryOriginFilter::people(), [false, true, true]],
            [new TimeEntryOriginFilter('mcp'), [true, false, false]],
            [new TimeEntryOriginFilter('api', false), [false, true, false]],
            [new TimeEntryOriginFilter(withoutChannel: true), [false, false, true]],
            [new TimeEntryOriginFilter(serviceAccount: true, withoutChannel: true), [false, false, false]],
        ];
        foreach ($cases as $i => [$filter, $expected]) {
            self::assertSame($expected, [$filter->matches($agentMcp), $filter->matches($personApi), $filter->matches($personApp)], 'case ' . $i);
        }

        foreach (['MCP', 'mcp ', ' mcp', "mcp\0", 'mсp', ''] as $lookAlike) {
            try {
                new TimeEntryOriginFilter($lookAlike);
                self::fail('look-alike accepted: ' . $lookAlike);
            } catch (InvalidArgumentException) {
            }
        }
        self::assertSame('a_b_1', TimerChannel::assertValid('a_b_1'));

        $this->expectException(InvalidArgumentException::class);
        new TimeEntryOriginFilter('mcp', withoutChannel: true);
    }

    private function timerService(?StubServiceAccountPolicy $policy): TimerService
    {
        return new TimerService(new StubTaskProvider(), $this->timers, $this->entries, new EventDispatcher(), $this->clock, serviceAccounts: $policy);
    }

    private function manual(StubServiceAccountPolicy|NoServiceAccountPolicy|null $policy): ManualEntryService
    {
        $tasks = new StubTaskProvider();

        return new ManualEntryService(new TimerService($tasks, $this->timers, $this->entries, new EventDispatcher(), $this->clock), $this->entries, $this->timers, new TaskProviderTaskAccessChecker($tasks), clock: $this->clock, serviceAccounts: $policy);
    }

    /**
     * @param callable(): TimeEntry $call
     */
    private function expectOverlap(callable $call): void
    {
        try {
            $call();
            self::fail('overlap expected');
        } catch (ManualEntryException $e) {
            self::assertSame(ManualEntryException::OVERLAP, $e->errorCode);
        }
    }
}
