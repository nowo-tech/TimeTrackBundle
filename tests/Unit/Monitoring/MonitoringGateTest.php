<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Monitoring;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Monitoring\MonitoringAcknowledgement;
use Nowo\TimeTrackBundle\Monitoring\MonitoringGate;
use Nowo\TimeTrackBundle\Port\WorkdaySourceGate;
use Nowo\TimeTrackBundle\Tests\Stub\MutableProfileProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubWorkdayProvider;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\FindableInMemoryTimeEntryRepository;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedWorkdayRegister;
use Nowo\TimeTrackBundle\Tests\Support\FixedWorkingCalendar;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryActiveTimerRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimeEntryRepository;
use Nowo\TimeTrackBundle\Tests\Support\MonitoringFixtures;
use Nowo\TimeTrackBundle\Timesheet\TimesheetTimezoneResolver;
use Nowo\TimeTrackBundle\Workday\WorkdayProviderInterface;
use Nowo\TimeTrackBundle\WorkingTime\CalendarDay;
use Nowo\TimeTrackBundle\WorkingTime\CalendarDayKind;
use PHPUnit\Framework\TestCase;

/**
 * Since 1.26.0 (contract §24.3): the refusal checks in contract order, SC-002 against the bundle's own timer and
 * entries, the schedule through the working calendar, the slot overlap of activity items and the age.
 */
final class MonitoringGateTest extends TestCase
{
    private TestUser $user;

    private InMemoryActiveTimerRepository $timers;

    private FindableInMemoryTimeEntryRepository $entries;

    private MutableProfileProvider $profile;

    protected function setUp(): void
    {
        $this->user    = new TestUser('1', 'u@example.com');
        $this->timers  = new InMemoryActiveTimerRepository();
        $this->entries = new FindableInMemoryTimeEntryRepository();
        $this->profile = new MutableProfileProvider();
    }

    public function testTheContractOrder(): void
    {
        $now   = new DateTimeImmutable('2026-11-18 10:00:00 UTC'); // a Wednesday
        $entry = $this->entry('2026-11-18 08:00:00', '2026-11-18 09:00:00');
        $gate  = $this->gate();
        $ack   = $this->ack(7);
        $at    = new DateTimeImmutable('2026-11-18 08:30:00 UTC');

        self::assertSame('kind_off', $gate->refusal($this->user, MonitoringFixtures::policy(7, ['apps']), $ack, 'screenshots', $entry, $at, $now));
        self::assertSame('not_acknowledged', $gate->refusal($this->user, MonitoringFixtures::policy(), $this->ack(6), 'screenshots', $entry, $at, $now));
        self::assertNull($gate->refusal($this->user, MonitoringFixtures::policy(), null, 'screenshots', $entry, $at, $now, 0, false), 'a discard marker needs no acknowledgement');
        self::assertSame('outside_timer', $gate->refusal($this->user, MonitoringFixtures::policy(), $ack, 'screenshots', 'unknown', $at, $now));
        self::assertSame('outside_timer', $gate->refusal($this->user, MonitoringFixtures::policy(), $ack, 'screenshots', $entry, new DateTimeImmutable('2026-11-18 09:00:01 UTC'), $now));
        self::assertNull($gate->refusal($this->user, MonitoringFixtures::policy(), $ack, 'screenshots', $entry, $at, $now));
        self::assertNull($gate->refusal($this->user, MonitoringFixtures::policy(), $ack, 'apps', $entry, new DateTimeImmutable('2026-11-18 07:55:00 UTC'), $now, 600), 'a slot only overlaps the timer');
        self::assertSame('outside_timer', $gate->refusal($this->user, MonitoringFixtures::policy(), $ack, 'apps', $entry, new DateTimeImmutable('2026-11-18 07:40:00 UTC'), $now, 600));

        self::assertSame('too_old', $gate->refusal($this->user, MonitoringFixtures::policy(overrides: ['maxBufferAgeHours' => 1]), $ack, 'screenshots', $entry, new DateTimeImmutable('2026-11-18 08:59:00 UTC'), $now));
        $this->profile->hours = ['timezone' => 'UTC', 'slots' => [['day' => 3, 'from' => '09:00', 'to' => '17:00']]];
        self::assertSame('outside_hours', $gate->refusal($this->user, MonitoringFixtures::policy(), $ack, 'screenshots', $entry, $at, $now));
        self::assertNull($gate->refusal($this->user, MonitoringFixtures::policy(), $ack, 'screenshots', $entry, $at, $now, 0, false, false), 'a discard marker skips the schedule');
    }

