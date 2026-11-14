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
use Nowo\TimeTrackBundle\Tests\Stub\MutableProfileProvider;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\FindableInMemoryTimeEntryRepository;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedWorkingCalendar;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryActiveTimerRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimeEntryRepository;
use Nowo\TimeTrackBundle\Tests\Support\MonitoringFixtures;
use Nowo\TimeTrackBundle\Timesheet\TimesheetTimezoneResolver;
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

    private function gate(): MonitoringGate
    {
        return new MonitoringGate($this->timers, $this->entries, new FixedWorkingCalendar('UTC', 28_800, [], ['']), new TimesheetTimezoneResolver(null, $this->profile), $this->profile);
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
