<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use Nowo\TimeTrackBundle\Entity\Timesheet;

/**
 * Since 1.16: optional store capability: the timesheets that block a move, read under a shared lock inside the move's
 * transaction. Without it the guard reads as usual: on MySQL and MariaDB a period locked during a move can then go unseen until
 * it ends. The Doctrine store implements it.
 */
interface LockingGuardTimesheetRepositoryInterface
{
    /**
     * Timesheets with this `owner_ref` that are locked or submitted and overlap the local dates, in one scope or (null) in
     * every scope, under a shared lock.
     *
     * @return list<Timesheet>
     */
    public function findGuardingForMove(string $ownerRef, ?string $scopeKey, string $fromDate, string $toDate): array;
}