    public function testTheTimerAndTheEntries(): void
    {
        $now   = new DateTimeImmutable('2026-11-18 10:00:00 UTC');
        $timer = new ActiveTimer($this->user, 'task-1', 'T', null, ClientType::Desktop, null, new DateTimeImmutable('2026-11-18 09:00:00 UTC'));
        $this->timers->save($timer);
        $gate = $this->gate();
        $ack  = $this->ack(7);

        self::assertNull($gate->refusal($this->user, MonitoringFixtures::policy(), $ack, 'screenshots', $timer->getId(), new DateTimeImmutable('2026-11-18 10:01:30 UTC'), $now), 'running: until now plus the clock skew');
        self::assertSame('outside_timer', $gate->refusal($this->user, MonitoringFixtures::policy(), $ack, 'screenshots', $timer->getId(), new DateTimeImmutable('2026-11-18 10:02:01 UTC'), $now));
        self::assertSame('outside_timer', $gate->refusal(new TestUser('2', 'o@example.com'), MonitoringFixtures::policy(), $ack, 'screenshots', $timer->getId(), $now, $now), 'never another user\'s timer');
        foreach ([TimeEntrySource::Manual, TimeEntrySource::Calendar, TimeEntrySource::Import] as $source) {
            self::assertSame('outside_timer', $gate->refusal($this->user, MonitoringFixtures::policy(), $ack, 'screenshots', $this->entry('2026-11-18 07:00:00', '2026-11-18 08:00:00', $source), new DateTimeImmutable('2026-11-18 07:30:00 UTC'), $now), $source->value);
        }
        $other = $this->entry('2026-11-18 07:00:00', '2026-11-18 08:00:00', TimeEntrySource::Desktop, new TestUser('2', 'o@example.com'));
        self::assertSame('outside_timer', $gate->refusal($this->user, MonitoringFixtures::policy(), $ack, 'screenshots', $other, new DateTimeImmutable('2026-11-18 07:30:00 UTC'), $now));

        // A host entry store that cannot find by id: only the running timer counts.
        $plain = new MonitoringGate($this->timers, new InMemoryTimeEntryRepository(), new FixedWorkingCalendar('UTC', 28_800, [], ['']), new TimesheetTimezoneResolver(null, $this->profile), $this->profile);
        self::assertSame('outside_timer', $plain->refusal($this->user, MonitoringFixtures::policy(), $ack, 'screenshots', 'entry-1', $now, $now));
        self::assertNull($plain->refusal($this->user, MonitoringFixtures::policy(), $ack, 'screenshots', $timer->getId(), $now, $now));
    }

    public function testTheWorkingCalendar(): void
    {
        $check = function (MonitoringGate $gate, string $start, string $at): ?string {
            $entry = $this->entry($start, (new DateTimeImmutable($start . ' UTC'))->modify('+2 hours')->format('Y-m-d H:i:s'));

            $instant = new DateTimeImmutable($at . ' UTC');

            return $gate->refusal($this->user, MonitoringFixtures::policy(), $this->ack(7), 'screenshots', $entry, $instant, $instant->modify('+1 hour'));
        };
        $calendar = new FixedWorkingCalendar('UTC', 28_800, [
            '2026-11-19' => new CalendarDay('2026-11-19', 'UTC', 0, CalendarDayKind::Holiday, 'Fiesta'),
            '2026-11-20' => new CalendarDay('2026-11-20', 'UTC', 28_800, CalendarDayKind::Compensatory),
        ], ['', 'org-a']);
        $gate = new MonitoringGate($this->timers, $this->entries, $calendar, new TimesheetTimezoneResolver(null, $this->profile), $this->profile, new FixedScopeProvider());

        self::assertNull($check($gate, '2026-11-18 08:00:00', '2026-11-18 08:30:00'), 'a working day');
        self::assertSame('outside_hours', $check($gate, '2026-11-19 08:00:00', '2026-11-19 08:30:00'), 'a holiday');
        self::assertSame('outside_hours', $check($gate, '2026-11-20 08:00:00', '2026-11-20 08:30:00'), 'time off in lieu');
        self::assertSame('outside_hours', $check($gate, '2026-11-21 08:00:00', '2026-11-21 08:30:00'), 'a rest day');
        $this->profile->hours = ['timezone' => 'Europe/Madrid', 'slots' => [['day' => 2, 'from' => '09:00', 'to' => '23:59']]];
        self::assertNull($check($gate, '2026-11-18 08:00:00', '2026-11-18 08:30:00'), 'no slot for that weekday: the calendar decides');
        $this->profile->hours = ['timezone' => 'Europe/Madrid', 'slots' => [['day' => 3, 'from' => '09:00', 'to' => '23:59']]];
        self::assertNull($check($gate, '2026-11-18 08:00:00', '2026-11-18 08:30:00'), '09:30 in Madrid');
        self::assertSame('outside_hours', $check($gate, '2026-11-18 07:00:00', '2026-11-18 07:30:00'), '08:30 in Madrid');
        self::assertNull($check($gate, '2026-11-18 22:00:00', '2026-11-18 22:59:00'), '23:59 runs to midnight');

        $none = new MonitoringGate($this->timers, $this->entries, new FixedWorkingCalendar('UTC', 28_800, [], ['other']), new TimesheetTimezoneResolver(null, $this->profile), $this->profile);
        self::assertNull($check($none, '2026-11-21 08:00:00', '2026-11-21 08:30:00'), 'no schedule from the host: no restriction');
    }

