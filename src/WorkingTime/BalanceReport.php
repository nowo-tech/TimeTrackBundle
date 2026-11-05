<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\WorkingTime;

/**
 * Since 1.13: the hours balance of a range (contract §4.4): per day, per ISO week and in total, the running balance at
 * the end of the range, the ledger lines of the range, the source (`register` / `task_time`), whether it is the legal
 * working-time record, the overtime policy and the annual overtime counter.
 */
final readonly class BalanceReport
{
    /**
     * @param list<array{date: string, kind: string, label: string|null, expectedSeconds: int, workedSeconds: int, diffSeconds: int, overtimeSeconds: int, flags: list<string>}> $days
     * @param list<array{key: string, expectedSeconds: int, workedSeconds: int, diffSeconds: int, overtimeSeconds: int}> $weeks
     * @param array{expectedSeconds: int, workedSeconds: int, diffSeconds: int, overtimeSeconds: int} $totals
     * @param list<array<string, mixed>> $ledger
     * @param array{id: string, version: string} $policy
     * @param array{year: int, ratifiedSeconds: int, compensatedSeconds: int, countedSeconds: int, capSeconds: int, flags: list<string>} $overtime
     */
    public function __construct(
        public string $from,
        public string $to,
        public string $timezone,
        public array $days,
        public array $weeks,
        public array $totals,
        public int $balanceSeconds,
        public array $ledger,
        public string $source,
        public bool $legalRecord,
        public array $policy,
        public array $overtime,
    ) {
    }

    /**
     * Client API shape (`GET balance`).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'from'           => $this->from,
            'to'             => $this->to,
            'timezone'       => $this->timezone,
            'source'         => $this->source,
            'legalRecord'    => $this->legalRecord,
            'policy'         => $this->policy,
            'days'           => $this->days,
            'weeks'          => $this->weeks,
            'totals'         => $this->totals,
            'balanceSeconds' => $this->balanceSeconds,
            'ledger'         => $this->ledger,
            'overtime'       => $this->overtime,
        ];
    }

    /**
     * Count of each flag over the days (snapshot `flags`).
     *
     * @return array<string, int>
     */
    public function flagCounts(): array
    {
        $counts = [];
        foreach ($this->days as $day) {
            foreach ($day['flags'] as $flag) {
                $counts[$flag] = ($counts[$flag] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
