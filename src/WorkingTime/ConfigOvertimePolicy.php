<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\WorkingTime;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\BalanceLedgerLine;
use Nowo\TimeTrackBundle\Enum\LedgerKind;
use Nowo\TimeTrackBundle\Enum\TimesheetPeriodKind;
use Nowo\TimeTrackBundle\Timesheet\Period;

use function abs;
use function array_sum;
use function hash;
use function implode;
use function max;
use function min;
use function substr;
use function usort;

/**
 * Since 1.13: the default overtime policy (`working_time.overtime`, contract §4.5).
 *
 * - Daily: worked above the expected time (`daily_mode: expected`), above the agreement's ordinary cap or 9 h
 *   (`ordinary_max`, art. 34.3 ET), or no daily rule (`none`, flexible schedules with a weekly target).
 * - Weekly: worked above Σ expected of the week (`weekly_mode: expected`) or above 40 h (`legal_max`, art. 34.1 ET),
 *   minus the daily overtime already counted.
 * - Annual: Σ ratified overtime of the calendar year minus the overtime compensated with rest within the 4 months
 *   after it (art. 35.2 ET; compensations are matched to the oldest ratified overtime first); flags
 *   `overtime_cap_near` at 80 % and `overtime_cap_exceeded` at 100 % of the cap (80 h).
 * - Flags (informational, §4.6): `long_day` (> 12 h), `rest_short` (< 12 h since the previous day's last end), and with
 *   a register source `break_missing` (> 6 h with less than 15 min of break).
 *
 * Only closed days (before today) count. The version changes with every configured value.
 */