    /**
     * Since 1.41.0 (D11): a break, before clock-in and after clock-out refuse with `on_break`, after `outside_hours`
     * and before `too_old`; discard markers skip it.
     */
    public function testOnBreakDuringBreaksAndAfterClockOut(): void
    {
        $now      = new DateTimeImmutable('2026-11-18 18:00:00 UTC');
        $entry    = $this->entry('2026-11-18 07:00:00', '2026-11-18 17:00:00');
        $register = (new FixedWorkdayRegister('2026-11-18'))->day('2026-11-18', 'closed', [
            ['work', '2026-11-18T08:00:00Z', '2026-11-18T12:00:00Z'],
            ['break', '2026-11-18T12:00:00Z', '2026-11-18T12:30:00Z'],
            ['work', '2026-11-18T12:30:00+00:00', '2026-11-18T16:00Z'],
        ]);
        $gate = $this->gate(new WorkdaySourceGate($register));
        $ack  = $this->ack(7);
        $at   = static fn (string $time): DateTimeImmutable => new DateTimeImmutable('2026-11-18 ' . $time . ' UTC');

        self::assertNull($gate->refusal($this->user, MonitoringFixtures::policy(), $ack, 'screenshots', $entry, $at('09:00:00'), $now));
        self::assertSame('on_break', $gate->refusal($this->user, MonitoringFixtures::policy(), $ack, 'screenshots', $entry, $at('12:10:00'), $now), 'a clocked break');
        self::assertSame('on_break', $gate->refusal($this->user, MonitoringFixtures::policy(), $ack, 'screenshots', $entry, $at('07:30:00'), $now), 'before clock-in');
        self::assertSame('on_break', $gate->refusal($this->user, MonitoringFixtures::policy(), $ack, 'screenshots', $entry, $at('16:30:00'), $now), 'after clock-out');
        self::assertNull($gate->refusal($this->user, MonitoringFixtures::policy(), $ack, 'screenshots', $entry, $at('12:30:00'), $now), 'back from the break (start inclusive)');
        self::assertSame('on_break', $gate->refusal($this->user, MonitoringFixtures::policy(), $ack, 'screenshots', $entry, $at('16:00:00'), $now), 'end exclusive');
        self::assertSame('on_break', $gate->refusal($this->user, MonitoringFixtures::policy(), $ack, 'apps', $entry, $at('12:10:00'), $now, 600), 'an activity slot');
        self::assertNull($gate->refusal($this->user, MonitoringFixtures::policy(), null, 'screenshots', $entry, $at('12:10:00'), $now, 600, false, false), 'a discard marker skips it');
        self::assertSame('outside_timer', $gate->refusal($this->user, MonitoringFixtures::policy(), $ack, 'screenshots', $entry, $at('17:30:00'), $now), 'the timer comes first');
        self::assertSame('too_old', $gate->refusal($this->user, MonitoringFixtures::policy(overrides: ['maxBufferAgeHours' => 1]), $ack, 'screenshots', $entry, $at('09:00:00'), $now), 'too_old after on_break');
        self::assertSame('on_break', $gate->refusal($this->user, MonitoringFixtures::policy(overrides: ['maxBufferAgeHours' => 1]), $ack, 'screenshots', $entry, $at('12:10:00'), $now));
        $saved                = $this->profile->hours;
        $this->profile->hours = ['timezone' => 'UTC', 'slots' => [['day' => 3, 'from' => '13:00', 'to' => '17:00']]];
        self::assertSame('outside_hours', $gate->refusal($this->user, MonitoringFixtures::policy(), $ack, 'screenshots', $entry, $at('12:10:00'), $now), 'outside_hours before on_break');
        $this->profile->hours = $saved;

        $register->off = ['1'];
        self::assertNull($gate->refusal($this->user, MonitoringFixtures::policy(), $ack, 'screenshots', $entry, $at('12:10:00'), $now), 'register off for the worker: no restriction');
        $register->off = [];
        self::assertNull($this->gate()->refusal($this->user, MonitoringFixtures::policy(), $ack, 'screenshots', $entry, $at('12:10:00'), $now), 'no workday provider: no restriction');

        $register->day('2026-11-18', 'idle', []);
        self::assertSame('on_break', $gate->refusal($this->user, MonitoringFixtures::policy(), $ack, 'screenshots', $entry, $at('09:00:00'), $now), 'not clocked in at all');
        $register->day('2026-11-18', 'closed', [['work', 'yesterday-ish', '2026-11-18T16:00:00Z']]);
        self::assertSame('on_break', $gate->refusal($this->user, MonitoringFixtures::policy(), $ack, 'screenshots', $entry, $at('09:00:00'), $now), 'an unreadable segment covers nothing');
    }

