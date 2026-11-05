<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use Nowo\TimeTrackBundle\Entity\TimesheetEvent;

/**
 * Since 1.13: the timesheet audit trail. Append and read only: there is deliberately no update or delete method (the
 * retention purge removes rows with their timesheet, {@see TimesheetRetentionRepositoryInterface}).
 */
interface TimesheetEventRepositoryInterface
{
    /**
     * Persists a new row (flushed by the surrounding transaction, {@see TimesheetRepositoryInterface::transactional()}).
     */
    public function append(TimesheetEvent $event): void;

    /**
     * Rows of a timesheet in chain order (`seq` ascending).
     *
     * @return list<TimesheetEvent>
     */
    public function findByTimesheet(string $timesheetId): array;

    /**
     * The last row of a timesheet (highest `seq`).
     */
    public function findLast(string $timesheetId): ?TimesheetEvent;
}
