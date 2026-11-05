<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Timesheet;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Enum\WriteKind;
use Nowo\TimeTrackBundle\Event\PeriodLockRefusedEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Exception\PeriodLockedException;
use Nowo\TimeTrackBundle\Exception\PeriodSubmittedException;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimesheetRepository;
use Nowo\TimeTrackBundle\Tests\Support\TimesheetFixtures;
use Nowo\TimeTrackBundle\Timesheet\BlockedEditRecorderInterface;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;
use Nowo\TimeTrackBundle\Timesheet\SingleScopeProvider;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScope;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function str_repeat;

final class PeriodLockGuardTest extends TestCase
{
    private TestUser $user;

    private InMemoryTimesheetRepository $timesheets;

    private EventDispatcher $dispatcher;

    /** @var list<PeriodLockRefusedEvent> */
    private array $refusals = [];

    protected function setUp(): void
    {
        $this->user       = new TestUser('7', 'ana@example.com');
        $this->timesheets = new InMemoryTimesheetRepository();
        $this->dispatcher = new EventDispatcher();
        $this->dispatcher->addListener(TimeTrackEvents::PERIOD_LOCK_REFUSED, function (PeriodLockRefusedEvent $event): void {
            $this->refusals[] = $event;
        });
    }

    public function testALockedPeriodRefusesEveryWriteOfThatScopeAndDate(): void
    {
        $locked = TimesheetFixtures::locked($this->user, 'org-a', '2026-W45', 'Europe/Madrid');
        $this->timesheets->save($locked);
        $guard = new PeriodLockGuard($this->timesheets, true, $this->dispatcher);

        try {
            $guard->assertWritable($this->user, 'org-a', new DateTimeImmutable('2026-11-04 09:00:00Z'), WriteKind::TimerStart);
            self::fail('locked');
        } catch (PeriodLockedException $e) {
            self::assertSame('period_locked', $e->errorCode());
            self::assertSame('2026-11-08', $e->getLockedThrough());
            self::assertSame($locked->getId(), $e->getTimesheetId());
            self::assertSame(WriteKind::TimerStart, $e->getWriteKind());
            self::assertSame('The period is locked.', $e->getMessage());
        }

        self::assertCount(1, $this->refusals);
        self::assertSame('7', $this->refusals[0]->userRef);
        self::assertSame('org-a', $this->refusals[0]->scopeKey);
        self::assertSame('2026-11-04', $this->refusals[0]->localDate);
        self::assertSame(WriteKind::TimerStart, $this->refusals[0]->writeKind);
        self::assertSame($locked->getId(), $this->refusals[0]->timesheetId);
        self::assertSame('period_locked', $this->refusals[0]->errorCode);

        // Another scope, another week, the zone edge: writable.
        $guard->assertWritable($this->user, 'org-b', new DateTimeImmutable('2026-11-04 09:00:00Z'), WriteKind::TimerStart);
        $guard->assertWritable($this->user, 'org-a', new DateTimeImmutable('2026-11-08 23:30:00Z'), WriteKind::TimerStart);
        self::assertNull($guard->lockingTimesheet($this->user, 'org-a', new DateTimeImmutable('2026-11-09 12:00:00Z')));
        self::assertSame($locked, $guard->lockingTimesheet($this->user, 'org-a', new DateTimeImmutable('2026-11-08 22:30:00Z')));
        self::assertCount(1, $this->refusals);
    }

    public function testANullScopeIsCheckedAgainstEveryScope(): void
    {
        $this->timesheets->save(TimesheetFixtures::locked($this->user, 'org-b', '2026-W45'));
        $guard = new PeriodLockGuard($this->timesheets);

        $this->expectException(PeriodLockedException::class);
        $guard->assertWritable($this->user, null, new DateTimeImmutable('2026-11-04 09:00:00Z'), WriteKind::Import);
    }

