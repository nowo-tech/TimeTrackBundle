<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Flushes the entity manager of the TimeTrack stores.
 *
 * Since 2.0 the stores always hold TimeTrack's private entity manager ({@see TimeTrackEntityManager}): the bundle never
 * flushes, clears, closes or resets the application's manager. A private manager a failed flush closed replaces itself
 * (at the next use, and in any case at the next request, message or command boundary), so a long-running worker never
 * serves a later request with it.
 */
final class RecoveringFlusher
{
    /**
     * Flushes the entity manager.
     *
     * @param ManagerRegistry|null $managerRegistry Unused since 1.27.0 (kept for compatibility)
     * @param object|null $hostFlushProbe @deprecated since 2.0, ignored (the 1.33 host-flush probe of the removed shared manager); removed in 3.0
     */
    public static function flush(EntityManagerInterface $entityManager, ?ManagerRegistry $managerRegistry = null, ?object $hostFlushProbe = null): void
    {
        // The bundle's recovering helper: a closed private manager is replaced (class description).
        $entityManager->flush(); // @phpstan-ignore frankenphp.worker.noFlushWithoutClosedManagerRecovery
    }
}
