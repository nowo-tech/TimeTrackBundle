<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\WorkingTime;

use Nowo\TimeTrackBundle\Entity\BalanceLedgerLine;
use Nowo\TimeTrackBundle\Enum\TimesheetPeriodKind;
use Nowo\TimeTrackBundle\Timesheet\Period;

use function array_merge;
use function array_unique;
use function array_values;

/**
 * Since 1.13: the balance engine (contract §4.1, §4.4), a pure function of its query (no I/O, no state):
 *
 * - `diff(d) = worked(d) − expected(d)` for closed days only (before today in the user's zone); today and later days
 *   show expected and partial worked time with the flag `open_day`; `none` days count nowhere.
 * - `balance = opening + Σ diff(d) for balanceFrom ≤ d ≤ to + Σ ledger seconds` of the lines that move the balance
 *   (`opening`, `adjustment`, `overtime_paid`, reversals of those) with an effective date in that range. Ratified and
 *   compensated overtime only feed the overtime counter.
 * - Weekly overtime is reported in the week it belongs to, and counted in the totals when that week ends in the range.
 *
 * No rounding: display rounding (minutes) is a client concern.
 */
final readonly class WorkingTimeBalanceCalculator
{
    /**
     * Computes the report.
     */
    public function compute(BalanceQuery $query): BalanceReport
    {
        $days   = [];
        $weeks  = [];
        $totals = ['expectedSeconds' => 0, 'workedSeconds' => 0, 'diffSeconds' => 0, 'overtimeSeconds' => 0];
        foreach ($query->calendar as $date => $day) {
            $date = (string) $date;
            if ($date < $query->from || $date > $query->to) {
                continue;
            }
            $worked   = $query->worked[$date] ?? null;
            $seconds  = $worked->workedSeconds ?? 0;
            $open     = $date >= $query->today;
            $none     = $day->kind === CalendarDayKind::None;
            $diff     = $open || $none ? 0 : $seconds - $day->expectedSeconds;
            $overtime = $query->overtime->daily[$date] ?? 0;
            $flags    = array_values(array_unique(array_merge($worked->flags ?? [], $query->overtime->flags[$date] ?? [], $open ? ['open_day'] : [])));
            $days[]   = [
                'date'            => $date,
                'kind'            => $day->kind->value,
                'label'           => $day->label,
                'expectedSeconds' => $day->expectedSeconds,
                'workedSeconds'   => $seconds,
                'diffSeconds'     => $diff,
                'overtimeSeconds' => $overtime,
                'flags'           => $flags,
            ];
            if ($none) {
                continue;
            }

            $period = Period::containing(TimesheetPeriodKind::Week, $date);
            $key    = $period->key;
            $weeks[$key] ??= ['key' => $key, 'expectedSeconds' => 0, 'workedSeconds' => 0, 'diffSeconds' => 0, 'overtimeSeconds' => $period->end <= $query->to ? $query->overtime->weekly[$key] ?? 0 : 0];
            $weeks[$key]['expectedSeconds'] += $day->expectedSeconds;
            $weeks[$key]['workedSeconds'] += $seconds;
            $weeks[$key]['diffSeconds'] += $diff;
            $weeks[$key]['overtimeSeconds'] += $overtime;
        }
        foreach ($weeks as $week) {
            $totals['expectedSeconds'] += $week['expectedSeconds'];
            $totals['workedSeconds'] += $week['workedSeconds'];
            $totals['diffSeconds'] += $week['diffSeconds'];
            $totals['overtimeSeconds'] += $week['overtimeSeconds'];
        }

        $balance = $query->openingSeconds;
        foreach ($query->calendar as $date => $day) {
            $date = (string) $date;
            if ($date >= $query->balanceFrom && $date <= $query->to && $date < $query->today && $day->kind !== CalendarDayKind::None) {
                $balance += ($query->worked[$date]->workedSeconds ?? 0) - $day->expectedSeconds;
            }
        }
        $ledger = [];
        foreach ($query->ledger as $line) {
            $effective = $line->getEffectiveDate();
            if ($effective >= $query->balanceFrom && $effective <= $query->to && $this->movesBalance($line, $query->ledger)) {
                $balance += $line->getSeconds();
            }
            if ($effective >= $query->from && $effective <= $query->to) {
                $ledger[] = $line->toArray();
            }
        }

        return new BalanceReport(
            $query->from,
            $query->to,
            $query->timezone,
            $days,
            array_values($weeks),
            $totals,
            $balance,
            $ledger,
            $query->source,
            $query->legalRecord,
            ['id' => $query->overtime->policyId, 'version' => $query->overtime->policyVersion],
            $query->overtime->annual,
        );
    }

    /**
     * Whether a line moves the balance; a reversal moves it when the line it reverses does.
     *
     * @param list<BalanceLedgerLine> $lines
     */
    private function movesBalance(BalanceLedgerLine $line, array $lines): bool
    {
        if ($line->getReversesId() === null) {
            return $line->getKind()->movesBalance();
        }
        foreach ($lines as $candidate) {
            if ($candidate->getId() === $line->getReversesId()) {
                return $candidate->getKind()->movesBalance();
            }
        }

        // The service loads every reversed line; one that is gone (purged) was a balance line in practice.
        return true;
    }
}
