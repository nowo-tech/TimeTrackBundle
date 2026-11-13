<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefFactory;
use Nowo\TimeTrackBundle\Repository\OriginFilteredTimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryOriginFilter;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Service\ManualEntryService;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Stub\HostFlushListener;
use Nowo\TimeTrackBundle\Tests\Stub\StubServiceAccountPolicy;
use Nowo\TimeTrackBundle\Timer\HostTimerApi;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use Nowo\TimeTrackBundle\ValueObject\Uuid;
use PHPUnit\Framework\Attributes\Group;

use function array_map;

/**
 * Since 1.25.0 (Beacon spec 234 FR-023 / FR-024): {@see HostTimerApi} on the real Doctrine stores (SQLite here, MySQL and
 * PostgreSQL in CI): the channel and agent columns, the origin filter, and a start that loses the unique index to a
 * concurrent insert and wins on the retry.
 */
final class HostTimerApiDatabaseTest extends FunctionalTestCase
{
    private AppUser $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = $this->createUser('agent-executor@example.com');
    }

    public function testStartReplaceAndStopStoreTheChannelAndTheMark(): void
    {
        $api   = $this->timerApi();
        $first = $api->start($this->ref($this->agent), 'task-1', 'C-12', 'mcp');
        self::assertNull($first->stopped);
        $second = $api->start($this->ref($this->agent), 'task-2', null, 'api');
        self::assertSame(['task-1', 'mcp', true], [$second->stopped?->taskId, $second->stopped?->channel, $second->stopped?->serviceAccount]);
        $personStart = $api->start($this->ref($this->user), 'task-1', null, 'api');
        self::assertNull($personStart->stopped);
        self::assertSame('task-2', $api->current($this->ref($this->agent))?->taskId);
        $stopped = $api->stop($this->ref($this->agent));
        self::assertSame(['task-2', 'api', true], [$stopped?->taskId, $stopped?->channel, $stopped?->serviceAccount]);
        self::assertSame('task-1', $api->current($this->ref($this->user))?->taskId, 'the person\'s timer still runs');

        $this->entityManager()->clear();
        $rows = $this->entityManager()->getRepository(TimeEntry::class)->findBy([], ['taskId' => 'ASC']);
        self::assertSame([['task-1', 'mcp', true, ['note' => 'C-12', 'closed_by' => 'replaced']], ['task-2', 'api', true, null]], array_map(static fn (TimeEntry $entry): array => [$entry->getTaskId(), $entry->channel(), $entry->isServiceAccount(), $entry->getMetadata()], $rows));
    }

    public function testTheOriginFilterOfTheDoctrineStore(): void
    {
        $manual = self::getContainer()->get(ManualEntryService::class);
        self::assertInstanceOf(ManualEntryService::class, $manual);
        $day = new DateTimeImmutable('-1 day 08:00');
        $manual->create($this->agent, 'task-1', $day, $day->modify('+1 hour'), TimeEntrySource::Manual, channel: 'mcp');
        $manual->create($this->agent, 'task-2', $day->modify('+30 minutes'), $day->modify('+90 minutes'), TimeEntrySource::Manual, channel: 'api');
        $manual->create($this->user, 'task-1', $day, $day->modify('+1 hour'), TimeEntrySource::Manual, channel: 'api');
        $manual->create($this->user, 'task-2', $day->modify('+2 hours'), $day->modify('+3 hours'), TimeEntrySource::Manual);

        $store = self::getContainer()->get(TimeEntryRepositoryInterface::class);
        self::assertInstanceOf(OriginFilteredTimeEntryRepositoryInterface::class, $store);
        $ids   = [(string) $this->agent->getId(), (string) $this->user->getId()];
        $from  = $day->modify('-1 hour');
        $to    = $day->modify('+5 hours');
        $cases = [
            [new TimeEntryOriginFilter(), 4],
            [TimeEntryOriginFilter::serviceAccounts(), 2],
            [TimeEntryOriginFilter::people(), 2],
            [new TimeEntryOriginFilter('api'), 2],
            [new TimeEntryOriginFilter('mcp', true), 1],
            [new TimeEntryOriginFilter('mcp', false), 0],
            [new TimeEntryOriginFilter(withoutChannel: true), 1],
        ];
        foreach ($cases as $i => [$filter, $count]) {
            self::assertSame($count, $store->countByUsersPeriodAndOrigin($ids, $from, $to, $filter), 'count ' . $i);
            self::assertCount($count, $store->findPageByUsersPeriodAndOrigin($ids, $from, $to, $filter, 0, 50), 'page ' . $i);
        }
        $page = $store->findPageByUsersPeriodAndOrigin($ids, $from, $to, TimeEntryOriginFilter::serviceAccounts(), 0, 1);
        self::assertSame(['task-2'], array_map(static fn (TimeEntry $entry): string => $entry->getTaskId(), $page), 'newest first, cut after the filter');
        self::assertSame([], $store->findPageByUsersPeriodAndOrigin([], $from, $to, new TimeEntryOriginFilter(), 0, 10));
        self::assertSame(0, $store->countByUsersPeriodAndOrigin([], $from, $to, new TimeEntryOriginFilter()));
    }

    #[Group('shared-entity-manager')]
    public function testAStartThatLosesTheUniqueIndexIsRetriedOnARecoveredManager(): void
    {
        $api = $this->timerApi();
        $api->start($this->ref($this->agent), 'task-1', null, 'mcp');

        // A concurrent start (client API, no account lock) inserts its row while this start flushes its own.
        $agentId                   = $this->agent->getId();
        $this->host()->onFlushOnce = static function (OnFlushEventArgs $args) use ($agentId): void {
            $args->getObjectManager()->getConnection()->insert('time_track_active_timers', [
                'id'                  => Uuid::generate()->toString(),
                'user_id'             => $agentId,
                'task_id'             => 'task-3',
                'task_title_snapshot' => 'Racer',
                'client_type'         => 'desktop',
                'started_at'          => '2026-01-01 10:00:00',
                'last_heartbeat_at'   => '2026-01-01 10:00:00',
            ]);
        };
        $start = $api->start($this->ref($this->agent), 'task-2', null, 'mcp');

        self::assertSame('task-1', $start->stoppedEntries[0]->taskId);
        self::assertSame('task-2', $api->current($this->ref($this->agent))?->taskId, 'the last call wins');
        self::assertSame(1, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_active_timers'));
        self::assertTrue($this->entityManager()->isOpen());
        self::assertNotNull($api->stop($this->ref($this->agent)));
    }

    private function timerApi(): HostTimerApi
    {
        $api = self::getContainer()->get(HostTimerApi::class);
        self::assertInstanceOf(HostTimerApi::class, $api);
        $policy = self::getContainer()->get(StubServiceAccountPolicy::class);
        self::assertInstanceOf(StubServiceAccountPolicy::class, $policy);

        return $api;
    }

    private function ref(AppUser $user): TimeTrackUserRef
    {
        return TimeTrackUserRefFactory::build($user);
    }

    private function host(): HostFlushListener
    {
        $host = self::getContainer()->get(HostFlushListener::class);
        self::assertInstanceOf(HostFlushListener::class, $host);

        return $host;
    }
}
