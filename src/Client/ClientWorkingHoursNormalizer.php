<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use DateTimeZone;

use function array_is_list;
use function array_key_exists;
use function array_key_last;
use function count;
use function in_array;
use function intdiv;
use function is_array;
use function is_int;
use function is_string;
use function max;
use function preg_match;
use function sprintf;
use function strtolower;
use function usort;

/**
 * Since 1.12: the working hours of `GET me` in the one shape the clients read,
 * `{timezone: ?string, slots: list<{day: 1-7, from: "HH:MM", to: "HH:MM"}>}` (`day`: ISO weekday, 1 = Monday;
 * `from` inclusive, `to` exclusive, local time in `timezone`).
 *
 * {@see ClientUserProfileProviderInterface::getWorkingHours()} may return either that `slots` shape or the documented
 * 1.9 `days` shape `{timezone, days: {mon: [["09:00", "17:00"]], ...}}` (day keys `mon`…`sun` or `monday`…`sunday`, any
 * case; a range is `["09:00", "17:00"]` or `{from, to}`). When both keys are present `slots` wins.
 *
 * - Times are `H:MM` / `HH:MM` (00:00–23:59); `24:00` is accepted as an end and sent as `23:59`.
 * - An overnight range (`from` after `to`, e.g. 22:00–06:00) is split at midnight: `{day, 22:00, 23:59}` and
 *   `{next day, 00:00, 06:00}` (Sunday wraps to Monday).
 * - Overlapping or touching ranges of one day are merged; slots are sorted by day, then start.
 * - Fail closed: anything else (unknown key shape, bad day, bad time, empty range, unknown time zone, more than
 *   {@see self::MAX_SLOTS} ranges) makes the whole value null.
 */
final class ClientWorkingHoursNormalizer
{
    /** Most ranges accepted from the provider (more = malformed, null). */
    public const MAX_SLOTS = 100;

    private const DAY_NAMES = [
        'mon'    => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7,
        'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6, 'sunday' => 7,
    ];

    private const END_OF_DAY = 1440;

    /**
     * Normalised working hours, or null when unknown or malformed.
     *
     * @param array<mixed>|null $hours Provider output (`slots` or `days` shape)
     *
     * @return array{timezone: string|null, slots: list<array{day: int, from: string, to: string}>}|null
     */
    public static function normalize(?array $hours): ?array
    {
        if ($hours === null) {
            return null;
        }

        $timezone = $hours['timezone'] ?? null;
        if ($timezone !== null && (!is_string($timezone) || !in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true))) {
            return null;
        }

        $ranges = array_key_exists('slots', $hours) ? self::fromSlots($hours['slots']) : (array_key_exists('days', $hours) ? self::fromDays($hours['days']) : null);
        if ($ranges === null || count($ranges) > self::MAX_SLOTS) {
            return null;
        }

        /** @var list<array{int, int, int}> $minutes */
        $minutes = [];
        foreach ($ranges as [$day, $from, $to]) {
            $start = self::minutes($from, false);
            $end   = self::minutes($to, true);
            if ($day === null || $start === null || $end === null || $start === $end) {
                return null;
            }

            if ($start < $end) {
                $minutes[] = [$day, $start, $end];
                continue;
            }

            $minutes[] = [$day, $start, self::END_OF_DAY];
            if ($end > 0) {
                $minutes[] = [$day % 7 + 1, 0, $end];
            }
        }

        return ['timezone' => $timezone, 'slots' => self::merge($minutes)];
    }

    /**
     * Ranges of the `slots` shape (`day` must be an int 1-7), or null when malformed.
     *
     * @return list<array{int|null, mixed, mixed}>|null
     */
    private static function fromSlots(mixed $slots): ?array
    {
        if (!is_array($slots) || !array_is_list($slots)) {
            return null;
        }

        $ranges = [];
        foreach ($slots as $slot) {
            if (!is_array($slot)) {
                return null;
            }

            $day      = $slot['day'] ?? null;
            $ranges[] = [is_int($day) && $day >= 1 && $day <= 7 ? $day : null, $slot['from'] ?? null, $slot['to'] ?? null];
        }

        return $ranges;
    }

    /**
     * Ranges of the 1.9 `days` shape, or null when malformed (unknown day key included).
     *
     * @return list<array{int, mixed, mixed}>|null
     */
    private static function fromDays(mixed $days): ?array
    {
        if (!is_array($days)) {
            return null;
        }

        $ranges = [];
        foreach ($days as $name => $list) {
            if (!is_array($list) || !array_is_list($list)) {
                return null;
            }

            $day = is_string($name) ? (self::DAY_NAMES[strtolower($name)] ?? null) : null;
            if ($day === null) {
                return null;
            }

            foreach ($list as $range) {
                if (!is_array($range)) {
                    return null;
                }

                $ranges[] = array_is_list($range) && count($range) === 2
                    ? [$day, $range[0], $range[1]]
                    : [$day, $range['from'] ?? null, $range['to'] ?? null];
            }
        }

        return $ranges;
    }

    /**
     * Minutes since midnight of `H:MM` / `HH:MM` (`24:00` only as an end), or null.
     */
    private static function minutes(mixed $time, bool $isEnd): ?int
    {
        if (!is_string($time) || preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/D', $time, $parts) !== 1) {
            return $isEnd && $time === '24:00' ? self::END_OF_DAY : null;
        }

        return (int) $parts[1] * 60 + (int) $parts[2];
    }

    /**
     * Merged, sorted slots (overlapping or touching ranges of one day become one).
     *
     * @param list<array{int, int, int}> $ranges
     *
     * @return list<array{day: int, from: string, to: string}>
     */
    private static function merge(array $ranges): array
    {
        usort($ranges, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        /** @var list<array{int, int, int}> $merged */
        $merged = [];
        foreach ($ranges as [$day, $from, $to]) {
            $last = array_key_last($merged);
            if ($last !== null && $merged[$last][0] === $day && $from <= $merged[$last][2]) {
                $merged[$last] = [$day, $merged[$last][1], max($to, $merged[$last][2])];
                continue;
            }

            $merged[] = [$day, $from, $to];
        }

        $slots = [];
        foreach ($merged as [$day, $from, $to]) {
            $slots[] = ['day' => $day, 'from' => self::time($from), 'to' => self::time($to === self::END_OF_DAY ? $to - 1 : $to)];
        }

        return $slots;
    }

    /**
     * `HH:MM` of minutes since midnight.
     */
    private static function time(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
