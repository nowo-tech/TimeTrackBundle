<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use Nowo\TimeTrackBundle\Entity\TimeEntry;

/**
 * Since 1.21 (audit MV-T-L1): optional store capability on top of {@see FindableTimeEntryRepositoryInterface} — the entry
 * re-read with a row lock inside the caller's transaction, as {@see LockingGuardTimesheetRepositoryInterface} does for
 * timesheets. A store without it is re-read with {@see FindableTimeEntryRepositoryInterface::findById()} instead (no row
 * lock). The Doctrine store implements it.
 */
interface LockingFindableTimeEntryRepositoryInterface extends FindableTimeEntryRepositoryInterface
{
    /**
     * The entry with this id re-read from the store with a write lock (`SELECT … FOR UPDATE`), inside the caller's
     * transaction; null when it is gone.
     */
    public function findForUpdate(string $id): ?TimeEntry;
}
