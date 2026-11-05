<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\WorkingTime;

use Nowo\TimeTrackBundle\Entity\BalanceLedgerLine;

/**
 * Since 1.13: everything the pure balance calculator needs (contract §4.4). Local dates are `Y-m-d`.
 */
final readonly class BalanceQuery
{
    /**
     * @param string $from First reported date
     * @param string $to Last reported date
     * @param string $today Local date of today: it and later days are open (excluded from diffs and the balance)
     * @param array<string, CalendarDay> $calendar Calendar of at least [min($from, $balanceFrom), $to]
     * @param array<string, WorkedDay> $worked Worked days of the same range
     * @param list<BalanceLedgerLine> $ledger Ledger lines with an effective date in [min($from, $balanceFrom), $to]
     * @param string $balanceFrom First date the running balance adds (after the cached closed period, or the start date)
     * @param int $openingSeconds Balance before `$balanceFrom` (the last locked period's snapshot, else 0)
     * @param string $source `register` or `task_time`
     * @param string $timezone IANA zone of the dates
     */
    public function __construct(
        public string $from,
        public string $to,
        public string $today,
        public array $calendar,
        public array $worked,
        public array $ledger,
        public string $balanceFrom,
        public int $openingSeconds,
        public OvertimeResult $overtime,
        public string $source,
        public bool $legalRecord,
        public string $timezone = 'UTC',
    ) {
    }
}
