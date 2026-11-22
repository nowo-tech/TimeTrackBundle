<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use Nowo\TimeTrackBundle\Entity\TimeEntry;

/**
 * Since 1.36.0 (Beacon spec 250): optional store capability for listings where an entry without a task of another
 * owner is kept only in one of the viewer's tenant scopes ({@see TimeEntryListing}). The condition is part of the query
 * (exact SQL compare), and every row is checked again in PHP with `===` before the page is cut or a row counted, so a
 * look-alike scope neither shows nor shifts the pages. The Doctrine store implements it; a store without it is filtered
 * in memory by the timer service.
 */
interface TasklessScopedTimeEntryRepositoryInterface
{
    /**
     * One page of the listing, newest first.
     *
     * @return list<TimeEntry>
     */
    public function findListingPage(TimeEntryListing $listing, int $offset, int $limit): array;

    /**
     * Number of entries of the listing.
     */
    public function countListing(TimeEntryListing $listing): int;

    /**
     * Whether the listing (its owners, ownerless scopes, period and sources) reaches any entry without a task. False lets
     * the caller keep its faster listing, which is then exactly the same.
     */
    public function hasEntriesWithoutTask(TimeEntryListing $listing): bool;
}
