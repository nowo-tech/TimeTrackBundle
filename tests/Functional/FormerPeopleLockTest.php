<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Enum\TimesheetLockReason;
use Nowo\TimeTrackBundle\Enum\TimesheetPeriodKind;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryData;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryOutcome;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryPerson;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryWriter;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimeEntryRepository;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Retention\FormerPersonTimeEntries;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Stub\StubTimesheetApproverProvider;
use Nowo\TimeTrackBundle\Timesheet\Period;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;

/**
 * Since 1.15: the period lock outlives the user. A deleted user's entries stay locked by the timesheets kept under
 * `owner_ref`; a former person has no timesheet; an entry with no identity at all has an unknown owner.
 */
final class FormerPeopleLockTest extends FunctionalTestCase
{
    private const SOURCE = 'clickup:dir-1';

    protected static function timeTrackConfig(): array
    {
        return ['timesheets' => ['enabled' => true, 'scope_mode' => 'single', 'approver_provider' => StubTimesheetApproverProvider::class]];
    }

    public function testADeletedUsersEntryStaysLockedByTheirTimesheet(): void
    {
        $owner = (string) $this->user->getId();
        $this->writer()->upsert($this->user, $this->data('locked'));
        $this->lockWeek();
        $entry = $this->reload('locked');
        self::assertFalse($this->guard()->isEntryWritable($entry));

        $this->people()->release($this->user, 'Ana');
        $this->entityManager()->clear();
        $entry = $this->reload('locked');
        self::assertSame($owner, $entry->getFormerUserId());
        self::assertFalse($this->guard()->isEntryWritable($entry), 'releasing the entry does not unlock it');
        self::assertArrayHasKey($entry->getId(), $this->guard()->blockedEntryIds([$entry]));

        $person = new ImportedTimeEntryPerson('p-1', 'Ana');
        self::assertSame(ImportedTimeEntryOutcome::Locked, $this->writer()->upsertForPerson($person, $this->data('locked', seconds: 60))->outcome);
        self::assertSame(0, $this->writer()->remove(self::SOURCE, 'locked'), 'kept');
        self::assertSame(0, $this->writer()->removeForSource(self::SOURCE), 'kept');
        $this->entityManager()->clear();
        self::assertSame(3600, $this->reload('locked')->getDurationSeconds());
    }

    public function testAFormerPersonHasNoTimesheetToLockThem(): void
    {
        $this->writer()->upsert($this->user, $this->data('locked'));
        $this->lockWeek();
        $person = new ImportedTimeEntryPerson('p-1', 'Ana');
        $this->writer()->upsertForPerson($person, $this->data('free'));
        $entry = $this->reload('free');

        self::assertTrue($this->guard()->isEntryWritable($entry));
        self::assertSame([], $this->guard()->blockedEntryIds([$entry]));
        self::assertSame(ImportedTimeEntryOutcome::Updated, $this->writer()->upsertForPerson($person, $this->data('free', seconds: 60))->outcome);
        self::assertSame(1, $this->writer()->remove(self::SOURCE, 'free'));
    }

    public function testAClaimCannotEnterTheNewOwnersLockedPeriod(): void
    {
        $this->writer()->upsertForPerson(new ImportedTimeEntryPerson('p-1', 'Ana'), $this->data('free'));
        $this->lockWeek();
        $this->entityManager()->clear();

        self::assertSame(ImportedTimeEntryOutcome::Locked, $this->writer()->upsert($this->managedUser(), $this->data('free'))->outcome);
        $this->entityManager()->clear();
        self::assertTrue($this->reload('free')->isOwnerless());
    }

    public function testAnEntryWithNoIdentityHasAnUnknownOwner(): void
    {
        $this->writer()->upsert($this->user, $this->data('e-1'));
        $this->entityManager()->getConnection()->executeStatement('UPDATE time_track_entries SET user_id = NULL');
        $this->entityManager()->clear();
        $entry = $this->reload('e-1');

        self::assertFalse($this->guard()->isEntryWritable($entry));
        self::assertArrayHasKey($entry->getId(), $this->guard()->blockedEntryIds([$entry]));
        self::assertSame(ImportedTimeEntryOutcome::Locked, $this->writer()->upsert($this->managedUser(), $this->data('e-1'))->outcome);
        self::assertSame(0, $this->writer()->removeForSource(self::SOURCE));
    }

    public function testErasureOutranksTheLock(): void
    {
        $this->writer()->upsert($this->user, $this->data('locked', description: 'Ana was sick'));
        $this->lockWeek();

        self::assertSame(1, $this->people()->anonymiseUser($this->user));
        $this->entityManager()->clear();
        $entry = $this->reload('locked');
        self::assertTrue($entry->isAnonymised());
        self::assertNull($entry->getDescription());
        self::assertSame(3600, $entry->getDurationSeconds());
    }

    private function managedUser(): AppUser
    {
        $user = $this->entityManager()->find(AppUser::class, $this->user->getId());
        self::assertInstanceOf(AppUser::class, $user);

        return $user;
    }

    private function lockWeek(): void
    {
        $em        = $this->entityManager();
        $timesheet = new Timesheet($this->user, (string) $this->user->getId(), '', Period::fromKey(TimesheetPeriodKind::Week, '2026-W01'), 'UTC', new DateTimeImmutable('2026-01-10 08:00:00'));
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

        return new ImportedTimeEntryWriter($repository, periodLockGuard: $this->guard());
    }

    private function guard(): PeriodLockGuard
    {
        $guard = self::getContainer()->get(PeriodLockGuard::class);
        self::assertInstanceOf(PeriodLockGuard::class, $guard);

        return $guard;
    }

    private function people(): FormerPersonTimeEntries
    {
        $people = self::getContainer()->get(FormerPersonTimeEntries::class);
        self::assertInstanceOf(FormerPersonTimeEntries::class, $people);

        return $people;
    }

    private function data(string $id, int $seconds = 3600, ?string $description = 'Imported'): ImportedTimeEntryData
    {
        $start = new DateTimeImmutable('2026-01-01 10:00:00');

        return new ImportedTimeEntryData(self::SOURCE, $id, 'task-1', 'Implement login API', 'board-1', $start, $start->modify('+1 hour'), $seconds, true, $description);
    }
}
