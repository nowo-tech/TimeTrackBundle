<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\TimeEntry;

/**
 * Time entry repository that pages in the database (REQ-PERF-001). Optional: TimerService::listEntriesPage() slices
 * {@see TimeEntryRepositoryInterface::findByUserAndPeriod()} in memory for repositories that do not implement it.
 */
interface PaginatedTimeEntryRepositoryInterface extends TimeEntryRepositoryInterface
{
    /**
     * Entries of the users started within the period, newest first.
     *
     * @param list<string> $userIds User ids
     * @param int $offset Rows to skip
     * @param int $limit Maximum rows
     *
     * @return list<TimeEntry>
     */
    public function findPageByUsersAndPeriod(array $userIds, DateTimeImmutable $from, DateTimeImmutable $to, int $offset, int $limit): array;

    /**
     * Number of entries of the users started within the period.
     *
     * @param list<string> $userIds User ids
     */
    public function countByUsersAndPeriod(array $userIds, DateTimeImmutable $from, DateTimeImmutable $to): int;
}
