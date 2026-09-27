<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Reloads a managed entity from the database before a security decision.
 *
 * In a long-running worker without kernel reset, the identity map keeps entities loaded by
 * earlier requests (e.g. a user whose roles were changed since).
 */
final class ManagedEntityRefresher
{
    public static function refresh(?ManagerRegistry $managerRegistry, object $entity): void
    {
        $entityManager = $managerRegistry?->getManagerForClass($entity::class);
        if (!$entityManager instanceof EntityManagerInterface || !$entityManager->isOpen() || !$entityManager->contains($entity)) {
            return;
        }

        $entityManager->refresh($entity);
    }
}
