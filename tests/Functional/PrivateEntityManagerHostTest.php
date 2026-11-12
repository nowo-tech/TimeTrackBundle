<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\TimeTrackBundle\Doctrine\TimeTrackEntityManager;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\BalanceLedgerLine;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\LedgerKind;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use ReflectionProperty;

/**
 * 1.23.0 (REQ-ARCH-001 rule 6, `database.private_entity_manager: true`): the bundle never flushes, clears or closes the
 * application's entity manager. Its writes go through its private manager on the same connection, so the application's
 * transaction decides about them, and a failed bundle write closes only the private manager.
 */
final class PrivateEntityManagerHostTest extends FunctionalTestCase
{
    protected static function timeTrackConfig(): array
    {
        return ['database' => ['private_entity_manager' => true]];
    }

    public function testABundleWriteNeverFlushesTheApplicationsPendingWork(): void
    {
        $host    = $this->entityManager();
        $pending = new AppUser('pending@example.com', 'secret');
        $host->persist($pending);
        (new ReflectionProperty(AppUser::class, 'email'))->setValue($this->user, 'changed-in-memory@example.com');

        $timer = $this->timers()->start($this->user, 'task-1', ClientType::Web);

        self::assertSame(0, (int) $host->getConnection()->fetchOne("SELECT COUNT(*) FROM app_user WHERE email = 'pending@example.com'"), 'the pending insert is not written');
        self::assertSame('person@example.com', $host->getConnection()->fetchOne('SELECT email FROM app_user WHERE id = ?', [$this->user->getId()]), 'the pending update is not written');
        self::assertTrue($host->getUnitOfWork()->isScheduledForInsert($pending), 'still the application\'s pending work');
        self::assertTrue($host->contains($this->user), 'nothing of the application is cleared');
        self::assertFalse($host->contains($timer), 'the bundle row lives in the private manager');
        self::assertSame(1, (int) $host->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_active_timers'));

        $host->flush();
        self::assertSame(1, (int) $host->getConnection()->fetchOne("SELECT COUNT(*) FROM app_user WHERE email = 'pending@example.com'"));
        self::assertSame('changed-in-memory@example.com', $host->getConnection()->fetchOne('SELECT email FROM app_user WHERE id = ?', [$this->user->getId()]), 'the application writes its own work when it flushes');
    }

    public function testAFailedBundleWriteClosesOnlyThePrivateManager(): void
    {
        $private = self::getContainer()->get(TimeTrackEntityManager::class);
        self::assertInstanceOf(TimeTrackEntityManager::class, $private);
        $first = new BalanceLedgerLine(null, '7', 'org-a', '2026-11-02', LedgerKind::Opening, 60, new DateTimeImmutable(), null, 'x');
        $twin  = new BalanceLedgerLine(null, '7', 'org-a', '2026-11-03', LedgerKind::Opening, 60, new DateTimeImmutable(), null, 'y');
        $private->persist($first);
        $private->flush();
        $closed = $private->live();
        $private->persist($twin);
        try {
            $private->flush();
            self::fail('the duplicate chain start is refused');
        } catch (UniqueConstraintViolationException) {
        }

        self::assertFalse($closed->isOpen());
        self::assertTrue($this->entityManager()->isOpen(), 'the application\'s manager stays open');
        self::assertNotSame($closed, $private->live(), 'the next bundle call gets a new private manager');
        self::assertTrue($this->timers()->start($this->user, 'task-1', ClientType::Web)->getUserId() === (string) $this->user->getId());
    }

    public function testTheApplicationsTransactionDecidesAboutTheBundleRows(): void
    {
        $connection = $this->entityManager()->getConnection();

        $connection->beginTransaction();
        $this->timers()->start($this->user, 'task-1', ClientType::Web);
        $connection->rollBack();
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM time_track_active_timers'), 'rolled back with the application');
        self::assertNull($this->timers()->getActive($this->user), 'and not served from the private identity map');

        $connection->beginTransaction();
        $this->timers()->start($this->user, 'task-2', ClientType::Web);
        $connection->commit();
        $owner  = $this->user;
        $active = $this->timers()->getActive($owner);
        self::assertInstanceOf(ActiveTimer::class, $active, 'committed with the application');
        self::assertSame('task-2', $active->getTaskId());
    }

    public function testDeletingAUserDropsThePrivateCopiesOfItsEntries(): void
    {
        $other = $this->createUser('leaving@example.com');
        $this->timers()->start($other, 'task-1', ClientType::Web);
        $entry = $this->timers()->stop($other);
        self::assertInstanceOf(TimeEntry::class, $entry);
        $entries = self::getContainer()->get(TimeEntryRepositoryInterface::class);
        self::assertInstanceOf(TimeEntryRepositoryInterface::class, $entries);
        $id = (string) $other->getId();
        self::assertCount(1, $entries->findByUserAndPeriod($id, new DateTimeImmutable('-1 day'), new DateTimeImmutable('+1 day')));

        $this->entityManager()->remove($other);
        $this->entityManager()->flush();

        $private = self::getContainer()->get(TimeTrackEntityManager::class);
        self::assertInstanceOf(TimeTrackEntityManager::class, $private);
        $reloaded = $private->find(TimeEntry::class, $entry->getId());
        self::assertInstanceOf(TimeEntry::class, $reloaded);
        self::assertNotSame($entry, $reloaded, 'read again after the user\'s entries were settled');
        self::assertNull($reloaded->getUser());
        self::assertSame('user:' . $id, $reloaded->getPersonRef());
    }

    private function timers(): TimerService
    {
        $timers = self::getContainer()->get(TimerService::class);
        self::assertInstanceOf(TimerService::class, $timers);

        return $timers;
    }
}
