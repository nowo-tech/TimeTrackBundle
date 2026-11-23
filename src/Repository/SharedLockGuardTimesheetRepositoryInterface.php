<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use Nowo\TimeTrackBundle\Entity\Timesheet;

/**
 * Since 1.40.1 (audit ZAN-1): optional store capability — {@see TimesheetRepositoryInterface::findGuarding()} read under a
 * shared lock inside the caller's transaction, with the rows read fresh, so the period lock of a write is decided on what
 * is committed now, not on an earlier REPEATABLE READ snapshot of the host's transaction (MySQL, MariaDB). The Doctrine
 * store implements it.
 */
interface SharedLockGuardTimesheetRepositoryInterface
{
    /**
     * Locked or submitted timesheets of the owner overlapping the local dates (one scope or, null, every scope), under a
     * shared lock when a transaction is open (a plain read otherwise), refreshed from the database.
     *
     * @return list<Timesheet>
     */
    public function findGuardingShared(string $ownerId, ?string $scopeKey, string $fromDate, string $toDate): array;
}
