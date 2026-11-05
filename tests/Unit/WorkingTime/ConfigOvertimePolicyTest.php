<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\WorkingTime;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\BalanceLedgerLine;
use Nowo\TimeTrackBundle\Enum\LedgerKind;
use Nowo\TimeTrackBundle\WorkingTime\CalendarDay;
use Nowo\TimeTrackBundle\WorkingTime\CalendarDayKind;
use Nowo\TimeTrackBundle\WorkingTime\ConfigOvertimePolicy;
use Nowo\TimeTrackBundle\WorkingTime\OvertimeInput;
use Nowo\TimeTrackBundle\WorkingTime\WorkedDay;
use PHPUnit\Framework\TestCase;

/**
 * Since 1.13 (AC-10): daily, weekly and annual overtime and the cap flags; nothing is blocked.
 */
final class ConfigOvertimePolicyTest extends TestCase
{
    public function testDailyAndWeeklyInExpectedMode(): void
    {
        // Week 2026-W45 (Mon 2 – Sun 8 Nov): 8 h expected Monday to Friday.
        $result = (new ConfigOvertimePolicy())->evaluate(new OvertimeInput($this->week(28_800), $this->worked([
            '2026-11-02' => 36_000, // +2 h daily
            '2026-11-03' => 28_800,
            '2026-11-04' => 28_800,
            '2026-11-05' => 28_800,
            '2026-11-06' => 28_800,
            '2026-11-07' => 7200,   // rest day: +2 h daily
        ]), '2026-11-09', false));

        self::assertSame(7200, $result->daily['2026-11-02']);
        self::assertSame(7200, $result->daily['2026-11-07']);
        self::assertSame(0, $result->daily['2026-11-03']);
        self::assertSame(0, $result->weekly['2026-W45'], 'the daily part is not counted twice');
        self::assertSame('config', $result->policyId);
        self::assertMatchesRegularExpression('/^1-[0-9a-f]{8}$/', $result->policyVersion);
    }

    public function testOrdinaryMaxAndLegalWeeklyMax(): void
    {
        $calendar               = $this->week(28_800);
        $calendar['2026-11-03'] = new CalendarDay('2026-11-03', 'UTC', 28_800, CalendarDayKind::Working, null, 1.0, 36_000);
        $policy                 = new ConfigOvertimePolicy('ordinary_max', 32_400, 'legal_max', 144_000);

        $result = $policy->evaluate(new OvertimeInput($calendar, $this->worked([
            '2026-11-02' => 34_200, // 9.5 h: 0.5 h above the 9 h ordinary cap
            '2026-11-03' => 37_800, // the agreement's 10 h cap: 0.5 h
            '2026-11-04' => 32_400,
            '2026-11-05' => 32_400,
            '2026-11-06' => 32_400,
        ]), '2026-11-09', false));

        self::assertSame(1800, $result->daily['2026-11-02']);
        self::assertSame(1800, $result->daily['2026-11-03']);
        // 168 h − 40 h... : 168 300 − 144 000 − 3600 daily = 20 700.
        self::assertSame(169_200 - 144_000 - 3600, $result->weekly['2026-W45']);
        self::assertNotSame((new ConfigOvertimePolicy())->version(), $policy->version(), 'a rule change bumps the version');
    }

    public function testFlexibleSchedulesUseTheWeeklyRuleOnly(): void
    {
        $result = (new ConfigOvertimePolicy('none'))->evaluate(new OvertimeInput($this->week(28_800), $this->worked(['2026-11-02' => 43_200, '2026-11-03' => 14_400]), '2026-11-09', false));

        self::assertSame(0, $result->daily['2026-11-02']);
        self::assertSame(0, $result->weekly['2026-W45'], '12 h + 4 h stays below the 40 h weekly target');
    }

    public function testOpenAndNoneDaysAreNotEvaluated(): void
    {
        $calendar               = $this->week(28_800);
        $calendar['2026-11-02'] = new CalendarDay('2026-11-02', 'UTC', 0, CalendarDayKind::None);

        $result = (new ConfigOvertimePolicy())->evaluate(new OvertimeInput($calendar, $this->worked(['2026-11-02' => 40_000, '2026-11-05' => 40_000]), '2026-11-05', false));

        self::assertArrayNotHasKey('2026-11-02', $result->daily);
        self::assertArrayNotHasKey('2026-11-05', $result->daily);
    }

