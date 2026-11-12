<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimesheetRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Since 1.22.1 (audit NL): the retention purge owns a DBAL transaction (a savepoint inside a host one) and never
 * flushes, clears or wraps the manager in an ORM transaction.
 */
final class DoctrineOrmTimesheetRepositoryPurgeTest extends TestCase
{
    public function testAFailedPurgeRollsBackItsOwnTransactionWithoutFlushingOrClearing(): void
    {
        $failure    = new RuntimeException('Query failed');
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::never())->method('commit');
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::once())->method('rollBack');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('createQueryBuilder')->willThrowException($failure);
        $em->expects(self::never())->method('flush');
        $em->expects(self::never())->method('clear');
        $em->expects(self::never())->method('wrapInTransaction');
        $em->expects(self::never())->method('detach');

        $this->expectExceptionObject($failure);
        (new DoctrineOrmTimesheetRepository($em))->purgeBefore('2021-01-01');
    }

    public function testAFailureAfterTheTransactionEndedIsRethrownWithoutRollback(): void
    {
        $failure    = new RuntimeException('Connection lost');
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->expects(self::never())->method('rollBack');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('createQueryBuilder')->willThrowException($failure);

        $this->expectExceptionObject($failure);
        (new DoctrineOrmTimesheetRepository($em))->purgeBefore('2021-01-01');
    }
}
