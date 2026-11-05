<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TimeTrackBundle\Entity\CalendarConnection;
use Nowo\TimeTrackBundle\Enum\CalendarConnectionMode;
use Nowo\TimeTrackBundle\Enum\CalendarProvider;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmCalendarRepository;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Since 1.14: a failed connection delete is rethrown after the closed manager is reset (worker mode).
 */
final class DoctrineOrmCalendarRepositoryTest extends TestCase
{
    public function testAFailedDeleteResetsTheClosedManager(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willThrowException(new RuntimeException('deadlock'));
        $em->method('isOpen')->willReturn(false);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagers')->willReturn(['default' => $em]);
        $registry->expects(self::once())->method('resetManager')->with('default');
        $connection = new CalendarConnection(new TestUser('1', 'u@example.com'), 'org-a', CalendarProvider::Google, CalendarConnectionMode::Local, null, 'v1', new DateTimeImmutable());

        $this->expectExceptionObject(new RuntimeException('deadlock'));
        (new DoctrineOrmCalendarRepository($em, $registry))->deleteConnection($connection);
    }
}
