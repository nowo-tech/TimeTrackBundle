<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Nowo\TimeTrackBundle\Dto\TaskListQuery;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Exception\ActiveTimerConflictException;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryActiveTimerRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimeEntryRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class TimerServiceTest extends TestCase
{
    private TimerService $service;

    private TestUser $user;

    protected function setUp(): void
    {
        $this->user    = new TestUser('user-1', 'demo@example.com');
        $this->service = new TimerService(
            new StubTaskProvider(),
            new InMemoryActiveTimerRepository(),
            new InMemoryTimeEntryRepository(),
            new EventDispatcher(),
        );
    }

    public function testStartAndStopCreatesEntry(): void
    {
        $timer = $this->service->start($this->user, 'task-1', ClientType::Web);
        self::assertSame('task-1', $timer->getTaskId());

        $entry = $this->service->stop($this->user);
        self::assertNotNull($entry);
        self::assertSame('task-1', $entry->getTaskId());
        self::assertNull($this->service->getActive($this->user));
    }

    public function testSecondStartThrowsConflict(): void
    {
        $this->service->start($this->user, 'task-1', ClientType::Extension);

        $this->expectException(ActiveTimerConflictException::class);
        $this->service->start($this->user, 'task-2', ClientType::Extension);
    }

    public function testListTasksUsesStubProvider(): void
    {
        $tasks = $this->service->listTasks($this->user, new TaskListQuery());
        self::assertCount(3, $tasks);
    }

    public function testListEntriesAfterStop(): void
    {
        $this->service->start($this->user, 'task-2', ClientType::Desktop);
        $this->service->stop($this->user);

        $entries = $this->service->listEntries(
            $this->user,
            new DateTimeImmutable('-1 day'),
            new DateTimeImmutable('+1 day'),
        );

        self::assertCount(1, $entries);
    }

    public function testStopWithoutActiveTimerReturnsNull(): void
    {
        self::assertNull($this->service->stop($this->user));
    }

    public function testHeartbeatUpdatesActiveTimer(): void
    {
        $this->service->start($this->user, 'task-1', ClientType::Web);
        $timer = $this->service->heartbeat($this->user, true);

        self::assertNotNull($timer);
        self::assertTrue($timer->getMetadata()['isIdle'] ?? false);
    }

    public function testHeartbeatWithoutActiveTimerReturnsNull(): void
    {
        self::assertNull($this->service->heartbeat($this->user));
    }

    public function testStartInvalidTaskThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->start($this->user, 'missing-task', ClientType::Web);
    }

    public function testStartWhenTaskNotFoundThrows(): void
    {
        $provider = $this->createMock(\Nowo\TimeTrackBundle\Integration\TaskProviderInterface::class);
        $provider->method('canUserTrack')->willReturn(true);
        $provider->method('findForUser')->willReturn(null);

        $service = new TimerService(
            $provider,
            new InMemoryActiveTimerRepository(),
            new InMemoryTimeEntryRepository(),
            new EventDispatcher(),
        );

        $this->expectException(InvalidArgumentException::class);
        $service->start($this->user, 'task-1', ClientType::Web);
    }

    public function testStartWhenUserCannotTrackThrows(): void
    {
        $provider = $this->createMock(\Nowo\TimeTrackBundle\Integration\TaskProviderInterface::class);
        $provider->method('canUserTrack')->willReturn(false);

        $service = new TimerService(
            $provider,
            new InMemoryActiveTimerRepository(),
            new InMemoryTimeEntryRepository(),
            new EventDispatcher(),
        );

        $this->expectException(InvalidArgumentException::class);
        $service->start($this->user, 'task-1', ClientType::Web);
    }

    public function testListEntriesUsesEventUserIds(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(
            'nowo_time_track.time_entry.list_query',
            static function ($event): void {
                $event->setUserIds(['user-1']);
            },
        );

        $service = new TimerService(
            new StubTaskProvider(),
            new InMemoryActiveTimerRepository(),
            new InMemoryTimeEntryRepository(),
            $dispatcher,
        );

        $service->start($this->user, 'task-1', ClientType::Web);
        $service->stop($this->user);

        $entries = $service->listEntries(
            $this->user,
            new DateTimeImmutable('-1 day'),
            new DateTimeImmutable('+1 day'),
        );

        self::assertCount(1, $entries);
    }

    public function testListEntriesWithTargetUserId(): void
    {
        $this->service->start($this->user, 'task-1', ClientType::Web);
        $this->service->stop($this->user);

        $entries = $this->service->listEntries(
            $this->user,
            new DateTimeImmutable('-1 day'),
            new DateTimeImmutable('+1 day'),
            'user-1',
        );

        self::assertCount(1, $entries);
    }

    public function testListEntriesAggregatesMultipleUsers(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(
            'nowo_time_track.time_entry.list_query',
            static function ($event): void {
                $event->setUserIds(['user-1', 'user-2']);
            },
        );

        $service = new TimerService(
            new StubTaskProvider(),
            new InMemoryActiveTimerRepository(),
            new InMemoryTimeEntryRepository(),
            $dispatcher,
        );

        $service->start($this->user, 'task-1', ClientType::Web);
        $service->stop($this->user);

        $entries = $service->listEntries(
            $this->user,
            new DateTimeImmutable('-1 day'),
            new DateTimeImmutable('+1 day'),
        );

        self::assertCount(1, $entries);
    }

    public function testStopMapsAllClientTypes(): void
    {
        foreach ([ClientType::Extension, ClientType::Desktop] as $clientType) {
            $this->service->start($this->user, 'task-1', $clientType);
            $entry = $this->service->stop($this->user);
            self::assertNotNull($entry);
        }
    }
}
