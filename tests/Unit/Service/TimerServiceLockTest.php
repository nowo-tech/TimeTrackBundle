<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Nowo\TimeTrackBundle\Dto\BackdateLimits;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\IdleFollowUp;
use Nowo\TimeTrackBundle\Enum\IdleResolution;
use Nowo\TimeTrackBundle\Event\TimesheetLateEntryEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Exception\PeriodLockedException;
use Nowo\TimeTrackBundle\Exception\PeriodSubmittedException;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryActiveTimerRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimeEntryRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimesheetRepository;
use Nowo\TimeTrackBundle\Tests\Support\TimesheetFixtures;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScopeProviderInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.13: every timer write path asks the period lock, and entries carry the tenant scope of their task.
 */
final class TimerServiceLockTest extends TestCase
{
    private TestUser $user;

    private MockClock $clock;

    private InMemoryTimesheetRepository $timesheets;

    private InMemoryTimeEntryRepository $entries;

    private InMemoryActiveTimerRepository $timers;

    /** @var list<TimesheetLateEntryEvent> */
    private array $late = [];

    private TimerService $service;

    protected function setUp(): void
    {
        $this->user       = new TestUser('7', 'ana@example.com');
        $this->clock      = new MockClock('2026-11-11 10:00:00');
        $this->timesheets = new InMemoryTimesheetRepository();
        $this->entries    = new InMemoryTimeEntryRepository();
        $this->timers     = new InMemoryActiveTimerRepository();
        $dispatcher       = new EventDispatcher();
        $dispatcher->addListener(TimeTrackEvents::TIMESHEET_LATE_ENTRY, function (TimesheetLateEntryEvent $event): void {
            $this->late[] = $event;
        });
        $scopes = new class implements TimesheetScopeProviderInterface {
            public function scopesFor(UserInterface $user): array
            {
                return [];
            }

            public function scopeKeyForTask(UserInterface $user, string $taskId, ?string $boardId): string
            {
                return $boardId === 'board-2' ? 'org-b' : 'org-a';
            }

            public function scopeKeysForEntries(array $entries): array
            {
                return [];
            }
        };
        $this->service = new TimerService(new StubTaskProvider(), $this->timers, $this->entries, $dispatcher, $this->clock, null, new PeriodLockGuard($this->timesheets, true, $dispatcher), $scopes);
    }

    public function testABackdatedStartIntoALockedWeekIsRefused(): void
    {
        $this->timesheets->save(TimesheetFixtures::locked($this->user, 'org-a', '2026-W45'));
        $this->clock->modify('2026-11-09 08:00:00');

        try {
            $this->service->startAt($this->user, 'task-1', ClientType::Desktop, [], new DateTimeImmutable('2026-11-08 12:00:00'), new BackdateLimits(86400));
            self::fail('locked');
        } catch (PeriodLockedException $e) {
            self::assertSame('2026-11-08', $e->getLockedThrough());
        }
        self::assertNull($this->service->getActive($this->user));

        // The same backdate on a task of another scope is writable.
        $this->service->startAt($this->user, 'task-3', ClientType::Desktop, [], new DateTimeImmutable('2026-11-08 12:00:00'), new BackdateLimits(86400));
        self::assertNotNull($this->service->getActive($this->user));
    }

    public function testAStartNowInASubmittedWeekIsRefusedAndSwitchKeepsTheTimer(): void
    {
        $this->timesheets->save(TimesheetFixtures::submitted($this->user, 'org-b', '2026-W46'));
        $this->service->start($this->user, 'task-1', ClientType::Web);

        try {
            $this->service->switchTo($this->user, 'task-3', ClientType::Web);
            self::fail('submitted');
        } catch (PeriodSubmittedException) {
        }
        self::assertSame('task-1', $this->service->getActive($this->user)?->getTaskId(), 'the running timer is untouched');

        $this->expectException(PeriodSubmittedException::class);
        $this->service->stop($this->user);
        $this->service->start($this->user, 'task-3', ClientType::Web);
    }

    public function testEntriesCarryTheScopeAndALateEntryIsReported(): void
    {
        $this->service->start($this->user, 'task-1', ClientType::Web);
        $locked = TimesheetFixtures::locked($this->user, 'org-a', '2026-W46');
        $this->timesheets->save($locked);
        $this->clock->modify('+1 hour');

        $entry = $this->service->stop($this->user);

        self::assertInstanceOf(TimeEntry::class, $entry);
        self::assertSame('org-a', $entry->getScopeKey());
        self::assertCount(1, $this->late);
        self::assertSame($locked->getId(), $this->late[0]->timesheetId);
        self::assertSame($entry->getId(), $this->late[0]->entryId);
    }

    public function testIdleDecisionsInALockedPeriodAreRefusedButKeepIsAllowed(): void
    {
        $this->service->start($this->user, 'task-1', ClientType::Web);
        $this->clock->modify('+1 hour');
        $from = new DateTimeImmutable('2026-11-11 10:20:00');
        $to   = new DateTimeImmutable('2026-11-11 10:40:00');

        $kept = $this->service->resolveIdle($this->user, $from, $to, IdleResolution::Keep);
        self::assertNotNull($kept);

        $this->timesheets->save(TimesheetFixtures::locked($this->user, 'org-b', '2026-W46'));
        try {
            $this->service->resolveIdle($this->user, $from, $to, IdleResolution::Reassign, 'task-3', IdleFollowUp::Continue);
            self::fail('target scope locked');
        } catch (PeriodLockedException) {
        }

        $this->timesheets->save(TimesheetFixtures::locked($this->user, 'org-a', '2026-W46'));
        $this->expectException(PeriodLockedException::class);
        $this->service->resolveIdle($this->user, $from, $to, IdleResolution::Discard);
    }

    public function testReassignedEntriesCarryTheTargetScope(): void
    {
        $this->service->start($this->user, 'task-1', ClientType::Web);
        $this->clock->modify('+1 hour');

        $result = $this->service->resolveIdle($this->user, new DateTimeImmutable('2026-11-11 10:20:00'), new DateTimeImmutable('2026-11-11 10:40:00'), IdleResolution::Reassign, 'task-3', IdleFollowUp::Stop);

        self::assertNotNull($result);
        self::assertSame('org-a', $result['entry']?->getScopeKey());
        self::assertSame('org-b', $result['reassigned']?->getScopeKey());
        self::assertSame([], $this->late);
    }
}
