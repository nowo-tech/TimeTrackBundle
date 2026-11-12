<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Repository\RepositoryFactory;
use WeakMap;

use function is_a;

/**
 * Repository factory of TimeTrack's private entity manager (1.23.0, REQ-ARCH-001 rule 6, audit NZ-M1 pattern):
 *
 * - a class whose repository is a DoctrineBundle `ServiceEntityRepository` (an application entity, e.g. the user class)
 *   is delegated to the source manager's factory: that repository is an application service bound to the application's
 *   manager and cannot be built for another one (Doctrine's `DefaultRepositoryFactory` would call its constructor with
 *   the wrong arguments);
 * - every other class gets its repository (the custom class or the default one) built for the asking manager and
 *   memoised per manager in a {@see WeakMap}: it disappears with the private manager it belongs to, so a kept-alive
 *   kernel that replaces the private manager at every boundary does not accumulate repositories.
 *
 * @internal
 */
final class TimeTrackRepositoryFactory implements RepositoryFactory
{
    /** DoctrineBundle's marker interface of service repositories (DoctrineBundle is optional for this class). */
    public const SERVICE_REPOSITORY = ServiceEntityRepositoryInterface::class;

    /** @var WeakMap<EntityManagerInterface, array<string, EntityRepository<object>>> */
    private WeakMap $repositories;

    /**
     * @param RepositoryFactory $source the source manager's factory (asked for service repositories only)
     */
    public function __construct(private readonly RepositoryFactory $source)
    {
        $this->repositories = new WeakMap();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $entityName
     *
     * @return EntityRepository<T>
     */
    public function getRepository(EntityManagerInterface $entityManager, string $entityName): EntityRepository
    {
        $metadata        = $entityManager->getClassMetadata($entityName);
        $repositoryClass = $metadata->customRepositoryClassName ?? $entityManager->getConfiguration()->getDefaultRepositoryClassName();
        if (is_a($repositoryClass, self::SERVICE_REPOSITORY, true)) {
            /** @var EntityRepository<T> $repository */
            $repository = $this->source->getRepository($entityManager, $entityName);

            return $repository;
        }

        /** @var array<string, EntityRepository<object>> $byClass */
        $byClass = $this->repositories[$entityManager] ?? [];
        if (!isset($byClass[$metadata->getName()])) {
            /** @var EntityRepository<object> $built */
            $built                         = new $repositoryClass($entityManager, $metadata);
            $byClass[$metadata->getName()] = $built;
            $this->remember($entityManager, $byClass);
        }

        /** @var EntityRepository<T> $repository */
        $repository = $byClass[$metadata->getName()];

        return $repository;
    }

    /**
     * Keeps the repositories of one manager until the manager is gone.
     *
     * @param array<string, EntityRepository<object>> $repositories
     */
    private function remember(EntityManagerInterface $entityManager, array $repositories): void
    {
        // @igor-ignore - Memo per private manager (WeakMap keyed by it): gone with the manager at the next boundary.
        $this->repositories[$entityManager] = $repositories;
    }
}
