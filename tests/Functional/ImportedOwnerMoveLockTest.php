<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
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
use Nowo\TimeTrackBundle\Retention\FormerPersonTimeEntries;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Stub\StubTimesheetApproverProvider;
use Nowo\TimeTrackBundle\Timesheet\Period;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScope;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScopeProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

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

    public function testAPeriodLockedDuringAMoveIsSeenOrWaitsForIt(): void
    {
        $conn = $this->entityManager()->getConnection();
        if ($conn->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('The in-memory database has no second connection; SQLite has no snapshot reads across writers here.');
        }
        $target = $this->createUser('target@example.com');
        $writer = $this->writer();
        $writer->upsert($this->user, $this->data('one', '2026-01-01 10:00:00'));
        $writer->upsert($this->user, $this->data('two', '2026-01-01 12:00:00'));
        $em        = $this->entityManager();
        $timesheet = new Timesheet($this->user, (string) $this->user->getId(), '', Period::fromKey(TimesheetPeriodKind::Week, '2026-W01'), 'UTC', new DateTimeImmutable('2026-01-10 08:00:00'));
        $em->persist($timesheet);
        $em->flush();
        $id = $timesheet->getId();

        // Another connection tries to lock the week after the move has checked its first row.
        $other = DriverManager::getConnection($conn->getParams());
        if ($conn->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $other->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
        } else {
            $other->executeStatement("SET lock_timeout = '1s'");
        }
        $committed = false;
        $calls     = 0;
        $scopes    = $this->createStub(TimesheetScopeProviderInterface::class);
        $scopes->method('scopesFor')->willReturn([new TimesheetScope('', '')]);
        $scopes->method('scopeKeyForTask')->willReturnCallback(static function () use ($other, $id, &$committed, &$calls): string {
            if (++$calls === 2) {
                try {
                    $other->executeStatement('UPDATE time_track_timesheets SET locked_at = ?, lock_reason = ? WHERE id = ?', ['2026-01-10 09:00:00', 'manual', $id]);
                    $committed = true;
                } catch (DriverException $exception) {
                    // The move's shared lock holds it off until the move ends; anything else is a broken test.
                    self::assertStringContainsStringIgnoringCase('lock', $exception->getMessage());
                }
            }

            return '';
        });
        $repository = self::getContainer()->get(TimeEntryRepositoryInterface::class);
        self::assertInstanceOf(DoctrineOrmTimeEntryRepository::class, $repository);
        $guard = self::getContainer()->get(PeriodLockGuard::class);
        self::assertInstanceOf(PeriodLockGuard::class, $guard);
        $em->clear();

        $result = (new ImportedTimeEntryWriter($repository, periodLockGuard: $guard, scopeProvider: $scopes))->moveBetweenUsers(self::SOURCE, $this->managedUser(), $this->managedTarget($target));
        $other->close();

        self::assertSame(2, $calls, 'the provider was asked for both rows');
        // Either the lock waited, so both rows left before it, or the second row saw it and stayed.
        self::assertSame($committed ? [1, 1] : [2, 0], [$result->moved, $result->keptLocked]);
    }

    public function testTheSecondsReportedAreTheSecondsMovedUnderARace(): void
    {
        $conn = $this->entityManager()->getConnection();
        if ($conn->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('The in-memory database has no second connection.');
        }
        $thief  = $this->createUser('thief@example.com');
        $target = $this->createUser('target@example.com');
        $writer = $this->writer();
        $start  = new DateTimeImmutable('2026-02-02 10:00:00');
        foreach (['task-1', 'task-2'] as $task) {
            $writer->upsert($this->user, new ImportedTimeEntryData(self::SOURCE, 'e-' . $task, $task, 'Task', 'board-1', $start, $start->modify('+1 hour'), 3600));
        }

        // Another connection tries to take the row the move has read but not reached.
        $other = DriverManager::getConnection($conn->getParams());
        if ($conn->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $other->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
        } else {
            $other->executeStatement("SET lock_timeout = '1s'");
        }
        $calls  = 0;
        $scopes = $this->createStub(TimesheetScopeProviderInterface::class);
        $scopes->method('scopesFor')->willReturn([new TimesheetScope('', '')]);
        $scopes->method('scopeKeyForTask')->willReturnCallback(static function (UserInterface $user, string $taskId) use ($other, $thief, &$calls): string {
            if (++$calls === 1) {
                try {
                    $other->executeStatement('UPDATE time_track_entries SET user_id = ? WHERE task_id <> ?', [$thief->getId(), $taskId]);
                } catch (DriverException $exception) {
                    // The move's lock holds it off; anything else is a broken test.
                    self::assertStringContainsStringIgnoringCase('lock', $exception->getMessage());
                }
            }

            return '';
        });
        $repository = self::getContainer()->get(TimeEntryRepositoryInterface::class);
        self::assertInstanceOf(DoctrineOrmTimeEntryRepository::class, $repository);
        $this->entityManager()->clear();

        $result = (new ImportedTimeEntryWriter($repository, scopeProvider: $scopes))->moveBetweenUsers(self::SOURCE, $this->managedUser(), $this->managedTarget($target));
        $other->close();

        self::assertSame($result->moved * 3600, $result->secondsMoved);
        self::assertSame(2, $calls, 'the provider was asked for both rows');
    }

    public function testASubmittedPeriodAlsoKeepsARowOfADeletedUser(): void
    {
        $writer = $this->writer();
        $writer->upsert($this->user, $this->data('e-1', '2026-01-01 10:00:00'));
        $this->lockWeek($this->user);
        $reference = 'user:' . $this->user->getId();
        $people    = self::getContainer()->get(FormerPersonTimeEntries::class);
        self::assertInstanceOf(FormerPersonTimeEntries::class, $people);
        $people->release($this->user, 'Ana');
        $this->entityManager()->clear();

        self::assertSame(1, $writer->moveToUser(self::SOURCE, new ImportedTimeEntryClaim($this->managedUser(), $reference, ''))->keptLocked);
    }

    private function managedTarget(AppUser $target): AppUser
    {
        $user = $this->entityManager()->find(AppUser::class, $target->getId());
        self::assertInstanceOf(AppUser::class, $user);

        return $user;
    }

    private function managedUser(): AppUser
    {
        $user = $this->entityManager()->find(AppUser::class, $this->user->getId());
        self::assertInstanceOf(AppUser::class, $user);

        return $user;
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
