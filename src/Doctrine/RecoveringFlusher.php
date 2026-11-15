<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TimeTrackBundle\Transaction\TimeTrackTransactionRunner;

/**
 * Flushes the entity manager of the TimeTrack stores.
 *
 * Up to 1.26 a flush that failed and closed the manager reset it at once through the registry. With the default
 * (shared) mode that manager is the application's: resetting it in the middle of a request detached every entity the
 * application had loaded, and their later changes were silently never written. Since 1.27.0 (Beacon spec 239) the
 * bundle never resets the application's manager in the middle of a request: a manager a failed TimeTrack flush closed
 * stays closed for the rest of the request (the application's next flush fails loudly), and the bundle's transaction
 * runner replaces it at the next request, message or command boundary ({@see TimeTrackTransactionRunner::reset()}), so
 * a long-running worker never serves a later request with it. The private manager replaces itself when it was closed.
 */
final class RecoveringFlusher
{
    /**
     * Flushes the entity manager.
     *
     * @param ManagerRegistry|null $managerRegistry Unused since 1.27.0 (kept for compatibility)
     */
    public static function flush(EntityManagerInterface $entityManager, ?ManagerRegistry $managerRegistry = null): void
    {
        // The bundle's recovering helper: a closed manager is replaced at the next unit-of-work boundary (class description).
        $entityManager->flush(); // @phpstan-ignore frankenphp.worker.noFlushWithoutClosedManagerRecovery
    }

    /**
     * Since 1.27.0 a no-op: a closed manager is replaced at the next unit-of-work boundary, never in the middle of a
     * request (see the class description).
     *
     * @deprecated since 1.27.0, nothing to do; removed in 2.0
     *
     * @param ManagerRegistry|null $managerRegistry Unused since 1.27.0 (kept for compatibility)
     */
    public static function resetIfClosed(EntityManagerInterface $entityManager, ?ManagerRegistry $managerRegistry = null): void
    {
    }
}
