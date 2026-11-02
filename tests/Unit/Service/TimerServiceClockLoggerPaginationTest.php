<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryActiveTimerRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryPaginatedTimeEntryRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimeEntryRepository;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function sprintf;

use const PHP_INT_MAX;

/**
 * REQ-DI-001 (injected clock), REQ-OBS-001 (logs without personal data) and REQ-PERF-001 (paged reports list).
 */
final class TimerServiceClockLoggerPaginationTest extends TestCase
{
    private TestUser $user;

    protected function setUp(): void
    {
        $this->user = new TestUser('user-1', 'demo@example.com');
    }

    public function testStartStopAndHeartbeatUseTheInjectedClock(): void
    {
        $clock   = new MockClock('2026-05-04 09:00:00');
        $service = $this->service(new InMemoryTimeEntryRepository(), $clock);

        $timer = $service->start($this->user, 'task-1', ClientType::Web);
        self::assertSame('2026-05-04 09:00:00', $timer->getStartedAt()->format('Y-m-d H:i:s'));

        $clock->sleep(300);
        $service->heartbeat($this->user);
        self::assertSame('2026-05-04 09:05:00', $timer->getLastHeartbeatAt()->format('Y-m-d H:i:s'));

        $clock->sleep(1500);
        $entry = $service->stop($this->user);
        self::assertInstanceOf(TimeEntry::class, $entry);
        self::assertSame(1800, $entry->getDurationSeconds());
        self::assertSame('2026-05-04 09:30:00', $entry->getEndedAt()->format('Y-m-d H:i:s'));
        self::assertSame('2026-05-04 09:30:00', $entry->getCreatedAt()->format('Y-m-d H:i:s'));
    }

    public function testTimerLogsCarryNoPersonalData(): void
    {
        $logger  = new RecordingLogger();
        $service = $this->service(new InMemoryTimeEntryRepository(), new MockClock(), $logger);

        $service->start($this->user, 'task-1', ClientType::Desktop, ['window' => 'secret title']);
        $service->stop($this->user);

        self::assertSame(['TimeTrack timer started.', 'TimeTrack timer stopped.'], $logger->messages());
        self::assertSame(
            ['bundle' => 'time_track', 'action' => 'timer_stop', 'client' => 'desktop'],
            $logger->records[1]['context'],
        );
        foreach ($logger->contextValues() as $value) {
            self::assertNotContains($value, ['user-1', 'demo@example.com', 'task-1', 'Implement login API', 'board-1', 'secret title']);
        }
    }

    public function testInMemoryRepositoryIsSlicedPerPage(): void
    {
        $repository = new InMemoryTimeEntryRepository();
        $service    = $this->service($repository, new MockClock());
        $this->seed($repository, 5);

        $page = $service->listEntriesPage($this->user, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-12-31'), 2, 2);

        self::assertSame(2, $page->page);
        self::assertSame(2, $page->perPage);
        self::assertSame(5, $page->total);
        self::assertSame(3, $page->totalPages());
        self::assertTrue($page->hasPrevious());
        self::assertTrue($page->hasNext());
        self::assertSame(['task-3', 'task-2'], array_map(static fn (TimeEntry $e): string => $e->getTaskId(), $page->entries));
    }

    /**
     * @return iterable<string, array{0: int, 1: int}>
     */
    public static function outOfRangePages(): iterable
    {
        yield 'zero' => [0, 1];
        yield 'negative' => [-4, 1];
        yield 'past the last page' => [99, 3];
        yield 'huge' => [PHP_INT_MAX, 3];
    }

    #[DataProvider('outOfRangePages')]
    public function testOutOfRangePagesAreClamped(int $requested, int $expected): void
    {
        $repository = new InMemoryTimeEntryRepository();
        $service    = $this->service($repository, new MockClock());
        $this->seed($repository, 5);

        $page = $service->listEntriesPage($this->user, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-12-31'), $requested, 2);

        self::assertSame($expected, $page->page);
    }

    public function testPaginatedRepositoryPagesInTheStore(): void
    {
        $repository = new InMemoryPaginatedTimeEntryRepository();
        $service    = $this->service($repository, new MockClock());
        $this->seed($repository, 5);

        $page = $service->listEntriesPage($this->user, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-12-31'), 3, 2, 'user-1');

        self::assertSame([['userIds' => ['user-1'], 'offset' => 4, 'limit' => 2]], $repository->pageQueries);
        self::assertSame(1, $repository->countQueries);
        self::assertSame(3, $page->page);
        self::assertFalse($page->hasNext());
        self::assertSame(['task-1'], array_map(static fn (TimeEntry $e): string => $e->getTaskId(), $page->entries));
    }

    public function testPaginatedRepositorySkipsThePageQueryWhenEmpty(): void
    {
        $repository = new InMemoryPaginatedTimeEntryRepository();
        $service    = $this->service($repository, new MockClock());

        $page = $service->listEntriesPage($this->user, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-12-31'), 1, 0);

        self::assertSame([], $page->entries);
        self::assertSame([], $repository->pageQueries);
        self::assertSame(1, $page->perPage);
        self::assertSame(1, $page->totalPages());
        self::assertFalse($page->hasPrevious());
        self::assertFalse($page->hasNext());
    }

    public function testPaginatedRepositoryUsesTheListQueryEventUserIds(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener('nowo_time_track.time_entry.list_query', static function ($event): void {
            $event->setUserIds(['user-1', 'user-2']);
        });
        $repository = new InMemoryPaginatedTimeEntryRepository();
        $service    = new TimerService(new StubTaskProvider(), new InMemoryActiveTimerRepository(), $repository, $dispatcher);
        $this->seed($repository, 1);

        $service->listEntriesPage($this->user, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-12-31'), 1, 10);

        self::assertSame(['user-1', 'user-2'], $repository->pageQueries[0]['userIds'] ?? null);
    }

    private function service(TimeEntryRepositoryInterface $repository, MockClock $clock, ?RecordingLogger $logger = null): TimerService
    {
        return new TimerService(new StubTaskProvider(), new InMemoryActiveTimerRepository(), $repository, new EventDispatcher(), $clock, $logger);
    }

    private function seed(TimeEntryRepositoryInterface $repository, int $count): void
    {
        for ($i = 1; $i <= $count; ++$i) {
            $start = new DateTimeImmutable(sprintf('2026-05-%02d 09:00:00', $i));
            $repository->save(new TimeEntry($this->user, 'task-' . $i, 'Task ' . $i, null, $start, $start->modify('+1 hour'), 3600, TimeEntrySource::Web));
        }
    }
}
