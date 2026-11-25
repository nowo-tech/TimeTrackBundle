<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Query;

/**
 * Since 2.1.0 (Beacon spec 256): the answer of {@see TimeEntryTotalsQueryInterface::totals()}: the groups, largest first,
 * and whether more groups exist than the limit. Immutable.
 */
final readonly class TimeEntryTotals
{
    /**
     * @param list<TimeEntryTotal> $totals The groups, by seconds (descending), then by key
     * @param bool $truncated Whether more groups matched than the limit
     */
    public function __construct(
        public array $totals,
        public bool $truncated = false,
    ) {
    }
}
