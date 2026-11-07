<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Enum\TimesheetLockReason;
use Nowo\TimeTrackBundle\Enum\TimesheetPeriodKind;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryClaim;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryData;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryPerson;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryWriter;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimeEntryRepository;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Stub\StubTimesheetApproverProvider;
use Nowo\TimeTrackBundle\Timesheet\Period;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScopeProviderInterface;

/**
 * Since 1.16: a move honours the period lock: locked rows stay and are counted, for the current owner and for the new user.
 */
final class ImportedOwnerMoveLockTest extends FunctionalTestCase
{
    private const SOURCE = 'clickup:dir-1';

    protected static function timeTrackConfig(): array
    {
        return ['timesheets' => ['enabled' => true, 'scope_mode' => 'single', 'approver_provider' => StubTimesheetApproverProvider::class]];
    }

    public function testARowInTheCurrentOwnersLockedPeriodStays(): void
    {
        $writer = $this->writer();
        $writer->upsert($this->user, $this->data('locked', '2026-01-01 10:00:00'));
        $writer->upsert($this->user, $this->data('open', '2026-02-02 10:00:00'));
        $this->lockWeek($this->user);

        $result = $writer->moveToPerson(self::SOURCE, $this->user, new ImportedTimeEntryPerson('p-1', 'Ana', ''));
        self::assertSame([1, 1, 0], [$result->moved, $result->keptLocked, $result->refusedScope]);
        $this->entityManager()->clear();
        self::assertNotNull($this->reload('locked')->getUser());
        self::assertNull($this->reload('open')->getUser());
    }

    public function testARowStaysOutOfTheNewUsersLockedPeriod(): void
    {
        $other  = $this->createUser('other@example.com');
        $writer = $this->writer();
        $writer->upsert($this->user, $this->data('e-1', '2026-01-01 10:00:00'));
        $this->lockWeek($other);

        self::assertSame(1, $writer->moveBetweenUsers(self::SOURCE, $this->user, $other)->keptLocked);
        $this->entityManager()->clear();
        $owner = $this->reload('e-1')->getUser();
        self::assertInstanceOf(AppUser::class, $owner);
        self::assertSame($this->user->getId(), $owner->getId());
    }

    public function testAPersonsRowStaysOutOfTheClaimersLockedPeriod(): void
    {
        $writer = $this->writer();
        $writer->upsertForPerson(new ImportedTimeEntryPerson('p-1', 'Ana', ''), $this->data('e-1', '2026-01-01 10:00:00'));
        $this->lockWeek($this->user);

        self::assertSame(1, $writer->moveToUser(self::SOURCE, new ImportedTimeEntryClaim($this->user, 'p-1'))->keptLocked);
    }

    private function lockWeek(AppUser $user): void
    {
        $em        = $this->entityManager();
        $timesheet = new Timesheet($user, (string) $user->getId(), '', Period::fromKey(TimesheetPeriodKind::Week, '2026-W01'), 'UTC', new DateTimeImmutable('2026-01-10 08:00:00'));
        $timesheet->applyLock(new DateTimeImmutable('2026-01-10 08:00:00'), TimesheetLockReason::Manual);
        $em->persist($timesheet);
        $em->flush();
    }

    private function reload(string $externalId): TimeEntry
    {
        $repository = self::getContainer()->get(TimeEntryRepositoryInterface::class);
        self::assertInstanceOf(DoctrineOrmTimeEntryRepository::class, $repository);
        $entry = $repository->findByExternal(self::SOURCE, $externalId);
        self::assertInstanceOf(TimeEntry::class, $entry);

        return $entry;
    }

    private function writer(): ImportedTimeEntryWriter
    {
        $repository = self::getContainer()->get(TimeEntryRepositoryInterface::class);
        self::assertInstanceOf(DoctrineOrmTimeEntryRepository::class, $repository);
        $guard = self::getContainer()->get(PeriodLockGuard::class);
        self::assertInstanceOf(PeriodLockGuard::class, $guard);

        $scopes = self::getContainer()->get(TimesheetScopeProviderInterface::class);
        self::assertInstanceOf(TimesheetScopeProviderInterface::class, $scopes);

        return new ImportedTimeEntryWriter($repository, periodLockGuard: $guard, scopeProvider: $scopes);
    }

    private function data(string $id, string $start): ImportedTimeEntryData
    {
        $from = new DateTimeImmutable($start);

        return new ImportedTimeEntryData(self::SOURCE, $id, 'task-1', 'Task', 'board-1', $from, $from->modify('+1 hour'), 3600);
    }
}
