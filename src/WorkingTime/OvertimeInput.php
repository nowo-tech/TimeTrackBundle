<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\WorkingTime;

use Nowo\TimeTrackBundle\Entity\BalanceLedgerLine;

/**
 * Since 1.13: what an overtime policy evaluates: calendar and worked days (whole ISO weeks), the local date of today
 * (days from today on are open and never counted) and the ledger lines of the year (ratified, compensated, reversals).
 */
final readonly class OvertimeInput
{
    /**
     * @param array<string, CalendarDay> $calendar Keyed by local date, in order
     * @param array<string, WorkedDay> $worked Keyed by local date
     * @param string $today Local date of today (Y-m-d)
     * @param bool $legalRecord Whether the worked days come from a working-time register (break flags need breaks)
     * @param list<BalanceLedgerLine> $ledger Ledger lines of the annual counter
     */
    public function __construct(
        public array $calendar,
        public array $worked,
        public string $today,
        public bool $legalRecord,
        public array $ledger = [],
    ) {
    }
}
