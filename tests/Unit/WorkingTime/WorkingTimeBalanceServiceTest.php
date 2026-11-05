<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\WorkingTime;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\Entity\BalanceLedgerLine;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\LedgerKind;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Enum\TimesheetLockReason;
use Nowo\TimeTrackBundle\Enum\TimesheetPeriodKind;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryData;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedWorkingCalendar;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryBalanceLedgerRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimeEntryRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimesheetRepository;
use Nowo\TimeTrackBundle\Tests\Support\TimesheetFixtures;
use Nowo\TimeTrackBundle\Timesheet\Period;
use Nowo\TimeTrackBundle\Timesheet\TimesheetTimezoneResolver;
use Nowo\TimeTrackBundle\WorkingTime\CalendarDay;
use Nowo\TimeTrackBundle\WorkingTime\CalendarDayKind;
use Nowo\TimeTrackBundle\WorkingTime\ConfigOvertimePolicy;
use Nowo\TimeTrackBundle\WorkingTime\TaskTimeWorkedTimeSource;
use Nowo\TimeTrackBundle\WorkingTime\WorkedDay;
use Nowo\TimeTrackBundle\WorkingTime\WorkedTimeSourceInterface;
use Nowo\TimeTrackBundle\WorkingTime\WorkingTimeBalanceService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_column;
use function array_combine;
use function array_sum;
use function in_array;

/**
 * Since 1.13 (AC-09, AC-11): the balance of a fake month in Madrid with one public holiday, two vacation days, one half
 * day and the October DST change matches a hand-computed fixture to the second.
 */
final class WorkingTimeBalanceServiceTest extends TestCase
{
    private TestUser $user;

    private InMemoryTimeEntryRepository $entries;

    private InMemoryBalanceLedgerRepository $ledger;

    private InMemoryTimesheetRepository $timesheets;

    private FixedWorkingCalendar $calendar;

    private TimesheetTimezoneResolver $timezones;

    protected function setUp(): void
    {
        $this->user       = new TestUser('7', 'ana@example.com');
        $this->entries    = new InMemoryTimeEntryRepository();
        $this->ledger     = new InMemoryBalanceLedgerRepository();
        $this->timesheets = new InMemoryTimesheetRepository();
        $this->calendar   = new FixedWorkingCalendar('Europe/Madrid', 28_800, [
            '2026-10-12' => new CalendarDay('2026-10-12', 'Europe/Madrid', 0, CalendarDayKind::Holiday, 'Fiesta Nacional'),
            '2026-10-19' => new CalendarDay('2026-10-19', 'Europe/Madrid', 0, CalendarDayKind::Vacation),
            '2026-10-20' => new CalendarDay('2026-10-20', 'Europe/Madrid', 0, CalendarDayKind::Vacation),
            '2026-10-30' => new CalendarDay('2026-10-30', 'Europe/Madrid', 14_400, CalendarDayKind::Working, null, 0.5),
        ]);
        $this->timezones = new TimesheetTimezoneResolver(new FixedScopeProvider(['7' => ['org-a']], ['org-a' => 'Europe/Madrid']));
    }

