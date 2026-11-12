<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\DependencyInjection;

use App\Entity\User;
use Nowo\TimeTrackBundle\DependencyInjection\TimeTrackExtension;
use Nowo\TimeTrackBundle\Doctrine\DoctrineUserReferenceResolver;
use Nowo\TimeTrackBundle\Doctrine\FormerPersonSnapshotListener;
use Nowo\TimeTrackBundle\Doctrine\PrivateEntityManagerMiddleware;
use Nowo\TimeTrackBundle\Doctrine\PrivateEntityManagerSubscriber;
use Nowo\TimeTrackBundle\Doctrine\TimeTrackEntityManager;
use Nowo\TimeTrackBundle\Doctrine\TimeTrackManagerRegistry;
use Nowo\TimeTrackBundle\Doctrine\UnitOfWorkBoundaries;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmBalanceLedgerRepository;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmCalendarRepository;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimeEntryRepository;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimesheetRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * 1.23.0 (`database.private_entity_manager`, REQ-ARCH-001 rule 6): off by default (the 1.22 wiring), on it routes every
 * bundle store and the user reference resolver through the private manager on `database.entity_manager`.
 */
final class PrivateEntityManagerConfigTest extends TestCase
{
    public function testOffByDefault(): void
    {
        $container = $this->load([]);

        self::assertFalse($container->hasDefinition(TimeTrackEntityManager::class));
        self::assertFalse($container->hasParameter(TimeTrackExtension::PRIVATE_ENTITY_MANAGER_SOURCE_PARAMETER));
        self::assertSame('doctrine.orm.default_entity_manager', (string) $container->getDefinition(DoctrineOrmTimeEntryRepository::class)->getArgument('$entityManager'));
        self::assertSame('doctrine', (string) $container->getDefinition(DoctrineOrmTimeEntryRepository::class)->getArgument('$managerRegistry'));
        self::assertNull($container->getDefinition(FormerPersonSnapshotListener::class)->getArgument('$privateEntityManager'));
    }

    public function testOnRoutesTheStoresThroughThePrivateManager(): void
    {
        $container = $this->load(['database' => ['entity_manager' => 'time', 'private_entity_manager' => true]]);

        self::assertSame('time', $container->getParameter(TimeTrackExtension::PRIVATE_ENTITY_MANAGER_SOURCE_PARAMETER));
        self::assertSame('time', $container->getDefinition(TimeTrackEntityManager::class)->getArgument(1));
        self::assertTrue($container->getDefinition(TimeTrackEntityManager::class)->hasTag('kernel.reset'));
        self::assertSame('time', $container->getDefinition(TimeTrackManagerRegistry::class)->getArgument(2));
        self::assertTrue($container->getDefinition(UnitOfWorkBoundaries::class)->hasTag('kernel.event_subscriber'));
        self::assertTrue($container->getDefinition(PrivateEntityManagerSubscriber::class)->hasTag('kernel.event_subscriber'));
        self::assertTrue($container->getDefinition(PrivateEntityManagerMiddleware::class)->hasTag('doctrine.middleware'));
        foreach ([DoctrineOrmTimeEntryRepository::class, DoctrineOrmTimesheetRepository::class, DoctrineOrmBalanceLedgerRepository::class, DoctrineOrmCalendarRepository::class] as $repository) {
            self::assertSame(TimeTrackEntityManager::class, (string) $container->getDefinition($repository)->getArgument('$entityManager'), $repository);
            self::assertSame(TimeTrackManagerRegistry::class, (string) $container->getDefinition($repository)->getArgument('$managerRegistry'), $repository);
        }
        self::assertSame(TimeTrackEntityManager::class, (string) $container->getDefinition(DoctrineUserReferenceResolver::class)->getArgument('$entityManager'));
        self::assertSame(TimeTrackEntityManager::class, (string) $container->getDefinition(FormerPersonSnapshotListener::class)->getArgument('$privateEntityManager'));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);
        (new TimeTrackExtension())->load([['user_class' => User::class] + $config], $container);

        return $container;
    }
}
