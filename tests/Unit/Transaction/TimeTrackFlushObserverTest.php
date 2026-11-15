<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Transaction;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use Nowo\TimeTrackBundle\Transaction\TimeTrackFlushObserver;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * 1.27.0 (audit QO-M1): the observer records a flush of the watched manager that writes an application entity.
 */
final class TimeTrackFlushObserverTest extends TestCase
{
    public function testAFlushOfAnotherManagerIsIgnored(): void
    {
        $watched = $this->createMock(UnitOfWork::class);
        $other   = $this->createMock(UnitOfWork::class);
        $other->expects(self::never())->method('getScheduledEntityInsertions');
        $observer = new TimeTrackFlushObserver($watched);

        $observer->onFlush(new OnFlushEventArgs($this->manager($other)));

        self::assertFalse($observer->hostWritten);
    }

    public function testACollectionChangeIsTheApplications(): void
    {
        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->method('getScheduledCollectionUpdates')->willReturn([]);
        $unitOfWork->method('getScheduledCollectionDeletions')->willReturn([new stdClass()]);
        $observer = new TimeTrackFlushObserver($unitOfWork);

        $observer->onFlush(new OnFlushEventArgs($this->manager($unitOfWork)));

        self::assertTrue($observer->hostWritten);
    }

    private function manager(UnitOfWork $unitOfWork): EntityManagerInterface
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getUnitOfWork')->willReturn($unitOfWork);

        return $manager;
    }
}