    public function testAFakeMonthMatchesTheHandComputedFixture(): void
    {
        $this->seedOctober();
        $opening = $this->line('2026-10-01', LedgerKind::Opening, 3600, 'Carried over');
        $adjust  = $this->line('2026-10-15', LedgerKind::Adjustment, -1800, 'Doctor (no cause stored)', $opening);
        $ratify  = $this->line('2026-10-16', LedgerKind::OvertimeRatified, 900, null, $adjust);
        $this->line('2026-10-17', LedgerKind::Reversal, 1800, 'Wrong', $ratify, $adjust->getId());

        $report = $this->service('2026-11-02 10:00:00', '2026-10-01')->report($this->user, 'org-a', '2026-10-01', '2026-10-31');

        self::assertNotNull($report);
        $days = array_combine(array_column($report->days, 'date'), $report->days);
        self::assertCount(31, $days);
        // 22 weekdays − holiday − 2 vacation days = 19 days, one of them a half day: 18 × 8 h + 4 h.
        self::assertSame(532_800, $report->totals['expectedSeconds']);
        // +1.5 h (Oct 1) − 1 h (Oct 2) + 2 h (holiday) + 5 h (Saturday night across the DST change); overlaps merged.
        self::assertSame(27_000, $report->totals['diffSeconds']);
        self::assertSame(559_800, $report->totals['workedSeconds']);
        self::assertSame(18_000, $days['2026-10-24']['workedSeconds'], '22:00 CEST → 02:00 CET is five real hours');
        self::assertSame(28_800, $days['2026-10-05']['workedSeconds'], 'two overlapping timers count once');
        self::assertSame('holiday', $days['2026-10-12']['kind']);
        self::assertSame('Fiesta Nacional', $days['2026-10-12']['label']);
        self::assertSame(0, $days['2026-10-30']['diffSeconds']);
        // Balance = Σ diff + opening − adjustment + its reversal; the ratified line does not move it.
        self::assertSame(27_000 + 3600 - 1800 + 1800, $report->balanceSeconds);
        // Daily overtime (expected mode): Oct 1 1.5 h, Oct 12 2 h, Oct 24 5 h; no weekly excess.
        self::assertSame(30_600, $report->totals['overtimeSeconds']);
        self::assertSame('task_time', $report->source);
        self::assertFalse($report->legalRecord);
        self::assertSame('config', $report->policy['id']);
        self::assertSame('Europe/Madrid', $report->timezone);
        self::assertCount(4, $report->ledger);
        self::assertSame(900, $report->overtime['ratifiedSeconds']);
        self::assertSame(['from', 'to', 'timezone', 'source', 'legalRecord', 'policy', 'days', 'weeks', 'totals', 'balanceSeconds', 'ledger', 'overtime'], array_keys($report->toArray()));
        self::assertSame([], $report->flagCounts());
        self::assertSame(array_sum(array_column($report->weeks, 'diffSeconds')), $report->totals['diffSeconds']);
    }

    public function testTodayAndLaterAreOpenAndExcluded(): void
    {
        $this->seedOctober();

        $report = $this->service('2026-10-14 12:00:00', '2026-10-01')->report($this->user, 'org-a', '2026-10-12', '2026-10-18');

        self::assertNotNull($report);
        $days = array_combine(array_column($report->days, 'date'), $report->days);
        self::assertSame(['open_day'], $days['2026-10-14']['flags']);
        self::assertSame(0, $days['2026-10-14']['diffSeconds']);
        self::assertSame(28_800, $days['2026-10-14']['workedSeconds'], 'partial worked time is still shown');
        self::assertSame(0, $days['2026-10-15']['diffSeconds']);
        // Closed: Oct 1–13 (start date Oct 1): +1.5 h − 1 h + 2 h.
        self::assertSame(9000, $report->balanceSeconds);
        self::assertSame(['open_day' => 5], $report->flagCounts());
    }

    public function testTheBalanceContinuesFromTheLastLockedSnapshot(): void
    {
        $this->seedOctober();
        $locked = TimesheetFixtures::locked($this->user, 'org-a', '2026-W41', 'Europe/Madrid', TimesheetLockReason::Approved);
        $locked->applyApprove(new DateTimeImmutable('2026-10-12 08:00:00'), '9', str_repeat('a', 64), ['balanceSeconds' => 100_000]);
        $this->timesheets->save($locked);

        $report = $this->service('2026-11-02 10:00:00', '2026-01-01')->report($this->user, 'org-a', '2026-10-19', '2026-10-25');

        self::assertNotNull($report);
        // From Oct 12 (after week 41): holiday +2 h, then the 19–25 week +5 h; the cached snapshot replaces everything before.
        self::assertSame(100_000 + 7200 + 18_000, $report->balanceSeconds);
        self::assertSame(['2026-10-12', '2026-10-25'], $this->calendar->calls[0]);
    }

