<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\Exception\EntityManagerClosed;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\BalanceLedgerLine;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\LedgerKind;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Enum\TimesheetLockReason;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Support\AttemptCounter;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use Nowo\TimeTrackBundle\Tests\Support\TimesheetFixtures;
use Nowo\TimeTrackBundle\Transaction\TimeTrackTransactionRunner;
use Nowo\TransactionContract\TransientStorageFailure;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Throwable;

/**
 * 1.27.0 (Beacon spec 239): the bundle's transaction runner with the shared (application's) entity manager: one
 * attempt in one transaction, only the TimeTrack rows a failed unit touched are forgotten, a manager a failed flush
 * closed stays closed until the boundary, statements-only units never flush, and replays only of the outermost
 * transaction.
 */
#[Group('shared-entity-manager')]
final class TransactionRunnerTest extends FunctionalTestCase
{
    public function testTheContainersRunnerIsTheBundlesOwn(): void
    {
        self::assertSame($this->runner(), self::getContainer()->get(TimeTrackTransactionRunner::SERVICE_ID));
        self::assertFalse($this->runner()->isTransactionActive());
    }

    public function testAUnitIsCommittedWithItsFlush(): void
    {
        $entry = $this->entry('task-1');

        $result = $this->runner()->run(function () use ($entry): string {
            $this->entityManager()->persist($entry);

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(1, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_entries'));
        self::assertFalse($this->entityManager()->getConnection()->isTransactionActive());
    }

    public function testAFailedUnitForgetsOnlyTheTimeTrackRowsItTouched(): void
    {
        $em        = $this->entityManager();
        $kept      = $this->entry('task-1');
        $changed   = $this->entry('task-2');
        $timesheet = TimesheetFixtures::open($this->user, 'org-a', '2026-W40');
        $pointing  = new BalanceLedgerLine(null, '7', 'org-a', '2026-10-01', LedgerKind::Opening, 60, new DateTimeImmutable(), null, 'x', $timesheet);
        foreach ([$kept, $changed, $timesheet, $pointing] as $row) {
            $em->persist($row);
        }
        $em->flush();
        $added = $this->entry('task-3');

        $failure = $this->failureOf(function () use ($em, $changed, $added, $timesheet): void {
            $this->runner()->run(static function () use ($em, $changed, $added, $timesheet): never {
                $changed->markBillable(true);
                $timesheet->applyLock(new DateTimeImmutable(), TimesheetLockReason::Manual);
                $em->persist($added);

                throw new RuntimeException('refused');
            });
        });
        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertSame('refused', $failure->getMessage());

        self::assertTrue($em->isOpen());
        self::assertTrue($em->contains($this->user), 'the application\'s entities stay managed');
        self::assertTrue($em->contains($kept), 'a row the unit did not change stays managed');
        self::assertFalse($em->contains($changed), 'a row the unit changed is forgotten');
        self::assertFalse($em->contains($added), 'a row the unit added is forgotten');
        self::assertFalse($em->contains($timesheet));
        self::assertFalse($em->contains($pointing), 'a kept row that points at a forgotten one goes too');
        self::assertSame(2, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_entries'));
    }

    public function testAFailedFlushLeavesTheManagerClosedUntilTheBoundary(): void
    {
        $em     = $this->entityManager();
        $logger = new RecordingLogger();
        $runner = new TimeTrackTransactionRunner($em, $this->registry(), 'default', 1, 0, $logger);

        self::assertInstanceOf(UniqueConstraintViolationException::class, $this->failureOf(function () use ($runner, $em): void {
            $runner->run(function () use ($em): void {
                $em->persist(new ActiveTimer($this->user, 'task-1', 'T', null, ClientType::Web));
                $em->persist(new ActiveTimer($this->user, 'task-2', 'T', null, ClientType::Web));
            });
        }));
        self::assertFalse($em->isOpen(), 'closed: the application\'s next flush fails loudly');
        self::assertFalse($em->getConnection()->isTransactionActive());

        self::assertInstanceOf(EntityManagerClosed::class, $this->failureOf(static function () use ($runner): void {
            $runner->runRetrying(static fn (): null => null);
        }), 'a closed manager is not used again in the same request');

        $runner->reset();
        self::assertTrue($this->entityManager()->isOpen(), 'replaced at the boundary');
        self::assertContains('TimeTrack: the entity manager closed by a failed write was replaced at the unit-of-work boundary.', $logger->messages());
        $runner->reset();
        self::assertCount(1, $logger->records, 'an open manager is left alone');
        (new TimeTrackTransactionRunner($this->entityManager()))->reset();
    }

    public function testAStatementsOnlyUnitNeverFlushesTheApplicationsPendingWork(): void
    {
        $em      = $this->entityManager();
        $pending = new AppUser('pending@example.com', 'secret');
        $em->persist($pending);

        $count = $this->runner()->runStatements(static fn (): int => (int) $em->getConnection()->executeStatement('DELETE FROM time_track_entries'));

        self::assertSame(0, $count);
        self::assertSame(0, (int) $em->getConnection()->fetchOne("SELECT COUNT(*) FROM app_user WHERE email = 'pending@example.com'"));
        self::assertTrue($em->getUnitOfWork()->isScheduledForInsert($pending));
    }

    public function testATransientFailureOfTheOutermostUnitIsReplayed(): void
    {
        foreach ([0, 1] as $backoff) {
            $runner = new TimeTrackTransactionRunner($this->entityManager(), $this->registry(), 'default', 2, $backoff);
            $state  = new AttemptCounter();

            $result = $runner->runRetrying(function () use ($state): string {
                if (++$state->count === 1) {
                    throw $this->deadlock();
                }

                return 'done';
            });

            self::assertSame('done', $result);
            self::assertSame(2, $state->count);
        }

        $state   = new AttemptCounter();
        $failure = $this->failureOf(function () use ($state): void {
            $this->runner()->runRetrying(function () use ($state): never {
                ++$state->count;

                throw $this->deadlock();
            }, 2, true);
        });
        self::assertInstanceOf(TransientStorageFailure::class, $failure);
        self::assertSame(1, $failure->retryAfterSeconds());
        self::assertSame(2, $state->count);
    }

    public function testATransientFailureInsideTheCallersTransactionIsNotReplayed(): void
    {
        $connection = $this->entityManager()->getConnection();
        $state      = new AttemptCounter();
        $connection->beginTransaction();
        $failure = $this->failureOf(function () use ($state): void {
            $this->runner()->runRetrying(function () use ($state): never {
                ++$state->count;

                throw $this->deadlock();
            });
        });
        $connection->rollBack(); // @phpstan-ignore frankenphp.worker.noRollbackAfterNestedBegin (the test plays the host's own transaction)
        self::assertInstanceOf(TransientStorageFailure::class, $failure, 'the nested failure goes to the caller');
        self::assertSame(1, $state->count);
    }

    /**
     * What the action threw (the test fails when it returns).
     */
    private function failureOf(callable $action): Throwable
    {
        try {
            $action();
        } catch (Throwable $failure) {
            return $failure;
        }

        self::fail('Expected a failure.');
    }

    private function runner(): TimeTrackTransactionRunner
    {
        $runner = self::getContainer()->get(TimeTrackTransactionRunner::class);
        self::assertInstanceOf(TimeTrackTransactionRunner::class, $runner);

        return $runner;
    }

    private function registry(): ManagerRegistry
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        return $registry;
    }

    private function entry(string $taskId): TimeEntry
    {
        $start = new DateTimeImmutable('2026-10-05 09:00:00');

        return new TimeEntry($this->user, $taskId, 'T', 'board-1', $start, $start->modify('+1 hour'), 3600, TimeEntrySource::Web);
    }

    private function deadlock(): DeadlockException
    {
        return new DeadlockException(new class('Deadlock found when trying to get lock') extends AbstractException {}, null);
    }
}
