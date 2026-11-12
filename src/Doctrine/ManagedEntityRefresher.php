<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

use function array_values;

/**
 * Reloads a managed entity from the database before a security decision.
 *
 * In a long-running worker without kernel reset, the identity map keeps entities loaded by
 * earlier requests (e.g. a user whose roles were changed since).
 */
final class ManagedEntityRefresher
{
    /**
     * Refreshes a managed entity from the database (fresh roles in worker mode); no-op when no open manager manages it.
     *
     * Since 1.23.1 the manager is the one that actually contains the entity: the registry's manager for the class first,
     * then every manager. DoctrineBundle 3.4 can answer the default manager for a class mapped only on a dedicated one,
     * which skipped the refresh (stale roles in a kept-alive worker).
     */
    public static function refresh(?ManagerRegistry $managerRegistry, object $entity): void
    {
        if (!$managerRegistry instanceof ManagerRegistry) {
            return;
        }

        foreach ([$managerRegistry->getManagerForClass($entity::class), ...array_values($managerRegistry->getManagers())] as $entityManager) {
            if ($entityManager instanceof EntityManagerInterface && $entityManager->isOpen() && $entityManager->contains($entity)) {
                $entityManager->refresh($entity);

                return;
            }
        }
    }
}