    public function testAnOpenWorkSegmentCountsOnlyWhileWorking(): void
    {
        $now   = new DateTimeImmutable('2026-11-18 10:00:00 UTC');
        $timer = new ActiveTimer($this->user, 'task-1', 'T', null, ClientType::Desktop, null, new DateTimeImmutable('2026-11-18 08:00:00 UTC'));
        $this->timers->save($timer);
        $register = (new FixedWorkdayRegister('2026-11-18'))->day('2026-11-18', 'working', [['work', '2026-11-18T08:00:00Z', null]]);
        $gate     = $this->gate(new WorkdaySourceGate($register));
        $ack      = $this->ack(7);

        self::assertNull($gate->refusal($this->user, MonitoringFixtures::policy(), $ack, 'screenshots', $timer->getId(), $now, $now));
        $register->day('2026-11-18', 'on_break', [['work', '2026-11-18T08:00:00Z', '2026-11-18T09:50:00Z'], ['break', '2026-11-18T09:50:00Z', null]]);
        self::assertSame('on_break', $gate->refusal($this->user, MonitoringFixtures::policy(), $ack, 'screenshots', $timer->getId(), $now, $now), 'the timer runs on, the workday is on a break');
        $register->day('2026-11-18', 'closed', [['work', '2026-11-18T08:00:00Z', null]]);
        self::assertSame('on_break', $gate->refusal($this->user, MonitoringFixtures::policy(), $ack, 'screenshots', $timer->getId(), $now, $now), 'an open segment of a closed day covers nothing');
    }

