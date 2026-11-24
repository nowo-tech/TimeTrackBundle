<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Monitoring;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\TimeTrackBundle\Access\ExactTimeEntryLookup;
use Nowo\TimeTrackBundle\Client\ClientUserProfileProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientWorkingHoursNormalizer;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefs;
use Nowo\TimeTrackBundle\Port\WorkdaySourceGate;
use Nowo\TimeTrackBundle\Repository\ActiveTimerRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Support\Rfc3339;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScopeProviderInterface;
use Nowo\TimeTrackBundle\Timesheet\TimesheetTimezoneResolver;
use Nowo\TimeTrackBundle\Workday\WorkdayHistorySourceInterface;
use Nowo\TimeTrackBundle\Workday\WorkdayProviderInterface;
use Nowo\TimeTrackBundle\Workday\WorkdayState;
use Nowo\TimeTrackBundle\WorkingTime\CalendarDay;
use Nowo\TimeTrackBundle\WorkingTime\CalendarDayKind;
use Nowo\TimeTrackBundle\WorkingTime\CalendarDayNormalizer;
use Nowo\TimeTrackBundle\WorkingTime\WorkingCalendarProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_unique;
use function array_values;
use function count;
use function in_array;
use function max;
use function min;
use function substr;
use function usort;

/**
 * Since 1.26.0 (contract §24.3): the refusal checks the bundle runs **before any byte is accepted**, in the contract
 * order — policy on and kind enabled (`kind_off`), version acknowledged (`not_acknowledged`), SC-002: the worker's own
 * timer ran on `entryId` at that instant (`outside_timer`), the spec 198 schedule through the working-calendar port
 * (`outside_hours`, only when the host gives a schedule), since 1.41.0 the spec 198 register (`on_break`: inside a break
 * or outside the clocked `work` of the worker, only when the host has a workday provider; `workday_unknown` when the
 * register is on but the day cannot be read), and the age (`too_old`, older than `maxBufferAgeHours`). Since 1.41.0 an
 * activity slot is checked at the first and the last second the timer covers (schedule) and as a whole (register).
 *
 * `entryId` is the id of the worker's running timer (`GET timer`) or of one of their own recorded timer entries
 * (`web`, `extension` or `desktop` source; the id `timer/stop` and `timer/switch` answer). Manual, calendar and
 * imported entries never had a running timer and are refused.
 *
 * Stateless: every call reads the timer, the entry, the calendar and the working hours again (worker mode).
 */
