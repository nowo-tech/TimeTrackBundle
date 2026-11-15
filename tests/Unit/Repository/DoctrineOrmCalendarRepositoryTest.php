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
    /**
     * Since 1.27.0 (Beacon spec 239): the delete is a unit of work of the bundle's runner; a failure is rethrown and the
     * manager is never reset in the middle of the request (up to 1.26 the closed manager was reset through the registry).
     */
    public function testAFailedDeleteNeverResetsTheManager(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('flush')->willThrowException(new RuntimeException('deadlock'));
        $em->method('isOpen')->willReturn(false);
        $em->expects(self::never())->method('wrapInTransaction');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagers')->willReturn(['default' => $em]);
        $registry->expects(self::never())->method('resetManager');
        $connection = new CalendarConnection(new TestUser('1', 'u@example.com'), 'org-a', CalendarProvider::Google, CalendarConnectionMode::Local, null, 'v1', new DateTimeImmutable());

        $this->expectExceptionObject(new RuntimeException('deadlock'));
        (new DoctrineOrmCalendarRepository($em, $registry))->deleteConnection($connection);
    }
}