    public function testWithoutAStartDateTheLast366DaysCountAndLongQueriesAreChunked(): void
    {
        $service = $this->service('2027-12-01 10:00:00', null);

        $report = $service->report($this->user, 'org-a', '2027-11-01', '2027-11-30');

        self::assertNotNull($report);
        self::assertSame([['2026-11-30', '2027-11-30'], ['2027-12-01', '2027-12-05']], $this->calendar->calls);
        self::assertLessThan(0, $report->balanceSeconds, 'no work for a year');
    }

    public function testRegisterSourcesAreTheLegalRecord(): void
    {
        $register = new class implements WorkedTimeSourceInterface {
            public function isLegalRecord(): bool
            {
                return true;
            }

            public function workedDays(UserInterface $user, string $scopeKey, DateTimeImmutable $from, DateTimeImmutable $to): array
            {
                return ['2026-10-01' => new WorkedDay('2026-10-01', 30_000, 600, new DateTimeImmutable('2026-10-01 07:00:00Z'), new DateTimeImmutable('2026-10-01 15:30:00Z'), ['corrected'])];
            }
        };
        $service = new WorkingTimeBalanceService($this->calendar, $register, new ConfigOvertimePolicy(), $this->ledger, $this->timesheets, $this->timezones, new MockClock('2026-11-02 10:00:00'), '2026-10-01');

        $report = $service->report($this->user, 'org-a', '2026-10-01', '2026-10-01');

        self::assertNotNull($report);
        self::assertTrue($report->legalRecord);
        self::assertTrue($service->isLegalRecord());
        self::assertSame('register', $report->source);
        self::assertSame(['corrected', 'break_missing'], $report->days[0]['flags']);
    }

    public function testOffUnknownCalendarsAndBadRanges(): void
    {
        $service = $this->service('2026-11-02 10:00:00', null);
        self::assertTrue($service->isEnabled());
        self::assertTrue($service->isAvailable($this->user, 'org-a'));
        self::assertFalse($service->isAvailable($this->user, 'org-b'));
        self::assertNull($service->report($this->user, 'org-b', '2026-10-01', '2026-10-31'));
        self::assertSame('2026-11-02', $service->today($this->user, 'org-a'));

        $off = new WorkingTimeBalanceService($this->calendar, new TaskTimeWorkedTimeSource($this->entries, $this->timezones), new ConfigOvertimePolicy(), $this->ledger, $this->timesheets, $this->timezones, enabled: false);
        self::assertFalse($off->isEnabled());
        self::assertFalse($off->isAvailable($this->user, 'org-a'));
        self::assertNull($off->report($this->user, 'org-a', '2026-10-01', '2026-10-31'));

        foreach ([['2026-10-31', '2026-10-01'], ['2026-01-01', '2027-01-02'], ['2026-02-30', '2026-03-01']] as [$from, $to]) {
            try {
                $service->report($this->user, 'org-a', $from, $to);
                self::fail('range ' . $from . ' ' . $to);
            } catch (InvalidArgumentException) {
            }
        }
    }

    public function testDaysOutsideTheUsersTimeInTheScopeCountNowhere(): void
    {
        $calendar = new FixedWorkingCalendar('UTC', 28_800, ['2026-10-01' => new CalendarDay('2026-10-01', 'UTC', 28_800, CalendarDayKind::None)]);
        $service  = new WorkingTimeBalanceService($calendar, new TaskTimeWorkedTimeSource($this->entries, $this->timezones), new ConfigOvertimePolicy(), $this->ledger, $this->timesheets, $this->timezones, new MockClock('2026-11-02 10:00:00'), '2026-10-01');

        $report = $service->report($this->user, 'org-a', '2026-10-01', '2026-10-02');

        self::assertNotNull($report);
        self::assertSame('none', $report->days[0]['kind']);
        self::assertSame(0, $report->days[0]['diffSeconds']);
        self::assertSame(28_800, $report->totals['expectedSeconds'], 'only Oct 2');
        self::assertSame(-28_800, $report->balanceSeconds);
    }

