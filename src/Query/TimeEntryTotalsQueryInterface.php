<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Query;

use InvalidArgumentException;

/**
 * Since 2.1.0 (Beacon spec 256): bounded grouping of the time entries (seconds and entry count per subject, activity
 * type, owner, task or allocation). A new port rather than a method on {@see TimeEntryQueryInterface} (adding a method to
 * an interface a host may decorate would break it in a minor). Public service
 * (`Nowo\TimeTrackBundle\Query\TimeEntryTotalsQueryInterface`).
 *
 * Bounded: the criteria must carry a key (as every query) **and** a closed start range (`startedFrom` and `startedTo`) of
 * at most {@see self::MAX_RANGE_DAYS} days; one SQL statement (no OFFSET, no follow-up query per group), served by the
 * tenant indexes for scope queries and by the owner index for owner queries. Like the list port it applies **no access
 * rule**: pass the owners and scopes the viewer may see, and label subject groups with `TimeSubjectLabelResolver` (one
 * `describe()` per type). Group keys are checked again byte for byte in PHP. Stateless.
 */
interface TimeEntryTotalsQueryInterface
{
    /** Most groups one call returns. */
    public const MAX_GROUPS = 1000;

    /** Longest start range, in days. */
    public const MAX_RANGE_DAYS = 366;

    /**
     * The totals of the matching entries per group, largest first; `truncated` when more groups matched than `$limit`.
     *
     * @param int $limit Most groups (1 to {@see self::MAX_GROUPS})
     *
     * @throws InvalidArgumentException When the start range is not closed or longer than {@see self::MAX_RANGE_DAYS}
     *                                  days, or the limit is out of range
     */
    public function totals(TimeEntryCriteria $criteria, TimeEntryGrouping $groupBy, int $limit = 200): TimeEntryTotals;
}
