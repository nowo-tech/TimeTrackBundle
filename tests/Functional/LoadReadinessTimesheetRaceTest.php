<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Enum\LedgerKind;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Exception\TimesheetException;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimeEntryRepository;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Stub\StubTimesheetApproverProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedWorkingCalendar;
use Nowo\TimeTrackBundle\Tests\Support\HookClock;
use Nowo\TimeTrackBundle\Tests\Support\ServiceCopy;
use Nowo\TimeTrackBundle\Timesheet\Period;
use Nowo\TimeTrackBundle\Timesheet\TimesheetService;
use Nowo\TimeTrackBundle\ValueObject\Uuid;
use Nowo\TimeTrackBundle\WorkingTime\BalanceLedger;
use Symfony\Component\Clock\NativeClock;

/**
 * 1.27.0 (Beacon spec 239): the races of the timesheet and ledger write paths (locked periods), played
 * deterministically. Every test fails on 1.26.0 and passes with the fixes.
 */
final class LoadReadinessTimesheetRaceTest extends FunctionalTestCase
{
    private AppUser $approver;

    private Period $week;

    protected static function timeTrackConfig(): array
    {
        return [
            'timesheets'   => ['enabled' => true, 'scope_provider' => FixedScopeProvider::class, 'approver_provider' => StubTimesheetApproverProvider::class],
            'working_time' => ['enabled' => true, 'calendar_provider' => FixedWorkingCalendar::class],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->approver = $this->createUser('boss@example.com');
        $owner          = (string) $this->user->getId();
        $boss           = (string) $this->approver->getId();
        $scopes         = self::getContainer()->get(FixedScopeProvider::class);
        self::assertInstanceOf(FixedScopeProvider::class, $scopes);
        $scopes->memberships = [$owner => ['org-a'], $boss => ['org-a']];
        $approvers           = self::getContainer()->get(StubTimesheetApproverProvider::class);
        self::assertInstanceOf(StubTimesheetApproverProvider::class, $approvers);
        $approvers->approvers = ['org-a' => [$boss => [$owner]]];
        $this->week           = $this->timesheets()->period($this->user, 'org-a', 'previous');
    }

    /**
     * R3 (locked periods): an entry of the period commits after the submit read the entries, before the submit's
     * transaction. Up to 1.26 the submit stored a hash without that entry (its approval then answered `stale`).
     */
    public function testASubmitHashesAnEntryCommittedBeforeItsLock(): void
    {
        $monday = $this->week->startsAt('UTC');
        $this->entry($monday->modify('+8 hours'), 3600);
        $this->timesheets()->view($this->user, (string) $this->user->getId(), 'org-a', $this->week);
        $clock   = new HookClock(new NativeClock());
        $service = ServiceCopy::with($this->timesheets(), ['clock' => $clock]);
        $clock->arm([2 => fn (): TimeEntry => $this->entry($monday->modify('+1 day +9 hours'), 1800)]);

        $submitted = $service->submit($this->user, 'org-a', $this->week);

        self::assertSame($this->timesheets()->snapshot($submitted)->entriesHash, $submitted->getEntriesHash(), 'the hash covers every entry of the period');
        self::assertSame(5400, $submitted->getTotals()['taskSeconds'] ?? null);
        $approved = $this->timesheets()->approve($this->approver, $submitted->getId());
        self::assertTrue($approved->isLocked(), 'the approval is not refused as stale');
    }

    /**
     * R9: two first views (or a view and a submit) of a period at once. Up to 1.26 the second insert failed on the unique
     * key (`conflict`), closed the manager and reset the application's manager (its user detached).
     */
    public function testTwoFirstViewsShareOneTimesheet(): void
    {
        $period  = $this->timesheets()->period($this->user, 'org-a', null);
        $clock   = new HookClock(new NativeClock());
        $service = ServiceCopy::with($this->timesheets(), ['clock' => $clock]);
        $other   = Uuid::generate()->toString();
        $clock->arm([1 => fn () => $this->insertTimesheet($other, $period)]);

        $timesheet = $service->view($this->user, (string) $this->user->getId(), 'org-a', $period);

        self::assertSame($other, $timesheet->getId(), 'the row the other request created');
        self::assertSame(1, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_timesheets'));
        self::assertTrue($this->entityManager()->isOpen());
        self::assertTrue($this->entityManager()->contains($this->user), 'the application\'s user stays managed');
    }

    /**
     * R3 (locked periods, second half): a decision another approver committed meanwhile. Since 1.27.0 the row is read
     * again under its lock: the later decision is refused as `invalid_transition` (never a lost update).
     */
    public function testALaterDecisionOnADecidedTimesheetIsRefused(): void
    {
        $monday = $this->week->startsAt('UTC');
        $this->entry($monday->modify('+8 hours'), 3600);
        $sheet = $this->timesheets()->submit($this->user, 'org-a', $this->week);
        $this->entityManager()->getConnection()->executeStatement("UPDATE time_track_timesheets SET status = 'rejected', version = version + 1 WHERE id = ?", [$sheet->getId()]);

        try {
            $this->timesheets()->approve($this->approver, $sheet->getId());
            self::fail('The timesheet is no longer submitted.');
        } catch (TimesheetException $exception) {
            self::assertSame('invalid_transition', $exception->errorCode());
        }
        self::assertSame('rejected', $this->entityManager()->getConnection()->fetchOne('SELECT status FROM time_track_timesheets WHERE id = ?', [$sheet->getId()]));
    }

    /**
     * The auto-lock of a period another request locked (or deleted) after this one read it: nothing is locked twice.
     */
    public function testAnAutoLockOfAPeriodLockedOrDeletedMeanwhile(): void
    {
        $sheet      = $this->timesheets()->view($this->user, (string) $this->user->getId(), 'org-a', $this->week);
        $clock      = new HookClock(new NativeClock());
        $service    = ServiceCopy::with($this->timesheets(), ['clock' => $clock]);
        $connection = $this->entityManager()->getConnection();
        $clock->arm([1 => static fn (): int|string => $connection->executeStatement("UPDATE time_track_timesheets SET locked_at = '2026-01-01 00:00:00', lock_reason = 'manual'")]);

        self::assertNull($service->autoLock($this->user, 'org-a', $this->week), 'locked meanwhile');
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM time_track_timesheet_events'));

        $connection->executeStatement('UPDATE time_track_timesheets SET locked_at = NULL, lock_reason = NULL');
        $this->clearEntityManagers();
        $clock->arm([1 => static fn (): int|string => $connection->executeStatement('DELETE FROM time_track_timesheets')]);
        try {
            $service->autoLock($this->user, 'org-a', $this->week);
            self::fail('The timesheet is gone.');
        } catch (TimesheetException $exception) {
            self::assertSame('not_found', $exception->errorCode());
        }
        self::assertNotSame('', $sheet->getId());
    }

    /**
     * R10: a ledger line added while the head moved. Since 1.27.0 the add reads the head under the owner's lock: lines
     * added one after the other chain (the head is read again inside the unit of work).
     */
    public function testLedgerLinesAddedOneAfterTheOtherChain(): void
    {
        $ledger = self::getContainer()->get(BalanceLedger::class);
        self::assertInstanceOf(BalanceLedger::class, $ledger);
        $first  = $ledger->add($this->approver, $this->user, 'org-a', LedgerKind::Adjustment, 60, '2026-10-01', 'first');
        $second = $ledger->add($this->approver, $this->user, 'org-a', LedgerKind::Adjustment, -30, '2026-10-02', 'second');

        self::assertSame($first->getHash(), $second->getPrevHash());
        self::assertTrue($this->entityManager()->isOpen());
    }

    private function timesheets(): TimesheetService
    {
        $service = self::getContainer()->get(TimesheetService::class);
        self::assertInstanceOf(TimesheetService::class, $service);

        return $service;
    }

    private function entry(DateTimeImmutable $start, int $seconds): TimeEntry
    {
        $entry = (new TimeEntry($this->user, 'task-1', 'T', 'board-1', $start, $start->modify('+' . $seconds . ' seconds'), $seconds, TimeEntrySource::Web))->assignScopeKey('org-a');
        (new DoctrineOrmTimeEntryRepository($this->entityManager()))->save($entry);

        return $entry;
    }

    /**
     * The period's timesheet a concurrent request committed.
     */
    private function insertTimesheet(string $id, Period $period): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->entityManager()->getConnection()->insert('time_track_timesheets', [
            'id'           => $id,
            'user_id'      => $this->user->getId(),
            'owner_ref'    => (string) $this->user->getId(),
            'scope_key'    => 'org-a',
            'period_kind'  => 'week',
            'period_start' => $period->start,
            'period_end'   => $period->end,
            'status'       => 'open',
            'timezone'     => 'UTC',
            'created_at'   => $now,
            'updated_at'   => $now,
            'version'      => 1,
        ]);
    }
}