final readonly class ConfigOvertimePolicy implements OvertimePolicyInterface
{
    public const DAILY_MODES = ['expected', 'ordinary_max', 'none'];

    public const WEEKLY_MODES = ['expected', 'legal_max'];

    /** Rest between two working days (art. 34.3 ET). */
    public const MIN_REST_SECONDS = 43_200;

    /** A day longer than this is flagged `long_day`. */
    public const LONG_DAY_SECONDS = 43_200;

    /** Continuous work that needs a break (art. 34.4 ET). */
    public const BREAK_AFTER_SECONDS = 21_600;

    /** The minimum break (art. 34.4 ET). */
    public const MIN_BREAK_SECONDS = 900;

    /** Months within which compensated rest removes overtime from the annual counter (art. 35.2 ET). */
    public const COMPENSATION_MONTHS = 4;

    /**
     * Receives the `working_time.overtime` values.
     */
    public function __construct(
        private string $dailyMode = 'expected',
        private int $ordinaryMaxSeconds = 32_400,
        private string $weeklyMode = 'expected',
        private int $weeklyMaxSeconds = 144_000,
        private int $annualCapSeconds = 288_000,
    ) {
    }

    /**
     * `config`.
     */
    public function id(): string
    {
        return 'config';
    }

    /**
     * `1-<hash of the configured values>`.
     */
    public function version(): string
    {
        return '1-' . substr(hash('sha256', implode('|', [$this->dailyMode, $this->ordinaryMaxSeconds, $this->weeklyMode, $this->weeklyMaxSeconds, $this->annualCapSeconds])), 0, 8);
    }

    /**
     * Daily, weekly and annual overtime with the informational flags.
     */
    public function evaluate(OvertimeInput $input): OvertimeResult
    {
        $daily   = [];
        $flags   = [];
        $weeks   = [];
        $prevEnd = null;
        foreach ($input->calendar as $date => $day) {
            $worked  = $input->worked[$date] ?? null;
            $seconds = $worked->workedSeconds ?? 0;
            if ($date >= $input->today || $day->kind === CalendarDayKind::None) {
                $prevEnd = $worked?->lastEnd;
                continue;
            }

            $threshold = match ($this->dailyMode) {
                'ordinary_max' => $day->ordinaryMaxSeconds ?? $this->ordinaryMaxSeconds,
                'none'         => null,
                default        => max($day->expectedSeconds, 0),
            };
            $daily[$date] = $threshold === null ? 0 : max(0, $seconds - $threshold);

            $week                       = Period::containing(TimesheetPeriodKind::Week, (string) $date)->key;
            $weeks[$week]['worked'][]   = $seconds;
            $weeks[$week]['expected'][] = $day->expectedSeconds;
            $weeks[$week]['daily'][]    = $daily[$date];

            $dayFlags = $this->flagsOf($worked, $prevEnd, $input->legalRecord);
            if ($dayFlags !== []) {
                $flags[$date] = $dayFlags;
            }
            $prevEnd = $worked?->lastEnd;
        }

        $weekly = [];
        foreach ($weeks as $key => $sums) {
            $threshold    = $this->weeklyMode === 'legal_max' ? $this->weeklyMaxSeconds : array_sum($sums['expected']);
            $weekly[$key] = max(0, array_sum($sums['worked']) - $threshold - array_sum($sums['daily']));
        }

        return new OvertimeResult($this->id(), $this->version(), $daily, $weekly, $flags, $this->annual($input));
    }

    /**
     * @return list<string>
     */
    private function flagsOf(?WorkedDay $worked, ?DateTimeImmutable $prevEnd, bool $legalRecord): array
    {
        if (!$worked instanceof WorkedDay || $worked->workedSeconds <= 0) {
            return [];
        }
        $flags = [];
        if ($worked->firstStart instanceof DateTimeImmutable && $prevEnd instanceof DateTimeImmutable && $worked->firstStart->getTimestamp() - $prevEnd->getTimestamp() < self::MIN_REST_SECONDS) {
            $flags[] = 'rest_short';
        }
        if ($legalRecord && $worked->workedSeconds > self::BREAK_AFTER_SECONDS && $worked->breakSeconds < self::MIN_BREAK_SECONDS) {
            $flags[] = 'break_missing';
        }
        if ($worked->workedSeconds > self::LONG_DAY_SECONDS) {
            $flags[] = 'long_day';
        }

        return $flags;
    }

    /**
     * @return array{year: int, ratifiedSeconds: int, compensatedSeconds: int, countedSeconds: int, capSeconds: int, flags: list<string>}
     */
    private function annual(OvertimeInput $input): array
    {
        $year     = (int) substr($input->today, 0, 4);
        $reversed = [];
        foreach ($input->ledger as $line) {
            if ($line->getKind() === LedgerKind::Reversal && $line->getReversesId() !== null) {
                $reversed[$line->getReversesId()] = true;
            }
        }

        /** @var list<array{date: string, left: int}> $ratified */
        $ratified     = [];
        $compensation = [];
        foreach ($input->ledger as $line) {
            if (isset($reversed[$line->getId()])) {
                continue;
            }
            if ($line->getKind() === LedgerKind::OvertimeRatified && $line->getSeconds() > 0) {
                $ratified[] = ['date' => $line->getEffectiveDate(), 'left' => $line->getSeconds()];
            } elseif ($line->getKind() === LedgerKind::OvertimeCompensated) {
                $compensation[] = $line;
            }
        }
        usort($ratified, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);
        usort($compensation, static fn (BalanceLedgerLine $a, BalanceLedgerLine $b): int => $a->getEffectiveDate() <=> $b->getEffectiveDate());

        $matched = 0;
        foreach ($compensation as $line) {
            $rest = abs($line->getSeconds());
            $date = $line->getEffectiveDate();
            foreach ($ratified as $i => $overtime) {
                $deadline = Period::date($overtime['date'])->modify('+' . self::COMPENSATION_MONTHS . ' months')->format('Y-m-d');
                if ($rest === 0 || $overtime['left'] === 0 || $overtime['date'] > $date || $date > $deadline) {
                    continue;
                }
                $take = min($rest, $overtime['left']);
                $ratified[$i]['left'] -= $take;
                $rest -= $take;
                if ((int) substr($overtime['date'], 0, 4) === $year) {
                    $matched += $take;
                }
            }
        }

        $counted = 0;
        foreach ($ratified as $overtime) {
            if ((int) substr($overtime['date'], 0, 4) !== $year) {
                continue;
            }
            $counted += $overtime['left'];
        }
        $flags = [];
        if ($this->annualCapSeconds > 0 && $counted * 100 >= $this->annualCapSeconds * 80) {
            $flags[] = $counted >= $this->annualCapSeconds ? 'overtime_cap_exceeded' : 'overtime_cap_near';
        }

        return ['year' => $year, 'ratifiedSeconds' => $counted + $matched, 'compensatedSeconds' => $matched, 'countedSeconds' => $counted, 'capSeconds' => $this->annualCapSeconds, 'flags' => $flags];
    }
}