    public function testEarlierDaysNeedTheHistoryPort(): void
    {
        $now      = new DateTimeImmutable('2026-11-19 07:00:00 UTC');
        $entry    = $this->entry('2026-11-18 20:00:00', '2026-11-18 23:00:00');
        $register = (new FixedWorkdayRegister('2026-11-19'))
            ->day('2026-11-18', 'closed', [['work', '2026-11-18T08:00:00Z', '2026-11-18T21:00:00Z']])
            ->day('2026-11-19', 'idle', []);
        $ack = $this->ack(7);
        $at  = new DateTimeImmutable('2026-11-18 22:00:00 UTC');

        self::assertSame('workday_unknown', $this->gate(new WorkdaySourceGate($register))->refusal($this->user, MonitoringFixtures::policy(), $ack, 'screenshots', $entry, $at, $now), 'today only: an earlier day is refused (audit ZBB-L2, fail closed)');
        $register->off = ['1'];
        self::assertNull($this->gate(new WorkdaySourceGate($register))->refusal($this->user, MonitoringFixtures::policy(), $ack, 'screenshots', $entry, $at, $now), 'register off for the worker: nothing to check');
        $register->off = [];
        self::assertSame('on_break', $this->gate(new WorkdaySourceGate($register->withHistory()))->refusal($this->user, MonitoringFixtures::policy(), $ack, 'screenshots', $entry, $at, $now), 'with history: after clock-out');
        self::assertNull($this->gate(new WorkdaySourceGate($register->withHistory()))->refusal($this->user, MonitoringFixtures::policy(), $ack, 'screenshots', $entry, new DateTimeImmutable('2026-11-18 20:30:00 UTC'), $now));

        // Audit ZBB-D-L1: once today's answer says the register is on, a null day of the evidence is unknown (fail
        // closed); today's null means the register is off.
        $saved = $register->days['2026-11-18'];
        unset($register->days['2026-11-18']);
        self::assertSame('workday_unknown', $this->gate(new WorkdaySourceGate($register->withHistory()))->refusal($this->user, MonitoringFixtures::policy(), $ack, 'screenshots', $entry, $at, $now));
        $register->off = ['1'];
        self::assertNull($this->gate(new WorkdaySourceGate($register->withHistory()))->refusal($this->user, MonitoringFixtures::policy(), $ack, 'screenshots', $entry, $at, $now));
        $register->off                = [];
        $register->days['2026-11-18'] = $saved;

        // The register's zone decides the date: 23:30 UTC is already the 19th in Madrid.
        $madrid = (new FixedWorkdayRegister('2026-11-19'))
            ->day('2026-11-18', 'closed', [['work', '2026-11-18T08:00:00+01:00', '2026-11-18T18:00:00+01:00']], 'Europe/Madrid')
            ->day('2026-11-19', 'working', [['work', '2026-11-19T00:15:00+01:00', '2026-11-19T01:00:00+01:00']], 'Europe/Madrid');
        $late = $this->entry('2026-11-18 22:00:00', '2026-11-19 00:30:00');
        self::assertNull($this->gate(new WorkdaySourceGate($madrid->withHistory()))->refusal($this->user, MonitoringFixtures::policy(), $ack, 'screenshots', $late, new DateTimeImmutable('2026-11-18 23:30:00 UTC'), $now));
        self::assertSame('on_break', $this->gate(new WorkdaySourceGate($madrid->withHistory()))->refusal($this->user, MonitoringFixtures::policy(), $ack, 'screenshots', $late, new DateTimeImmutable('2026-11-18 22:30:00 UTC'), $now));
    }

    /**
     * Audit ZBB-L3: an activity slot is checked over the whole span the timer covers, for the register and the schedule.
     */
    public function testASlotIsCheckedOverItsWholeSpan(): void
    {
        $now      = new DateTimeImmutable('2026-11-18 18:00:00 UTC');
        $entry    = $this->entry('2026-11-18 08:00:00', '2026-11-18 16:00:00');
        $register = (new FixedWorkdayRegister('2026-11-18'))->day('2026-11-18', 'closed', [
            ['work', '2026-11-18T08:00:00Z', '2026-11-18T12:02:00Z'],
            ['break', '2026-11-18T12:02:00Z', '2026-11-18T13:00:00Z'],
            ['work', '2026-11-18T13:00:00Z', '2026-11-18T14:00:00Z'],
            ['work', '2026-11-18T14:00:00Z', '2026-11-18T16:00:00Z'],
        ]);
        $gate = $this->gate(new WorkdaySourceGate($register));
        $ack  = $this->ack(7);
        $slot = static fn (string $time): DateTimeImmutable => new DateTimeImmutable('2026-11-18 ' . $time . ' UTC');

        self::assertNull($gate->refusal($this->user, MonitoringFixtures::policy(), $ack, 'apps', $entry, $slot('11:50:00'), $now, 600));
        self::assertSame('on_break', $gate->refusal($this->user, MonitoringFixtures::policy(), $ack, 'apps', $entry, $slot('12:00:00'), $now, 600), 'the slot runs into the break');
        self::assertSame('on_break', $gate->refusal($this->user, MonitoringFixtures::policy(), $ack, 'apps', $entry, $slot('12:50:00'), $now, 600), 'the slot starts in the break');
        self::assertNull($gate->refusal($this->user, MonitoringFixtures::policy(), $ack, 'apps', $entry, $slot('13:55:00'), $now, 600), 'contiguous work segments are one stretch');
        self::assertNull($gate->refusal($this->user, MonitoringFixtures::policy(), $ack, 'apps', $entry, $slot('15:55:00'), $now, 600), 'only what the timer covers: it ends at 16:00 with the work');
        self::assertNull($gate->refusal($this->user, MonitoringFixtures::policy(), $ack, 'screenshots', $entry, $slot('12:01:59'), $now), 'a screenshot is one instant');

        $this->profile->hours = ['timezone' => 'UTC', 'slots' => [['day' => 3, 'from' => '08:00', 'to' => '11:55']]];
        self::assertSame('outside_hours', $gate->refusal($this->user, MonitoringFixtures::policy(), $ack, 'apps', $entry, $slot('11:50:00'), $now, 600), 'the slot runs past the schedule');
        self::assertNull($gate->refusal($this->user, MonitoringFixtures::policy(), $ack, 'apps', $entry, $slot('11:40:00'), $now, 600));
    }

