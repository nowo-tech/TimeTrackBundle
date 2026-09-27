<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Throwable;

/**
 * Flushes an EntityManager and reopens it when the flush fails and closes it.
 *
 * Without a kernel reset between requests (long-running workers) a closed manager would stay
 * closed for every later request handled by the same process.
 */
final class RecoveringFlusher
{
    public static function flush(EntityManagerInterface $entityManager, ?ManagerRegistry $managerRegistry): void
    {
        try {
            $entityManager->flush();
        } catch (Throwable $exception) {
            self::resetIfClosed($entityManager, $managerRegistry);

            throw $exception;
        }
    }

    public static function resetIfClosed(EntityManagerInterface $entityManager, ?ManagerRegistry $managerRegistry): void
    {
        if ($managerRegistry === null || $entityManager->isOpen()) {
            return;
        }

        foreach ($managerRegistry->getManagers() as $name => $manager) {
            if ($manager === $entityManager) {
                $managerRegistry->resetManager($name);

                return;
            }
        }
    }
}
