<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TimeTrackBundle\Doctrine\ManagedEntityRefresher;
use Nowo\TimeTrackBundle\Doctrine\RecoveringFlusher;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WorkerSafeDoctrineTest extends TestCase
{
    /**
     * Since 1.27.0 (Beacon spec 239): a failed flush is rethrown and the manager is never reset in the middle of the
     * request (it is replaced at the next unit-of-work boundary by the bundle's runner).
     */
    public function testAFailedFlushIsRethrownAndNeverResetsTheManager(): void
    {
        $failure = new RuntimeException('Unique constraint violation');
        $em      = $this->createMock(EntityManagerInterface::class);
        $em->method('flush')->willThrowException($failure);
        $em->method('isOpen')->willReturn(false);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagers')->willReturn(['default' => $em]);
        $registry->expects(self::never())->method('resetManager');

        try {
            RecoveringFlusher::flush($em, $registry);
            self::fail('The flush exception must be rethrown.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
    }

    public function testAnOpenManagerIsFlushed(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        RecoveringFlusher::flush($em);
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