    /**
     * Audit ZBB-L4: a shift that started yesterday and runs past midnight covers the instants after midnight.
     */
    public function testANightShiftAcrossMidnight(): void
    {
        $now      = new DateTimeImmutable('2026-11-19 07:00:00 UTC');
        $entry    = $this->entry('2026-11-18 22:00:00', '2026-11-19 06:00:00');
        $register = (new FixedWorkdayRegister('2026-11-18'))
            ->day('2026-11-18', 'closed', [['work', '2026-11-18T22:00:00Z', '2026-11-19T06:00:00Z']])
            ->day('2026-11-19', 'idle', []);
        $ack = $this->ack(7);
        $at  = new DateTimeImmutable('2026-11-19 02:00:00 UTC');

        self::assertNull($this->gate(new WorkdaySourceGate($register->withHistory()))->refusal($this->user, MonitoringFixtures::policy(), $ack, 'screenshots', $entry, $at, $now), 'with history: the day before is consulted');
        self::assertNull($this->gate(new WorkdaySourceGate($register->withHistory()))->refusal($this->user, MonitoringFixtures::policy(), $ack, 'apps', $entry, new DateTimeImmutable('2026-11-18 23:55:00 UTC'), $now, 600), 'a slot across midnight');
        self::assertNull($this->gate(new WorkdaySourceGate($register))->refusal($this->user, MonitoringFixtures::policy(), $ack, 'screenshots', $entry, $at, $now), 'today only: today() still answers the shift\'s day, which is the day before');
        $register->today = '2026-11-19';
        self::assertSame('on_break', $this->gate(new WorkdaySourceGate($register))->refusal($this->user, MonitoringFixtures::policy(), $ack, 'screenshots', $entry, $at, $now), 'today only, after the switch to the new day: its day has no work');
        self::assertSame('workday_unknown', $this->gate(new WorkdaySourceGate($register))->refusal($this->user, MonitoringFixtures::policy(), $ack, 'screenshots', $entry, new DateTimeImmutable('2026-11-18 22:30:00 UTC'), $now), 'two days back without history');
    }

    public function testALegacyWorkdayProviderIsAskedForToday(): void
    {
        $now      = new DateTimeImmutable('2026-11-05 12:00:00 UTC');
        $entry    = $this->entry('2026-11-05 08:00:00', '2026-11-05 11:00:00');
        $provider = new StubWorkdayProvider(); // today = 2026-11-05 (Madrid), no segments

        self::assertSame('on_break', $this->gate($provider)->refusal($this->user, MonitoringFixtures::policy(), $this->ack(7), 'screenshots', $entry, new DateTimeImmutable('2026-11-05 09:00:00 UTC'), $now), 'idle all day: not clocked in');
        $provider->off = ['u@example.com'];
        self::assertNull($this->gate($provider)->refusal($this->user, MonitoringFixtures::policy(), $this->ack(7), 'screenshots', $entry, new DateTimeImmutable('2026-11-05 09:00:00 UTC'), $now));
    }

    private function gate(?WorkdayProviderInterface $workday = null): MonitoringGate
    {
        return new MonitoringGate($this->timers, $this->entries, new FixedWorkingCalendar('UTC', 28_800, [], ['']), new TimesheetTimezoneResolver(null, $this->profile), $this->profile, null, $workday);
    }

    private function ack(int $version): MonitoringAcknowledgement
    {
        return new MonitoringAcknowledgement($version, new DateTimeImmutable(), 'desktop', MonitoringFixtures::noticeSha256());
    }

    private function entry(string $start, string $end, TimeEntrySource $source = TimeEntrySource::Desktop, ?TestUser $user = null): string
    {
        $startedAt = new DateTimeImmutable($start . ' UTC');
        $endedAt   = new DateTimeImmutable($end . ' UTC');
        $entry     = new TimeEntry($user ?? $this->user, 'task-1', 'T', null, $startedAt, $endedAt, $endedAt->getTimestamp() - $startedAt->getTimestamp(), $source);
        $entry->assignScopeKey('');
        $this->entries->save($entry);

        return $entry->getId();
    }
}
