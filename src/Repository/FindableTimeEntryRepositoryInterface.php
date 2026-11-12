<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Service\TimeEntryBillableService;

/**
 * Since 1.21: optional store capability — one entry by id (`PATCH entries/{id}`, {@see TimeEntryBillableService::changeById()}).
 * A store without it answers every lookup as not found.
 */
interface FindableTimeEntryRepositoryInterface
{
    /**
     * The entry with this id, or null.
     */
    public function findById(string $id): ?TimeEntry;

    /**
     * The entry with this id re-read from the store with a write lock (`SELECT … FOR UPDATE`), inside the caller's
     * transaction; null when it is gone. Audit MV-T-L1: the checks of a change run on what this returns.
     */
    public function findForUpdate(string $id): ?TimeEntry;
}
