<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\IdleResolution;
use Nowo\TimeTrackBundle\Exception\ActiveTimerConflictException;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimeEntryRepository;
use Nowo\TimeTrackBundle\Tests\Support\RacingActiveTimerRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * CL-05 regression (1.9.1): two requests read the running timer before either deletes it. Only the request whose
 * conditional delete removes the row records the entry.
 */
final class TimerServiceConcurrentStopTest extends TestCase
{
    private RacingActiveTimerRepository $timers;

    private InMemoryTimeEntryRepository $entries;

    private TimerService $service;

    private TestUser $user;

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->user    = new TestUser('user-1', 'demo@example.com');
        $this->timers  = new RacingActiveTimerRepository();
        $this->entries = new InMemoryTimeEntryRepository();
        $this->clock   = new MockClock('2026-11-02 10:00:00');
        $this->service = new TimerService(new StubTaskProvider(), $this->timers, $this->entries, new EventDispatcher(), $this->clock);
    }

    public function testConcurrentStopsRecordTheEntryOnce(): void
    {
        $this->service->start($this->user, 'task-1', ClientType::Extension);
        $this->timers->staleReads(true, true);
        $this->clock->sleep(60);

        self::assertNotNull($this->service->stop($this->user));
        self::assertNull($this->service->stop($this->user));
        self::assertCount(1, $this->entries->findByUserAndPeriod('user-1', new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-11-03')));
    }

    public function testConcurrentSwitchesRecordTheEntryOnce(): void
    {
        $this->service->start($this->user, 'task-1', ClientType::Extension);
        // Reads: stop A (stale), start A (current), stop B (stale), start B (current).
        $this->timers->staleReads(true, false, true, false);
        $this->clock->sleep(60);

        $first = $this->service->switchTo($this->user, 'task-2', ClientType::Extension);
        self::assertNotNull($first['entry']);

        try {
            $this->service->switchTo($this->user, 'task-2', ClientType::Extension);
            self::fail('The losing switch must not start a second timer.');
        } catch (ActiveTimerConflictException) {
        }

        self::assertCount(1, $this->entries->findByUserAndPeriod('user-1', new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-11-03')));
    }

    public function testConcurrentIdleSplitsRecordTheEntryOnce(): void
    {
        $this->service->start($this->user, 'task-1', ClientType::Desktop);
        $this->timers->staleReads(true, true, true, true);
        $this->clock->sleep(600);
        $from = new DateTimeImmutable('2026-11-02 10:02:00');
        $to   = new DateTimeImmutable('2026-11-02 10:05:00');

        self::assertNotNull($this->service->resolveIdle($this->user, $from, $to, IdleResolution::Split));
        self::assertNull($this->service->resolveIdle($this->user, $from, $to, IdleResolution::Split));
        self::assertCount(1, $this->entries->findByUserAndPeriod('user-1', new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-11-03')));
    }
}
