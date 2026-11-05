<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\WorkingTime;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\Timesheet\Period;
use Nowo\TimeTrackBundle\Timesheet\TimesheetTimezoneResolver;
use Psr\Log\LoggerInterface;

use function is_finite;

/**
 * Since 1.13: validates a calendar provider's days (contract §4.2) and fails closed: a malformed day (bad or
 * out-of-range date, duplicate, `expectedSeconds` outside 0..86400, bad zone, fraction outside 0..1, cap outside
 * 0..86400) becomes `kind: none` with a warning log that carries the reason only; a missing date is `none` too; a label
 * survives on holidays only.
 */
final class CalendarDayNormalizer
{
    /** Longest day, in seconds. */
    public const MAX_SECONDS = 86_400;

    /**
     * Every local date of [$fromDate, $toDate] with its validated day, in order (null when the provider returned null).
     *
     * @param list<CalendarDay>|null $days
     *
     * @return array<string, CalendarDay>|null
     */
    public static function normalize(?array $days, string $fromDate, string $toDate, string $fallbackTimezone, ?LoggerInterface $logger = null): ?array
    {
        if ($days === null) {
            return null;
        }

        $valid = [];
        foreach ($days as $day) {
            $reason = self::problem($day, $fromDate, $toDate);
            if ($reason === null && isset($valid[$day->date])) {
                $reason = 'duplicate';
            }
            if ($reason !== null) {
                $logger?->warning('TimeTrack calendar day ignored.', ['bundle' => 'time_track', 'action' => 'calendar_day', 'reason' => $reason]);
                continue;
            }
            $valid[$day->date] = $day->kind === CalendarDayKind::Holiday || $day->label === null
                ? $day
                : new CalendarDay($day->date, $day->timezone, $day->expectedSeconds, $day->kind, null, $day->fraction, $day->ordinaryMaxSeconds);
        }

        $out  = [];
        $date = Period::date($fromDate);
        $end  = Period::date($toDate);
        while ($date <= $end) {
            $key       = $date->format('Y-m-d');
            $out[$key] = $valid[$key] ?? new CalendarDay($key, $fallbackTimezone, 0, CalendarDayKind::None);
            $date      = $date->modify('+1 day');
        }

        return $out;
    }

    private static function problem(CalendarDay $day, string $fromDate, string $toDate): ?string
    {
        try {
            Period::date($day->date);
        } catch (InvalidArgumentException) {
            return 'date';
        }

        return match (true) {
            $day->date < $fromDate || $day->date > $toDate                                                                      => 'out_of_range',
            $day->expectedSeconds < 0 || $day->expectedSeconds > self::MAX_SECONDS                                              => 'expected_seconds',
            !TimesheetTimezoneResolver::isValid($day->timezone)                                                                 => 'timezone',
            !is_finite($day->fraction) || $day->fraction < 0.0 || $day->fraction > 1.0                                          => 'fraction',
            $day->ordinaryMaxSeconds !== null && ($day->ordinaryMaxSeconds < 0 || $day->ordinaryMaxSeconds > self::MAX_SECONDS) => 'ordinary_max_seconds',
            default                                                                                                             => null,
        };
    }

    /**
     * Local midnight of a date in a zone.
     */
    public static function midnight(string $date, string $timezone): DateTimeImmutable
    {
        return new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone($timezone));
    }
}
