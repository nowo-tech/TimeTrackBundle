<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TimeTrackBundle\Doctrine\ManagedEntityRefresher;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;

use function dirname;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * 1.23.1: the refresh before a role check finds the manager that actually holds the user — also when the user lives in a
 * dedicated, non-default entity manager and the registry answers the default one for its class (DoctrineBundle 3.4;
 * this test runs on whatever DoctrineBundle the CI cell installs: 2.x with Symfony 7.4, 3.x with Symfony 8.1).
 */
final class ManagedEntityRefresherTest extends TestCase
{
    public function testTheUserOfADedicatedManagerIsRefreshedThroughTheDoctrineBundleRegistry(): void
    {
        $default   = $this->manager([]);
        $users     = $this->manager([dirname(__DIR__, 2) . '/Fixtures/FunctionalEntity']);
        $container = new Container();
        $container->set('doctrine.dbal.default_connection', $default->getConnection());
        $container->set('doctrine.orm.default_entity_manager', $default);
        $container->set('doctrine.orm.users_entity_manager', $users);
        $registry = new Registry($container, ['default' => 'doctrine.dbal.default_connection'], ['default' => 'doctrine.orm.default_entity_manager', 'users' => 'doctrine.orm.users_entity_manager'], 'default', 'default');

        $user = $this->staleUser($users);
        ManagedEntityRefresher::refresh($registry, $user);

        self::assertSame(['ROLE_USER', 'ROLE_ADMIN'], $user->getRoles());
    }

    public function testAManagerForTheClassThatDoesNotHoldTheUserIsSkipped(): void
    {
        // What DoctrineBundle 3.4 can answer: the default manager for a class mapped only on another one.
        $users  = $this->manager([dirname(__DIR__, 2) . '/Fixtures/FunctionalEntity']);
        $user   = $this->staleUser($users);
        $wrong  = $this->manager([dirname(__DIR__, 2) . '/Fixtures/FunctionalEntity']);
        $closed = $this->manager([dirname(__DIR__, 2) . '/Fixtures/FunctionalEntity']);
        $closed->close();
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($wrong);
        $registry->method('getManagers')->willReturn(['closed' => $closed, 'default' => $wrong, 'users' => $users]);

        ManagedEntityRefresher::refresh($registry, $user);

        self::assertSame(['ROLE_USER', 'ROLE_ADMIN'], $user->getRoles());
    }

    public function testNothingHappensWithoutARegistryOrAManagerHoldingTheEntity(): void
    {
        $users = $this->manager([dirname(__DIR__, 2) . '/Fixtures/FunctionalEntity']);
        $user  = $this->staleUser($users);
        $users->clear();

        ManagedEntityRefresher::refresh(null, $user);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn(null);
        $registry->method('getManagers')->willReturn(['users' => $users]);
        ManagedEntityRefresher::refresh($registry, $user);

        self::assertSame(['ROLE_USER'], $user->getRoles(), 'a detached user is left as it is');
    }

    /**
     * A user loaded by the manager, then promoted in the database behind its back.
     */
    private function staleUser(EntityManagerInterface $users): AppUser
    {
        (new SchemaTool($users))->createSchema($users->getMetadataFactory()->getAllMetadata());
        $user = new AppUser('ana@example.com', 'secret');
        $users->persist($user);
        $users->flush();
        $users->getConnection()->executeStatement('UPDATE app_user SET roles = ?', ['["ROLE_USER","ROLE_ADMIN"]']);
        self::assertSame(['ROLE_USER'], $user->getRoles());

        return $user;
    }

    /**
     * @param list<string> $paths
     */
    private function manager(array $paths): EntityManager
    {
        $config = ORMSetup::createAttributeMetadataConfiguration($paths, true, sys_get_temp_dir() . '/nowo_time_track_refresher_proxies');
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }

        return new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
    }
}
