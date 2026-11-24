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

use function in_array;
use function max;
use function substr;

/**
 * Since 1.26.0 (contract §24.3): the refusal checks the bundle runs **before any byte is accepted**, in the contract
 * order — policy on and kind enabled (`kind_off`), version acknowledged (`not_acknowledged`), SC-002: the worker's own
 * timer ran on `entryId` at that instant (`outside_timer`), the spec 198 schedule through the working-calendar port
 * (`outside_hours`, only when the host gives a schedule), since 1.41.0 the spec 198 register (`on_break`: inside a break
 * or outside every clocked `work` segment of that day, only when the host has a workday provider and it knows the day),
 * and the age (`too_old`, older than `maxBufferAgeHours`).
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
        if ($checkSchedule && !$this->insideSchedule($user, $span['scopeKey'], $instant)) {
            return self::OUTSIDE_HOURS;
        }
        if ($checkSchedule && !$this->insideWorkday($user, $span['scopeKey'], $instant, $now)) {
            return self::ON_BREAK;
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
     * Since 1.41.0 (D11): inside a clocked `work` segment of the register. No workday provider, a register that is off
     * for the worker, or a day the provider cannot give (only today without {@see WorkdayHistorySourceInterface}) = no
     * restriction. An open `work` segment counts only while the status is `working` (until now plus the clock skew);
     * a segment whose times cannot be read never covers anything (fail closed).
     */
    private function insideWorkday(UserInterface $user, string $scopeKey, DateTimeImmutable $instant, DateTimeImmutable $now): bool
    {
        if (!$this->workday instanceof WorkdayProviderInterface) {
            return true;
        }
        $state = $this->workdayOn($user, $instant->setTimezone(new DateTimeZone($this->timezoneResolver->resolve($user, $scopeKey)))->format('Y-m-d'));
        if ($state instanceof WorkdayState && $instant->setTimezone(new DateTimeZone($state->timezone))->format('Y-m-d') !== $state->date) {
            // The register's zone puts the instant on another date: ask for that date instead.
            $state = $this->workdayOn($user, $instant->setTimezone(new DateTimeZone($state->timezone))->format('Y-m-d'));
        }
        if (!$state instanceof WorkdayState) {
            return true;
        }
        $at = $instant->getTimestamp();
        foreach ($state->segments as $segment) {
            if (($segment['type'] ?? null) !== 'work') {
                continue;
            }
            $start = Rfc3339::parse($segment['start'] ?? null, true);
            $end   = ($segment['end'] ?? null) === null
                ? ($state->status === 'working' ? $now->modify('+' . MonitoringSettings::CLOCK_SKEW_SECONDS . ' seconds') : null)
                : Rfc3339::parse($segment['end'], true);
            if ($start instanceof DateTimeImmutable && $end instanceof DateTimeImmutable && $at >= $start->getTimestamp() && $at < $end->getTimestamp()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The register's day for a local date: from {@see WorkdayHistorySourceInterface} when the host implements it, else
     * today's day when it is that date, else null (unknown).
     */
    private function workdayOn(UserInterface $user, string $date): ?WorkdayState
    {
        $history = $this->workday instanceof WorkdaySourceGate ? $this->workday->history() : ($this->workday instanceof WorkdayHistorySourceInterface ? $this->workday : null);
        if ($history instanceof WorkdayHistorySourceInterface) {
            $state = $history->dayFor(TimeTrackUserRefs::fromUser($user), $date);

            return $state instanceof WorkdayState && $state->date === $date ? $state : null;
        }
        $state = $this->workday?->today($user);

        return $state instanceof WorkdayState && $state->date === $date ? $state : null;
    }

    private function minutes(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }
}
