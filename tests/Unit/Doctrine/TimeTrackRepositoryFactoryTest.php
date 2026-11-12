<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Repository\RepositoryFactory;
use Nowo\TimeTrackBundle\Doctrine\TimeTrackRepositoryFactory;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use WeakMap;

/**
 * 1.23.0 (audit NZ-M1 pattern): the private manager's repository factory delegates DoctrineBundle service repositories
 * to the source factory and builds the others per manager, dropped with it.
 */
final class TimeTrackRepositoryFactoryTest extends TestCase
{
    public function testAServiceRepositoryComesFromTheSourceFactory(): void
    {
        $manager = $this->manager(AppUserServiceRepository::class);
        $service = $this->createStub(EntityRepository::class);
        $source  = $this->createMock(RepositoryFactory::class);
        $source->expects(self::once())->method('getRepository')->with($manager, AppUser::class)->willReturn($service);

        self::assertSame($service, (new TimeTrackRepositoryFactory($source))->getRepository($manager, AppUser::class));
    }

    public function testOtherRepositoriesAreBuiltAndMemoisedPerManager(): void
    {
        $source = $this->createMock(RepositoryFactory::class);
        $source->expects(self::never())->method('getRepository');
        $factory = new TimeTrackRepositoryFactory($source);
        $first   = $this->manager(null);
        $second  = $this->manager(null);

        $repository = $factory->getRepository($first, AppUser::class);
        self::assertSame(EntityRepository::class, $repository::class);
        self::assertSame($repository, $factory->getRepository($first, AppUser::class));
        self::assertNotSame($repository, $factory->getRepository($second, AppUser::class), 'one per manager');

        $memo = (new ReflectionProperty(TimeTrackRepositoryFactory::class, 'repositories'))->getValue($factory);
        self::assertInstanceOf(WeakMap::class, $memo, 'keyed weakly by manager: dropped with it');
        self::assertCount(2, $memo);
    }

    /**
     * @param class-string<EntityRepository<AppUser>>|null $repositoryClass
     */
    private function manager(?string $repositoryClass): EntityManagerInterface
    {
        $metadata                            = new ClassMetadata(AppUser::class);
        $metadata->customRepositoryClassName = $repositoryClass;
        $configuration                       = new Configuration();
        $manager                             = $this->createStub(EntityManagerInterface::class);
        $manager->method('getClassMetadata')->willReturn($metadata);
        $manager->method('getConfiguration')->willReturn($configuration);

        return $manager;
    }
}
