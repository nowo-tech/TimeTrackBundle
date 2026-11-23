<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TimeTrackBundle\Doctrine\TimeTrackManagerRegistry;
use Nowo\TimeTrackBundle\Entity\BalanceLedgerLine;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\LedgerKind;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Enum\TimesheetLockReason;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Support\AttemptCounter;
use Nowo\TimeTrackBundle\Tests\Support\TimesheetFixtures;
use Nowo\TimeTrackBundle\Transaction\TimeTrackTransactionRunner;
use Nowo\TransactionContract\TransientStorageFailure;
use RuntimeException;
use Throwable;

/**
 * 1.27.0 (Beacon spec 239): the bundle's transaction runner, on TimeTrack's private entity manager (the only mode since
 * 2.0): one attempt in one transaction, a failed unit forgets what it touched (the private manager is replaced; the
 * application's manager and its entities are never involved), statements-only units never flush, and replays only of
 * the outermost transaction.
 */
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
            $this->bundleEntityManager()->persist($entry);

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(1, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_entries'));
        self::assertFalse($this->entityManager()->getConnection()->isTransactionActive());
    }

    public function testAFailedUnitNeverTouchesTheApplicationsManager(): void
    {
        $em      = $this->entityManager();
        $private = $this->bundleEntityManager();
        $kept    = $this->entry('task-1');
        $private->persist($kept);
        $private->flush();
        $pending = new AppUser('runner-pending@example.com', 'secret');
        $em->persist($pending);

        $failure = $this->failureOf(function () use ($private): void {
            $this->runner()->run(function () use ($private): never {
                $private->persist($this->entry('task-3'));

                throw new RuntimeException('refused');
            });
        });
        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertSame('refused', $failure->getMessage());

        self::assertTrue($em->isOpen());
        self::assertTrue($em->contains($this->user), 'the application\'s entities stay managed');
        self::assertTrue($em->getUnitOfWork()->isScheduledForInsert($pending), 'the application\'s pending work stays pending');
        self::assertSame(0, (int) $em->getConnection()->fetchOne("SELECT COUNT(*) FROM app_user WHERE email = 'runner-pending@example.com'"));
        self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_entries'));
    }

    public function testAFailedStatementsUnitForgetsOnlyTheTimeTrackRowsItTouched(): void
    {
        $em        = $this->bundleEntityManager();
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
            $this->runner()->runStatements(static function () use ($em, $changed, $added, $timesheet): never {
                $changed->markBillable(true);
                $timesheet->applyLock(new DateTimeImmutable(), TimesheetLockReason::Manual);
                $em->persist($added);

                throw new RuntimeException('refused');
            });
        });
        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertSame('refused', $failure->getMessage());

        self::assertTrue($em->isOpen());
        self::assertTrue($this->entityManager()->contains($this->user), 'the application\'s entities stay managed');
        self::assertTrue($em->contains($kept), 'a row the unit did not change stays managed');
        self::assertFalse($em->contains($changed), 'a row the unit changed is forgotten');
        self::assertFalse($em->contains($added), 'a row the unit added is forgotten');
        self::assertFalse($em->contains($timesheet));
        self::assertFalse($em->contains($pointing), 'a kept row that points at a forgotten one goes too');
        self::assertSame(2, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_entries'));
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
            $runner = new TimeTrackTransactionRunner($this->bundleEntityManager(), $this->registry(), 'default', 2, $backoff);
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
        $registry = self::getContainer()->get(TimeTrackManagerRegistry::class);
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
