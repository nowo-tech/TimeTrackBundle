<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Query;

/**
 * Since 2.1.0 (Beacon spec 256): one group of {@see TimeEntryTotals}: its key, the summed seconds and the number of
 * entries. Immutable.
 */
final readonly class TimeEntryTotal
{
    /**
     * @param TimeEntryGroupKey $key The group
     * @param int $seconds Tracked seconds of the group
     * @param int $entries Entries of the group
     */
    public function __construct(
        public TimeEntryGroupKey $key,
        public int $seconds,
        public int $entries,
    ) {
    }
}
