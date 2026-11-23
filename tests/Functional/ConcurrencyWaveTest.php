<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Entity\TimesheetEvent;
use Nowo\TimeTrackBundle\Enum\TimesheetAction;
use Nowo\TimeTrackBundle\Enum\TimesheetLockReason;
use Nowo\TimeTrackBundle\Enum\TimesheetPeriodKind;
use Nowo\TimeTrackBundle\Enum\WriteKind;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryData;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryOutcome;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryWriter;
use Nowo\TimeTrackBundle\Port\Internal\TimesheetScopeProviderInterface;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimeEntryRepository;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Tests\Stub\StubTimesheetApproverProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Tests\Support\HookClock;
use Nowo\TimeTrackBundle\Tests\Support\ServiceCopy;
use Nowo\TimeTrackBundle\Timesheet\Period;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;
use Nowo\TimeTrackBundle\Timesheet\TimesheetAuditTrail;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScope;
use Symfony\Component\Clock\NativeClock;

use function str_repeat;

/**
 * 1.31.0 (REQ-RUNTIME-003, concurrency wave phase 2): the two known issues of 1.30.0, closed.
 *
 * - QO-L3: an import plans and writes under its owners' write locks, so the period-lock check it makes cannot be
 *   overtaken by a submit, approval or lock of the same owner.
 * - QO-L4: the `edit_blocked` audit row of a refusal is appended under the timesheet's row lock (read again), so a
 *   transition that commits at that moment no longer fails the append: the caller still gets its `period_locked`
 *   refusal, never a 409 / 500 from the audit row.
 */
final class ConcurrencyWaveTest extends FunctionalTestCase
{
    private const SOURCE = 'clickup:wave';

    protected static function timeTrackConfig(): array
    {
        return [
            'timesheets' => ['enabled' => true, 'scope_provider' => FixedScopeProvider::class, 'approver_provider' => StubTimesheetApproverProvider::class, 'audit_blocked_edits' => true],
        ];
    }

    /**
     * QO-L3: while the batch is planned (existing rows read, period locks checked) and written, the connection is inside
     * the owners' unit: the lock check is made under the lock, for every owner of the batch.
     */
    public function testAnImportPlansAndWritesInsideTheOwnersUnit(): void
    {
        $other  = $this->createUser('wave-b@example.com');
        $conn   = $this->entityManager()->getConnection();
        $inUnit = [];
        $scopes = $this->createStub(TimesheetScopeProviderInterface::class);
        $scopes->method('scopesFor')->willReturn([new TimesheetScope('', '')]);
        $scopes->method('scopeKeyForTask')->willReturnCallback(static function () use ($conn, &$inUnit): string {
            $inUnit[] = $conn->isTransactionActive();

            return '';
        });
        $start = new DateTimeImmutable('2026-03-02 09:00:00');

        $batch = $this->writer($scopes)->upsertMany([
            [$other, $this->data('w-2', $start)],
            [$this->user, $this->data('w-1', $start)],
        ]);

        self::assertSame([true, true], $inUnit, 'planned under the locks');
        self::assertSame(2, $batch->created);
        self::assertFalse($conn->isTransactionActive(), 'the unit is over once the batch is written');
        self::assertSame(2, (int) $conn->fetchOne('SELECT COUNT(*) FROM time_track_entries'));
    }

    /**
     * QO-L3: without period locks (timesheets off) the import keeps its single unit and takes no owner lock.
     */
    public function testAnImportWithoutThePeriodGuardTakesNoOwnerLock(): void
    {
        $conn   = $this->entityManager()->getConnection();
        $inUnit = [];
        $scopes = $this->createStub(TimesheetScopeProviderInterface::class);
        $scopes->method('scopeKeyForTask')->willReturnCallback(static function () use ($conn, &$inUnit): string {
            $inUnit[] = $conn->isTransactionActive();

            return '';
        });
        $repository = self::getContainer()->get(TimeEntryRepositoryInterface::class);
        self::assertInstanceOf(DoctrineOrmTimeEntryRepository::class, $repository);

        (new ImportedTimeEntryWriter($repository, scopeProvider: $scopes))->upsert($this->user, $this->data('w-3', new DateTimeImmutable('2026-03-02 09:00:00')));

        self::assertSame([false], $inUnit);
    }

