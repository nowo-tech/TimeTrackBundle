<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use Nowo\TimeTrackBundle\Entity\Timesheet;

/**
 * Since 1.15: optional store capability for the period lock of a deleted user. Their timesheets lose `user_id` with the
 * account and keep `owner_ref`, so {@see TimesheetRepositoryInterface::findGuarding()} no longer finds them. Without this
 * capability the guard treats a deleted user's entries as locked. The Doctrine store implements it.
 */
interface FormerOwnerTimesheetRepositoryInterface
{
    /**
     * Timesheets with this `owner_ref` that are locked or submitted and overlap the local dates, in one scope or (null) in
     * every scope.
     *
     * @return list<Timesheet>
     */
    public function findGuardingByOwnerRef(string $ownerRef, ?string $scopeKey, string $fromDate, string $toDate): array;
}