    public function testAReversalOutsideTheRangeIsResolved(): void
    {
        $paid = $this->line('2024-06-01', LedgerKind::OvertimePaid, -3600, 'Paid');
        $this->line('2026-10-02', LedgerKind::Reversal, 3600, 'Not paid', $paid, $paid->getId());
        $this->line('2026-10-03', LedgerKind::Reversal, 60, 'Dangling', null, 'gone');

        $report = $this->service('2026-11-02 10:00:00', '2026-10-01')->report($this->user, 'org-a', '2026-10-01', '2026-10-03');

        self::assertNotNull($report);
        // −2 working days of 8 h, + the reversal of a balance line, + a reversal whose line is gone (counted).
        self::assertSame(-57_600 + 3600 + 60, $report->balanceSeconds);
    }

    private function service(string $now, ?string $startDate): WorkingTimeBalanceService
    {
        return new WorkingTimeBalanceService($this->calendar, new TaskTimeWorkedTimeSource($this->entries, $this->timezones), new ConfigOvertimePolicy(), $this->ledger, $this->timesheets, $this->timezones, new MockClock($now), $startDate);
    }

    private function seedOctober(): void
    {
        $special = ['2026-10-01' => ['09:00', '18:30'], '2026-10-02' => ['09:00', '16:00']];
        foreach (Period::fromKey(TimesheetPeriodKind::Month, '2026-10')->dates() as $date) {
            $weekday = (int) Period::date($date)->format('N');
            if ($weekday > 5 || in_array($date, ['2026-10-12', '2026-10-19', '2026-10-20'], true)) {
                continue;
            }
            [$from, $to] = $special[$date] ?? ['09:00', '17:00'];
            if ($date === '2026-10-30') {
                // Half day in two separate blocks.
                $this->entry($date . ' 09:00', $date . ' 11:00');
                $this->entry($date . ' 11:30', $date . ' 13:30');
                continue;
            }
            if ($date === '2026-10-05') {
                $this->entry($date . ' 09:00', $date . ' 13:00');
                $this->entry($date . ' 12:00', $date . ' 17:00');
                continue;
            }
            $this->entry($date . ' ' . $from, $date . ' ' . $to);
        }
        $this->entry('2026-10-12 10:00', '2026-10-12 12:00');
        // Saturday night across the change (Oct 25, 03:00 CEST → 02:00 CET): five real hours, on the start date.
        $this->entry('2026-10-24 22:00', '2026-10-25 02:00');
        // Not counted: another scope and an imported entry.
        $this->entry('2026-10-07 18:00', '2026-10-07 20:00', 'org-b');
        $this->entries->save(TimeEntry::imported($this->user, new ImportedTimeEntryData('clickup:x', 'i-1', 'task-1', 'T', null, $this->at('2026-10-06 18:00'), $this->at('2026-10-06 20:00'), 7200))->assignScopeKey('org-a'));
    }

    private function entry(string $from, string $to, string $scope = 'org-a'): void
    {
        $start = $this->at($from);
        $end   = $this->at($to);
        $this->entries->save((new TimeEntry($this->user, 'task-1', 'T', null, $start, $end, $end->getTimestamp() - $start->getTimestamp(), TimeEntrySource::Web))->assignScopeKey($scope));
    }

    private function at(string $local): DateTimeImmutable
    {
        return new DateTimeImmutable($local, new DateTimeZone('Europe/Madrid'));
    }

    private function line(string $date, LedgerKind $kind, int $seconds, ?string $reason, ?BalanceLedgerLine $after = null, ?string $reverses = null): BalanceLedgerLine
    {
        $line = new BalanceLedgerLine($this->user, '7', 'org-a', $date, $kind, $seconds, new DateTimeImmutable($date . ' 12:00:00'), $after?->getHash(), $reason, null, $reverses, '9');
        $this->ledger->append($line);

        return $line;
    }
}