    /**
     * QO-L3: a period locked before the batch is read again still refuses the row (the check is unchanged, now under the lock).
     */
    public function testAnImportStillSkipsALockedPeriod(): void
    {
        $this->lockWeek();
        $scopes = $this->createStub(TimesheetScopeProviderInterface::class);
        $scopes->method('scopeKeyForTask')->willReturn('');

        $result = $this->writer($scopes)->upsert($this->user, $this->data('w-4', new DateTimeImmutable('2026-01-01 10:00:00')));

        self::assertSame(ImportedTimeEntryOutcome::Locked, $result->outcome);
        self::assertSame(0, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_entries'));
    }

    /**
     * QO-L4: a transition commits between the rate-limit read and the append (version and head moved). Up to 1.30 the
     * append failed on the stale version (shared manager: closed, 409 / 500); now the row is locked and read again and the
     * audit row chains behind the new head.
     */
    public function testARefusalRecordedWhileATransitionCommitsStillSucceeds(): void
    {
        $sheet  = $this->lockWeek();
        $id     = $sheet->getId();
        $headBy = str_repeat('a', 64);
        $clock  = new HookClock(new NativeClock());
        $trail  = ServiceCopy::with($this->trail(), ['clock' => $clock]);
        $conn   = $this->entityManager()->getConnection();
        $clock->arm([1 => static fn (): int|string => $conn->executeStatement('UPDATE time_track_timesheets SET version = version + 1, head_hash = ? WHERE id = ?', [$headBy, $id])]);

        $trail->record($sheet, (string) $this->user->getId(), WriteKind::EntryCreate);

        self::assertTrue($this->entityManager()->isOpen());
        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM time_track_timesheet_events WHERE timesheet_id = ? AND action = ?', [$id, TimesheetAction::EditBlocked->value]));
        self::assertSame($headBy, $conn->fetchOne('SELECT prev_hash FROM time_track_timesheet_events WHERE timesheet_id = ?', [$id]), 'chained behind the head the transition committed');
        self::assertNotSame($headBy, $conn->fetchOne('SELECT head_hash FROM time_track_timesheets WHERE id = ?', [$id]), 'the head moved to the new row');
    }

    /**
     * QO-L4: the rate limit holds under the lock too (a second refusal in the same minute appends nothing), and a
     * timesheet deleted meanwhile records nothing and fails nothing.
     */
    public function testTheRateLimitHoldsUnderTheLockAndAGoneTimesheetIsSkipped(): void
    {
        $sheet = $this->lockWeek();
        $trail = $this->trail();
        $owner = (string) $this->user->getId();

        $trail->record($sheet, $owner, WriteKind::EntryCreate);
        $trail->record($sheet, $owner, WriteKind::EntryCreate);
        $conn = $this->entityManager()->getConnection();
        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM time_track_timesheet_events'));

        $conn->executeStatement('DELETE FROM time_track_timesheet_events');
        $conn->executeStatement('DELETE FROM time_track_timesheets');
        $this->clearEntityManagers();
        $trail->record($sheet, $owner, WriteKind::EntryCreate);
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM time_track_timesheet_events'));
    }

    /**
     * QO-L4: the other request's `edit_blocked` row commits after the rate-limit read and before the append (the lock is
     * held): the row is read again under the lock and no second one is written.
     */
    public function testARefusalRecordedByAnotherRequestMeanwhileIsNotRecordedTwice(): void
    {
        $sheet = $this->lockWeek();
        $owner = (string) $this->user->getId();
        $clock = new HookClock(new NativeClock());
        $trail = ServiceCopy::with($this->trail(), ['clock' => $clock]);
        $clock->arm([2 => fn () => $this->trail()->record($sheet, $owner, WriteKind::EntryCreate)]);

        $trail->record($sheet, $owner, WriteKind::EntryCreate);

        self::assertSame(1, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_timesheet_events WHERE action = ?', [TimesheetAction::EditBlocked->value]));
    }

    /**
     * QO-L4: a write refused by a locked period answers the refusal (never a conflict) through the guard, with the audit row written.
     */
    public function testTheGuardRefusesAndRecordsInOneStep(): void
    {
        $this->lockWeek();
        $guard = self::getContainer()->get(PeriodLockGuard::class);
        self::assertInstanceOf(PeriodLockGuard::class, $guard);

        $refusal = $guard->refusal($this->user, '', new DateTimeImmutable('2026-01-01 10:00:00'), WriteKind::EntryCreate);

        self::assertNotNull($refusal);
        self::assertSame(1, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_timesheet_events WHERE action = ?', [TimesheetAction::EditBlocked->value]));
        $events = $this->entityManager()->getRepository(TimesheetEvent::class)->findAll();
        self::assertCount(1, $events);
    }

    private function lockWeek(): Timesheet
    {
        $em        = $this->entityManager();
        $timesheet = new Timesheet($this->user, (string) $this->user->getId(), '', Period::fromKey(TimesheetPeriodKind::Week, '2026-W01'), 'UTC', new DateTimeImmutable('2026-01-10 08:00:00'));
        $timesheet->applyLock(new DateTimeImmutable('2026-01-10 08:00:00'), TimesheetLockReason::Manual);
        $em->persist($timesheet);
        $em->flush();

        return $timesheet;
    }

    private function trail(): TimesheetAuditTrail
    {
        $trail = self::getContainer()->get(TimesheetAuditTrail::class);
        self::assertInstanceOf(TimesheetAuditTrail::class, $trail);

        return $trail;
    }

    private function writer(TimesheetScopeProviderInterface $scopes): ImportedTimeEntryWriter
    {
        $repository = self::getContainer()->get(TimeEntryRepositoryInterface::class);
        self::assertInstanceOf(DoctrineOrmTimeEntryRepository::class, $repository);
        $guard = self::getContainer()->get(PeriodLockGuard::class);
        self::assertInstanceOf(PeriodLockGuard::class, $guard);

        return new ImportedTimeEntryWriter($repository, periodLockGuard: $guard, scopeProvider: $scopes);
    }

    private function data(string $id, DateTimeImmutable $start): ImportedTimeEntryData
    {
        return new ImportedTimeEntryData(self::SOURCE, $id, 'task-1', 'Task', 'board-1', $start, $start->modify('+1 hour'), 3600);
    }
}
