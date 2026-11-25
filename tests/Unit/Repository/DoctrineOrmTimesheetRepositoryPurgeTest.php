<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimesheetRepository;
use PHPUnit\Framework\TestCase;

/**
 * Since 1.22.1 (audit NL): the retention purge owns a DBAL transaction (a savepoint inside a host one) and never
 * flushes, clears or wraps the manager in an ORM transaction.
 */
final class DoctrineOrmTimesheetRepositoryPurgeTest extends TestCase
{
    /**
     * 2.0 (audit ZAR-1): a store built by hand with a manager that is not TimeTrack's private one (the application's)
     * refuses the purge before it touches the manager or its connection.
     */
    public function testAStoreBuiltWithAnotherManagerRefusesBeforeTouchingIt(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('beginTransaction');
        $connection->expects(self::never())->method('rollBack');
        $connection->expects(self::never())->method('executeStatement');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->expects(self::never())->method('createQueryBuilder');
        $em->expects(self::never())->method('flush');
        $em->expects(self::never())->method('clear');
        $em->expects(self::never())->method('close');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('only runs units of work on Nowo\\TimeTrackBundle\\Doctrine\\TimeTrackEntityManager');
        (new DoctrineOrmTimesheetRepository($em))->purgeBefore('2021-01-01');
    }
}