final readonly class MonitoringGate
{
    /** The policy is off for the worker, or the kind is not enabled. */
    public const KIND_OFF = 'kind_off';

    /** The worker did not acknowledge the current policy version. */
    public const NOT_ACKNOWLEDGED = 'not_acknowledged';

    /** The worker's own timer did not run on `entryId` at that instant. */
    public const OUTSIDE_TIMER = 'outside_timer';

    /** Outside the worker's working calendar (spec 198 schedule, day off, leave). */
    public const OUTSIDE_HOURS = 'outside_hours';

    /**
     * Since 1.41.0 (owner decision D11): the worker's register (spec 198) shows a break, or no clocked `work` segment
     * (before clock-in, after clock-out), at that instant.
     */
    public const ON_BREAK = 'on_break';

    /**
     * Since 1.41.0 (audit ZBB-L2, owner: fail closed): the register is on for the worker but the day of the instant
     * cannot be read — the host's workday provider answers today only (implement
     * {@see WorkdayHistorySourceInterface}) — so buffered evidence of an earlier day is refused.
     */
    public const WORKDAY_UNKNOWN = 'workday_unknown';

    /** Older than `maxBufferAgeHours`. */
    public const TOO_OLD = 'too_old';

    /** Entry sources that come from a running timer. */
    private const TIMER_SOURCES = [TimeEntrySource::Web, TimeEntrySource::Extension, TimeEntrySource::Desktop];

    /**
     * Receives the timer and entry stores, the working calendar, the scope provider, the zone resolver, the
     * working-hours source and (since 1.41.0) the host's workday register (null = no `on_break` check).
     */
    public function __construct(
        private ActiveTimerRepositoryInterface $timers,
        private TimeEntryRepositoryInterface $entries,
        private WorkingCalendarProviderInterface $calendar,
        private TimesheetTimezoneResolver $timezoneResolver,
        private ClientUserProfileProviderInterface $profileProvider,
        private ?TimesheetScopeProviderInterface $scopeProvider = null,
        private ?WorkdayProviderInterface $workday = null,
    ) {
    }

    /**
     * Whether the worker may upload under this policy: they acknowledged the current version, or an earlier one when
     * the current version does not widen collection.
     */
    public static function isAcknowledged(MonitoringPolicy $policy, ?MonitoringAcknowledgement $last): bool
    {
        return !MonitoringContext::acknowledgementRequired($policy, $last);
    }

    /**
     * The first refusal reason for an instant (`takenAt` of a screenshot, the slot of an activity item or discard
     * marker), or null when it may be collected.
     *
     * @param string $kind Policy kind (`screenshots`, `apps`, `activity`)
     * @param DateTimeImmutable $at The instant (a screenshot's `takenAt`)
     * @param int $windowSeconds 0 for an instant; the slot length for a slot (`$at` = its start), which then only has to
     *                           overlap the timer
     * @param bool $checkAcknowledgement false for a discard marker (nothing is collected)
     * @param bool $checkSchedule false for a discard marker
     */
    public function refusal(
        UserInterface $user,
        MonitoringPolicy $policy,
        ?MonitoringAcknowledgement $last,
        string $kind,
        string $entryId,
        DateTimeImmutable $at,
        DateTimeImmutable $now,
        int $windowSeconds = 0,
        bool $checkAcknowledgement = true,
        bool $checkSchedule = true,
    ): ?string {
        if (!$policy->allows($kind)) {
            return self::KIND_OFF;
        }
        if ($checkAcknowledgement && !self::isAcknowledged($policy, $last)) {
            return self::NOT_ACKNOWLEDGED;
        }
        $span = $this->span($user, $entryId, $now);
        // The instant checked: the screenshot time, or the first second of the slot the timer covers.
        $instant = $span === null ? $at : max($at, $span['start']);
        $end     = $windowSeconds > 0 ? $at->getTimestamp() + $windowSeconds : $at->getTimestamp() + 1;
        if ($span === null || $instant->getTimestamp() < $span['start']->getTimestamp() || $instant->getTimestamp() > $span['end']->getTimestamp() || $instant->getTimestamp() >= $end) {
            return self::OUTSIDE_TIMER;
        }
        // Since 1.41.0 (audit ZBB-L3): a slot is checked over the whole span the timer covers — its first and its last
        // second — so a slot that runs into a break, the end of the schedule or a clock-out is refused, not kept whole.
        $last        = $windowSeconds > 0 ? max($instant->getTimestamp(), min($end, $span['end']->getTimestamp()) - 1) : $instant->getTimestamp();
        $lastInstant = $last === $instant->getTimestamp() ? $instant : new DateTimeImmutable('@' . $last);
        if ($checkSchedule && (!$this->insideSchedule($user, $span['scopeKey'], $instant) || !$this->insideSchedule($user, $span['scopeKey'], $lastInstant))) {
            return self::OUTSIDE_HOURS;
        }
        if ($checkSchedule) {
            $workday = $this->workdayRefusal($user, $span['scopeKey'], $instant, $lastInstant, $now);
            if ($workday !== null) {
                return $workday;
            }
        }
        if ($at->getTimestamp() < $now->getTimestamp() - $policy->maxBufferAgeHours * 3600) {
            return self::TOO_OLD;
        }

        return null;
    }

    /**
     * The interval of the worker's own timer on `entryId`: the running timer (until now) or a recorded timer entry.
     *
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable, scopeKey: string}|null
     */
    private function span(UserInterface $user, string $entryId, DateTimeImmutable $now): ?array
    {
        $userId = UserIdResolver::getId($user);
        $timer  = $this->timers->findByUserId($userId);
        if ($timer instanceof ActiveTimer && $timer->getId() === $entryId && $timer->getUserId() === $userId) {
            // Running: until now, with the accepted clock skew of the client.
            return ['start' => $timer->getStartedAt(), 'end' => $now->modify('+' . MonitoringSettings::CLOCK_SKEW_SECONDS . ' seconds'), 'scopeKey' => ($timer->hasTask() ? $this->scopeProvider?->scopeKeyForTask($user, $timer->getTaskId(), $timer->getBoardIdSnapshot()) : $timer->getScopeKey()) ?? ''];
        }
        // Since 1.37.0: by its exact id (audit LQ rule), the owner compared with ===.
        $entry = ExactTimeEntryLookup::find($this->entries, $entryId);
        if (!$entry instanceof TimeEntry || $entry->getUserId() !== $userId || !in_array($entry->getSource(), self::TIMER_SOURCES, true)) {
            return null;
        }

        return ['start' => $entry->getStartedAt(), 'end' => $entry->getEndedAt(), 'scopeKey' => $entry->getScopeKey() ?? ''];
    }

    /**
     * Inside the working calendar: no schedule from the host = no restriction; otherwise a working day with expected
     * time, and, when the worker's working hours have slots for that weekday, inside one of them.
     */
    private function insideSchedule(UserInterface $user, string $scopeKey, DateTimeImmutable $instant): bool
    {
        $zone  = $this->timezoneResolver->resolve($user, $scopeKey);
        $local = $instant->setTimezone(new DateTimeZone($zone));
        $date  = $local->format('Y-m-d');
        $day   = null;
        foreach ($this->calendar->days($user, $scopeKey, CalendarDayNormalizer::midnight($date, $zone), CalendarDayNormalizer::midnight($date, $zone)) ?? [] as $candidate) {
            if ($candidate instanceof CalendarDay && $candidate->date === $date) {
                $day = $candidate;
            }
        }
        if (!$day instanceof CalendarDay) {
            return true;
        }
        if ($day->kind !== CalendarDayKind::Working || $day->expectedSeconds <= 0) {
            return false;
        }

        $hours   = ClientWorkingHoursNormalizer::normalize($this->profileProvider->getWorkingHours($user));
        $weekday = (int) $local->format('N');
        $minute  = (int) $local->format('G') * 60 + (int) $local->format('i');
        $slots   = 0;
        foreach ($hours['slots'] ?? [] as $slot) {
            if ($slot['day'] !== $weekday) {
                continue;
            }
            ++$slots;
            $to = $slot['to'] === '23:59' ? 1440 : $this->minutes($slot['to']);
            if ($minute >= $this->minutes($slot['from']) && $minute < $to) {
                return true;
            }
        }

        return $slots === 0;
    }

    /**
     * Since 1.41.0 (D11; audit ZBB-L2, L3, L4): null when `[$from, $to]` (both inclusive) lies inside one stretch of
     * clocked `work` of the register, else `on_break`, or `workday_unknown` when the register is on for the worker but
     * the bundle cannot read the day (no {@see WorkdayHistorySourceInterface} and not today's day: fail closed).
     *
     * The days consulted are the local dates of both ends and the day before (a shift that started yesterday and runs
     * past midnight). Contiguous `work` segments are merged; an open `work` segment counts only while the status is
     * `working` (until now plus the clock skew); a segment whose times cannot be read covers nothing. No workday
     * provider, or a register that is off for the worker (null), restricts nothing.
     */
    private function workdayRefusal(UserInterface $user, string $scopeKey, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $now): ?string
    {
        if (!$this->workday instanceof WorkdayProviderInterface) {
            return null;
        }
        $states = $this->workdayStates($user, new DateTimeZone($this->timezoneResolver->resolve($user, $scopeKey)), $from, $to);
        if ($states === null) {
            return null;
        }
        if ($states === false) {
            return self::WORKDAY_UNKNOWN;
        }
        foreach ($this->workStretches($states, $now) as [$start, $end]) {
            if ($from->getTimestamp() >= $start && $to->getTimestamp() < $end) {
                return null;
            }
        }

        return self::ON_BREAK;
    }

    /**
     * The register's days around `[$from, $to]`: null when the register is off for the worker (today's answer is
     * null), false when it is on but a day of the span is unknown (today-only provider and another date, or a history
     * source that answers null for that day).
     *
     * @return false|list<WorkdayState>|null
     */
    private function workdayStates(UserInterface $user, DateTimeZone $zone, DateTimeImmutable $from, DateTimeImmutable $to): array|false|null
    {
        $dates   = $this->datesAround($zone, $from, $to);
        $history = $this->workday instanceof WorkdaySourceGate ? $this->workday->history() : ($this->workday instanceof WorkdayHistorySourceInterface ? $this->workday : null);
        if ($history instanceof WorkdayHistorySourceInterface) {
            // Audit ZBB-D-L1: whether the register is on for the worker comes from today's answer only. Once it is on,
            // a null day for the span's own dates is unknown (fail closed); the day before is optional (night shifts).
            if (!$this->workday?->today($user) instanceof WorkdayState) {
                return null;
            }
            $ref      = TimeTrackUserRefs::fromUser($user);
            $required = $this->datesAround($zone, $from, $to, false);
            $states   = [];
            for ($i = 0; $i < count($dates); ++$i) {
                $state = $history->dayFor($ref, $dates[$i]);
                if (!$state instanceof WorkdayState) {
                    if (in_array($dates[$i], $required, true)) {
                        return false;
                    }
                    continue;
                }
                $states[] = $state;
                // The register's zone may put the span on other dates: ask for those too.
                foreach ($this->datesAround(new DateTimeZone($state->timezone), $from, $to) as $date) {
                    if (!in_array($date, $dates, true)) {
                        $dates[] = $date;
                    }
                }
            }

            return $states;
        }
        $state = $this->workday?->today($user);
        if (!$state instanceof WorkdayState) {
            return null;
        }

        return in_array($state->date, $this->datesAround(new DateTimeZone($state->timezone), $from, $to), true) ? [$state] : false;
    }

    /**
     * The local dates of both ends and (unless `$withDayBefore` is false) the day before the first.
     *
     * @return list<string>
     */
    private function datesAround(DateTimeZone $zone, DateTimeImmutable $from, DateTimeImmutable $to, bool $withDayBefore = true): array
    {
        $first = $from->setTimezone($zone);
        $dates = [$first->format('Y-m-d'), $to->setTimezone($zone)->format('Y-m-d')];
        if ($withDayBefore) {
            $dates[] = $first->modify('-1 day')->format('Y-m-d');
        }

        return array_values(array_unique($dates));
    }

    /**
     * The clocked `work` stretches of the days, as merged `[start, end)` Unix-time pairs.
     *
     * @param list<WorkdayState> $states
     *
     * @return list<array{int, int}>
     */
    private function workStretches(array $states, DateTimeImmutable $now): array
    {
        $open = $now->getTimestamp() + MonitoringSettings::CLOCK_SKEW_SECONDS;
        $work = [];
        foreach ($states as $state) {
            foreach ($state->segments as $segment) {
                if (($segment['type'] ?? null) !== 'work') {
                    continue;
                }
                $start = Rfc3339::parse($segment['start'] ?? null, true);
                $end   = ($segment['end'] ?? null) === null ? ($state->status === 'working' ? $open : null) : Rfc3339::parse($segment['end'], true)?->getTimestamp();
                if ($start instanceof DateTimeImmutable && $end !== null && $end > $start->getTimestamp()) {
                    $work[] = [$start->getTimestamp(), $end];
                }
            }
        }
        usort($work, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        /** @var list<array{int, int}> $merged */
        $merged = [];
        foreach ($work as [$start, $end]) {
            $count = count($merged);
            if ($count > 0 && $start <= $merged[$count - 1][1]) {
                $merged[$count - 1] = [$merged[$count - 1][0], max($merged[$count - 1][1], $end)];
                continue;
            }
            $merged[] = [$start, $end];
        }

        return $merged;
    }

    private function minutes(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }
}