    public function testASubmittedPeriodSoftLocksAndALockWins(): void
    {
        $submitted = TimesheetFixtures::submitted($this->user, 'org-a', '2026-W45');
        $this->timesheets->save($submitted);
        $recorder = new class implements BlockedEditRecorderInterface {
            /** @var list<array{string, string, WriteKind}> */
            public array $calls = [];

            public function record(Timesheet $timesheet, string $userRef, WriteKind $kind): void
            {
                $this->calls[] = [$timesheet->getId(), $userRef, $kind];
            }
        };
        $guard = new PeriodLockGuard($this->timesheets, true, null, $recorder);
        $at    = new DateTimeImmutable('2026-11-05 09:00:00Z');

        $refusal = $guard->refusal($this->user, null, $at, WriteKind::Edit);
        self::assertInstanceOf(PeriodSubmittedException::class, $refusal);
        self::assertSame('period_submitted', $refusal->errorCode());
        self::assertSame('2026-11-08', $refusal->getPeriodEnd());
        self::assertSame([[$submitted->getId(), '7', WriteKind::Edit]], $recorder->calls);
        self::assertSame($submitted, $guard->blockingTimesheet($this->user, 'org-a', $at));
        self::assertNull($guard->lockingTimesheet($this->user, 'org-a', $at));

        $locked = TimesheetFixtures::locked($this->user, 'org-b', '2026-W45');
        $this->timesheets->save($locked);
        self::assertSame($locked, $guard->blockingTimesheet($this->user, null, $at), 'a hard lock is reported before a submitted period');
    }

    public function testEntries(): void
    {
        $this->timesheets->save(TimesheetFixtures::locked($this->user, 'org-a', '2026-W45'));
        $guard  = new PeriodLockGuard($this->timesheets);
        $locked = (new TimeEntry($this->user, 'task-1', 'T', null, new DateTimeImmutable('2026-11-03 09:00:00Z'), new DateTimeImmutable('2026-11-03 10:00:00Z'), 3600, TimeEntrySource::Web))->assignScopeKey('org-a');
        $open   = (new TimeEntry($this->user, 'task-1', 'T', null, new DateTimeImmutable('2026-11-10 09:00:00Z'), new DateTimeImmutable('2026-11-10 10:00:00Z'), 3600, TimeEntrySource::Web))->assignScopeKey('org-a');
        $orphan = new TimeEntry(new stdClass(), 'task-1', 'T', null, new DateTimeImmutable('2026-11-10 09:00:00Z'), new DateTimeImmutable('2026-11-10 10:00:00Z'), 3600, TimeEntrySource::Web);

        self::assertFalse($guard->isEntryWritable($locked));
        self::assertTrue($guard->isEntryWritable($open));
        self::assertFalse($guard->isEntryWritable($orphan), 'an entry without a known owner fails closed');
        self::assertTrue($guard->isEnabled());
    }

    public function testOffAllowsEverythingWithoutAQuery(): void
    {
        $this->timesheets->save(TimesheetFixtures::locked($this->user, 'org-a', '2026-W45'));
        $guard = new PeriodLockGuard($this->timesheets, false);
        $entry = (new TimeEntry($this->user, 'task-1', 'T', null, new DateTimeImmutable('2026-11-03 09:00:00Z'), new DateTimeImmutable('2026-11-03 10:00:00Z'), 3600, TimeEntrySource::Web))->assignScopeKey('org-a');

        $guard->assertWritable($this->user, 'org-a', new DateTimeImmutable('2026-11-04 09:00:00Z'), WriteKind::TimerStart);
        self::assertTrue($guard->isEntryWritable($entry));
        self::assertNull($guard->lockingTimesheet($this->user, 'org-a', new DateTimeImmutable('2026-11-04 09:00:00Z')));
        self::assertFalse($guard->isEnabled());
    }

    public function testSingleScopeProviderAndScopes(): void
    {
        $provider = new SingleScopeProvider('Company', 'Europe/Madrid');
        $entry    = new TimeEntry($this->user, 'task-1', 'T', null, new DateTimeImmutable(), new DateTimeImmutable(), 0, TimeEntrySource::Web);

        self::assertEquals([new TimesheetScope('', 'Company', 'Europe/Madrid')], $provider->scopesFor($this->user));
        self::assertSame('', $provider->scopeKeyForTask($this->user, 'task-1', null));
        self::assertSame([$entry->getId() => ''], $provider->scopeKeysForEntries([$entry]));

        $this->expectException(InvalidArgumentException::class);
        new TimesheetScope(str_repeat('x', 65), 'Too long');
    }
}
