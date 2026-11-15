<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Transaction;

use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use Nowo\TimeTrackBundle\Doctrine\TimeTrackManagerRegistry;

/**
 * 1.27.0 (audit QO-M1, after WikiBundle's QN-M1): watches the flushes of one unit of work of
 * {@see TimeTrackTransactionRunner} on the shared entity manager and records whether one of them wrote an entity of the
 * application (not a TimeTrack row). Such a write is undone by a rollback of the unit while Doctrine keeps it as written,
 * so the runner then closes the manager (loud) instead of leaving the application's rows managed but lost.
 *
 * One instance per unit, registered on the manager's event manager for the unit only.
 *
 * @internal
 */
final class TimeTrackFlushObserver
{
    /** A flush of the unit wrote (inserted, updated, deleted, or changed a collection of) an application entity. */
    public bool $hostWritten = false;

    /**
     * @param UnitOfWork $unitOfWork The unit of work of the watched manager (flushes of other managers are ignored)
     */
    public function __construct(private readonly UnitOfWork $unitOfWork)
    {
    }

    /** Doctrine `onFlush`: what this flush is about to write. */
    public function onFlush(OnFlushEventArgs $args): void
    {
        $unitOfWork = $args->getObjectManager()->getUnitOfWork();
        if ($unitOfWork !== $this->unitOfWork) {
            return;
        }
        // The TimeTrack entities have no to-many associations: a collection change is always the application's.
        if ($unitOfWork->getScheduledCollectionUpdates() !== [] || $unitOfWork->getScheduledCollectionDeletions() !== []) {
            // @igor-ignore - One observer per unit of work, created and dropped by the runner: never a shared service.
            $this->hostWritten = true;

            return;
        }
        foreach ([...$unitOfWork->getScheduledEntityInsertions(), ...$unitOfWork->getScheduledEntityUpdates(), ...$unitOfWork->getScheduledEntityDeletions()] as $entity) {
            if (!TimeTrackManagerRegistry::isBundleEntity($entity::class)) {
                // @igor-ignore - One observer per unit of work, created and dropped by the runner: never a shared service.
                $this->hostWritten = true;

                return;
            }
        }
    }
}