    public function testRestBreakAndLongDayFlags(): void
    {
        $worked = [
            '2026-11-02' => new WorkedDay('2026-11-02', 46_800, 0, new DateTimeImmutable('2026-11-02 08:00:00Z'), new DateTimeImmutable('2026-11-02 23:00:00Z')),
            '2026-11-03' => new WorkedDay('2026-11-03', 25_200, 1800, new DateTimeImmutable('2026-11-03 07:00:00Z'), new DateTimeImmutable('2026-11-03 15:00:00Z')),
            '2026-11-04' => new WorkedDay('2026-11-04', 0),
        ];

        $register = (new ConfigOvertimePolicy())->evaluate(new OvertimeInput($this->week(28_800), $worked, '2026-11-09', true));
        $task     = (new ConfigOvertimePolicy())->evaluate(new OvertimeInput($this->week(28_800), $worked, '2026-11-09', false));

        self::assertSame(['break_missing', 'long_day'], $register->flags['2026-11-02']);
        self::assertSame(['rest_short'], $register->flags['2026-11-03'], '8 h between 23:00 and 07:00');
        self::assertArrayNotHasKey('2026-11-04', $register->flags);
        self::assertSame(['long_day'], $task->flags['2026-11-02'], 'no break flag without a register');
    }

    public function testTheAnnualCounterSubtractsRestGivenWithinFourMonths(): void
    {
        $lines = [];
        $add   = static function (string $date, LedgerKind $kind, int $seconds, ?string $reverses = null) use (&$lines): BalanceLedgerLine {
            $line    = new BalanceLedgerLine(null, '7', 'org-a', $date, $kind, $seconds, new DateTimeImmutable(), null, 'r', null, $reverses);
            $lines[] = $line;

            return $line;
        };
        $add('2025-11-01', LedgerKind::OvertimeRatified, 36_000);         // last year: absorbs the first compensation
        $add('2026-02-01', LedgerKind::OvertimeRatified, 180_000);
        $add('2026-03-01', LedgerKind::OvertimeRatified, 72_000);
        $gone = $add('2026-03-02', LedgerKind::OvertimeRatified, 99_999);
        $add('2026-03-03', LedgerKind::Reversal, -99_999, $gone->getId());
        $add('2026-01-15', LedgerKind::OvertimeCompensated, 36_000);      // within 4 months of 2025-11-01
        $add('2026-05-15', LedgerKind::OvertimeCompensated, 7200);        // within 4 months of 2026-02-01
        $add('2026-12-01', LedgerKind::OvertimeCompensated, 7200);        // too late for any line

        $annual = (new ConfigOvertimePolicy())->evaluate(new OvertimeInput([], [], '2026-12-15', false, $lines))->annual;

        self::assertSame(2026, $annual['year']);
        self::assertSame(252_000, $annual['ratifiedSeconds']);
        self::assertSame(7200, $annual['compensatedSeconds']);
        self::assertSame(244_800, $annual['countedSeconds']);
        self::assertSame(288_000, $annual['capSeconds']);
        self::assertSame(['overtime_cap_near'], $annual['flags']);

        $exceeded = (new ConfigOvertimePolicy(annualCapSeconds: 200_000))->evaluate(new OvertimeInput([], [], '2026-12-15', false, $lines))->annual;
        self::assertSame(['overtime_cap_exceeded'], $exceeded['flags']);
        self::assertSame([], (new ConfigOvertimePolicy(annualCapSeconds: 0))->evaluate(new OvertimeInput([], [], '2026-12-15', false, $lines))->annual['flags']);
        self::assertSame([], (new ConfigOvertimePolicy())->evaluate(new OvertimeInput([], [], '2026-12-15', false, []))->annual['flags']);
    }

    /**
     * @return array<string, CalendarDay>
     */
    private function week(int $weekday): array
    {
        $days = [];
        foreach (['02', '03', '04', '05', '06', '07', '08'] as $i => $d) {
            $date        = '2026-11-' . $d;
            $days[$date] = $i < 5 ? new CalendarDay($date, 'UTC', $weekday, CalendarDayKind::Working) : new CalendarDay($date, 'UTC', 0, CalendarDayKind::Rest);
        }

        return $days;
    }

    /**
     * @param array<string, int> $seconds
     *
     * @return array<string, WorkedDay>
     */
    private function worked(array $seconds): array
    {
        $days = [];
        foreach ($seconds as $date => $value) {
            $days[$date] = new WorkedDay($date, $value);
        }

        return $days;
    }
}
