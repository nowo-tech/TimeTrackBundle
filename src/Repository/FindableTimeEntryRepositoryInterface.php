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
}
