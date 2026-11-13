<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\TimeEntry;

/**
 * Since 1.25.0 (Beacon spec 234): optional store capability — the entries of some users in a period, filtered by channel
 * and by the service-account mark, so a host tells agents' time from people's time without reading raw metadata. The
 * Doctrine store implements it (index `idx_time_track_entries_origin` on `service_account, channel, started_at`, on top
 * of the user and period conditions of {@see PaginatedTimeEntryRepositoryInterface}). Framework-free.
 */
interface OriginFilteredTimeEntryRepositoryInterface
{
    /**
     * One page of the users' entries started within the period that pass the filter, newest first.
     *
     * @param list<string> $userIds User ids (none: no entry)
     *
     * @return list<TimeEntry>
     */
    public function findPageByUsersPeriodAndOrigin(array $userIds, DateTimeImmutable $from, DateTimeImmutable $to, TimeEntryOriginFilter $filter, int $offset, int $limit): array;

    /**
     * Number of the users' entries started within the period that pass the filter.
     *
     * Audit PA-4: the count is a SQL `COUNT` on the same conditions, so it does **not** repeat the page's PHP re-check of
     * the channel. For every value the bundle writes the two agree (a channel is `[a-z0-9_]{1,16}`, which no collation
     * folds). They can differ only for rows written by hand outside the bundle, whose channel differs from the filter's
     * by case, accent or trailing space on a folding collation: the count may then include a row the page leaves out.
     *
     * @param list<string> $userIds User ids (none: 0)
     */
    public function countByUsersPeriodAndOrigin(array $userIds, DateTimeImmutable $from, DateTimeImmutable $to, TimeEntryOriginFilter $filter): int;
}
