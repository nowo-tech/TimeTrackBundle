<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\TimeTrackBundle\Doctrine\TimeTrackEntityManager;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Transaction\TimeTrackTransactionRunner;
use Throwable;

/**
 * 1.27.0 (Beacon spec 239): the bundle's transaction runner with the private entity manager: transaction-doctrine's
 * ORM runner on TimeTrack's own manager; a failed unit replaces only that manager; the application's manager and its
 * pending work are never touched.
 */
final class TransactionRunnerPrivateTest extends FunctionalTestCase
{
    protected static function timeTrackConfig(): array
    {
        return ['database' => ['private_entity_manager' => true]];
    }

    public function testAFailedUnitReplacesOnlyThePrivateManager(): void
    {
        $runner  = self::getContainer()->get(TimeTrackTransactionRunner::class);
        $private = self::getContainer()->get(TimeTrackEntityManager::class);
        self::assertInstanceOf(TimeTrackTransactionRunner::class, $runner);
        self::assertInstanceOf(TimeTrackEntityManager::class, $private);
        $host    = $this->entityManager();
        $pending = new AppUser('pending@example.com', 'secret');
        $host->persist($pending);
        $before = $private->live();

        $failure = null;
        try {
            $runner->run(function () use ($private): void {
                $private->persist(new ActiveTimer($this->user, 'task-1', 'T', null, ClientType::Web));
                $private->persist(new ActiveTimer($this->user, 'task-2', 'T', null, ClientType::Web));
            });
        } catch (Throwable $failure) {
        }
        self::assertInstanceOf(UniqueConstraintViolationException::class, $failure, 'the unique key refuses the second timer');

        self::assertNotSame($before, $private->live(), 'the private manager was replaced');
        self::assertTrue($host->isOpen());
        self::assertTrue($host->getUnitOfWork()->isScheduledForInsert($pending), 'the application\'s pending work is untouched');
        self::assertTrue($host->contains($this->user));
        $runner->reset();
        self::assertSame(1, $runner->run(static fn (): int => 1));
    }
}
