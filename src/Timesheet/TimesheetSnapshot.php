<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Timesheet;

use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\WorkingTime\BalanceReport;

/**
 * Since 1.13: the entries of a timesheet period and its snapshot (contract §5.8): `totals`, the canonical entries hash
 * and, when working time is on, the balance report of the period.
 */
final readonly class TimesheetSnapshot
{
    /**
     * @param list<TimeEntry> $entries Entries of the owner, scope and period (live and imported)
     * @param list<TimeEntry> $unassigned The owner's entries of the period without a scope (shown to the owner only)
     * @param array<string, mixed> $totals
     */
    public function __construct(
        public array $entries,
        public array $unassigned,
        public array $totals,
        public string $entriesHash,
        public ?BalanceReport $balance,
    ) {
    }
}
