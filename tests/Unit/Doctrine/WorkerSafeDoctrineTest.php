<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TimeTrackBundle\Doctrine\ManagedEntityRefresher;
use Nowo\TimeTrackBundle\Doctrine\RecoveringFlusher;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmActiveTimerRepository;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WorkerSafeDoctrineTest extends TestCase
{
    public function testFlushResetsClosedManagerAndRethrows(): void
    {
        $failure = new RuntimeException('Unique constraint violation');
        $em      = $this->createMock(EntityManagerInterface::class);
        $em->method('flush')->willThrowException($failure);
        $em->method('isOpen')->willReturn(false);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagers')->willReturn([
            'other'      => $this->createMock(EntityManagerInterface::class),
            'time_track' => $em,
        ]);
        $registry->expects(self::once())->method('resetManager')->with('time_track')->willReturn($em);

        $this->expectExceptionObject($failure);
        RecoveringFlusher::flush($em, $registry);
    }

    public function testResetIfClosedIgnoresOpenUnknownOrMissingRegistry(): void
    {
        $open = $this->createMock(EntityManagerInterface::class);
        $open->method('isOpen')->willReturn(true);

        $closed = $this->createMock(EntityManagerInterface::class);
        $closed->method('isOpen')->willReturn(false);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagers')->willReturn(['default' => $this->createMock(EntityManagerInterface::class)]);
        $registry->expects(self::never())->method('resetManager');

        RecoveringFlusher::resetIfClosed($open, $registry);
        RecoveringFlusher::resetIfClosed($closed, $registry);
        RecoveringFlusher::resetIfClosed($closed, null);
    }

    public function testConcurrentTimerStartDoesNotLeaveTheWorkerWithAClosedManager(): void
    {
        $open    = true;
        $flushes = 0;

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturnCallback(static function () use (&$open): bool {
            return $open;
        });
        $em->expects(self::exactly(2))->method('flush')->willReturnCallback(static function () use (&$open, &$flushes): void {
            if (!$open) {
                throw new RuntimeException('The EntityManager is closed.');
            }

            if (++$flushes === 1) {
                $open = false;

                throw new RuntimeException('Duplicate active timer');
            }
        });

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagers')->willReturn(['default' => $em]);
        $registry->expects(self::once())->method('resetManager')->with('default')->willReturnCallback(static function () use (&$open, $em): EntityManagerInterface {
            $open = true;

            return $em;
        });

        $repository = new DoctrineOrmActiveTimerRepository($em, $registry);

        try {
            $repository->save(new ActiveTimer(new TestUser('1', 'a@example.com'), 'task-1', 'Task', null, ClientType::Desktop));
            self::fail('The flush exception must be rethrown.');
        } catch (RuntimeException $exception) {
            self::assertSame('Duplicate active timer', $exception->getMessage());
        }

        $repository->save(new ActiveTimer(new TestUser('2', 'b@example.com'), 'task-1', 'Task', null, ClientType::Desktop));
        self::assertSame(2, $flushes);
    }

    public function testRefresherOnlyRefreshesManagedEntitiesOnOpenManagers(): void
    {
        $user = new TestUser('1', 'a@example.com');

        $managed = $this->createMock(EntityManagerInterface::class);
        $managed->method('isOpen')->willReturn(true);
        $managed->method('contains')->willReturn(true);
        $managed->expects(self::once())->method('refresh')->with($user);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturnOnConsecutiveCalls($managed, null);

        ManagedEntityRefresher::refresh($registry, $user);
        ManagedEntityRefresher::refresh($registry, $user);
        ManagedEntityRefresher::refresh(null, $user);
    }
}
