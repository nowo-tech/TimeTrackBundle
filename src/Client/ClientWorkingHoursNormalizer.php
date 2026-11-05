<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use DateTimeZone;
use Psr\Log\LoggerInterface;

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
 *   {@see self::MAX_SLOTS} ranges) is sent as `{timezone, slots: []}` — never inside working hours, so the clients
 *   send no notifications that are limited to working hours — and logged as a warning. Only a null provider value (unknown) stays null.
 */
final class ClientWorkingHoursNormalizer
{
    /** Most ranges accepted from the provider (more = malformed); counted while reading, before anything is built. */
    public const MAX_SLOTS = 100;

    private const DAY_NAMES = [
        'mon'    => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7,
        'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6, 'sunday' => 7,
    ];

    private const END_OF_DAY = 1440;

    /**
     * Normalised working hours: null only when the provider returned null (unknown); a malformed value fails closed
     * as `{timezone: <the given zone when valid, else null>, slots: []}` (never inside working hours) and logs a
     * warning with the reason (no user data).
     *
     * @param array<mixed>|null $hours Provider output (`slots` or `days` shape)
     *
     * @return array{timezone: string|null, slots: list<array{day: int, from: string, to: string}>}|null
     */
    public static function normalize(?array $hours, ?LoggerInterface $logger = null): ?array
    {
        if ($hours === null) {
            return null;
        }

        $timezone  = $hours['timezone'] ?? null;
        $validZone = $timezone === null || (is_string($timezone) && in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true));
        $result    = $validZone ? self::slots($hours) : 'unknown time zone';
        if (is_string($result)) {
            $logger?->warning('TimeTrack client working hours are malformed; sent as no working hours.', ['bundle' => 'time_track', 'action' => 'client_me', 'reason' => $result]);

            return ['timezone' => $validZone && is_string($timezone) ? $timezone : null, 'slots' => []];
        }

        return ['timezone' => $timezone, 'slots' => $result];
    }

    /**
     * Normalised slots of either shape, or the reason they are malformed.
     *
     * @param array<mixed> $hours
     *
     * @return list<array{day: int, from: string, to: string}>|string
     */
    private static function slots(array $hours): array|string
    {
        $ranges = array_key_exists('slots', $hours) ? self::fromSlots($hours['slots']) : (array_key_exists('days', $hours) ? self::fromDays($hours['days']) : 'neither slots nor days');
        if (is_string($ranges)) {
            return $ranges;
        }

        /** @var list<array{int, int, int}> $minutes */
        $minutes = [];
        foreach ($ranges as [$day, $from, $to]) {
            $start = self::minutes($from, false);
            $end   = self::minutes($to, true);
            if ($day === null) {
                return 'invalid day';
            }

            if ($start === null || $end === null) {
                return 'invalid time';
            }

            if ($start === $end) {
                return 'empty range';
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

        return self::merge($minutes);
    }

    /**
     * Ranges of the `slots` shape (`day` must be an int 1-7), or the reason they are malformed.
     *
     * @return list<array{int|null, mixed, mixed}>|string
     */
    private static function fromSlots(mixed $slots): array|string
    {
        if (!is_array($slots) || !array_is_list($slots)) {
            return 'slots is not a list';
        }

        if (count($slots) > self::MAX_SLOTS) {
            return 'too many ranges';
        }

        $ranges = [];
        foreach ($slots as $slot) {
            if (!is_array($slot)) {
                return 'slot is not an object';
            }

            $day      = $slot['day'] ?? null;
            $ranges[] = [is_int($day) && $day >= 1 && $day <= 7 ? $day : null, $slot['from'] ?? null, $slot['to'] ?? null];
        }

        return $ranges;
    }

    /**
     * Ranges of the 1.9 `days` shape, or the reason they are malformed (unknown day key included).
     *
     * @return list<array{int, mixed, mixed}>|string
     */
    private static function fromDays(mixed $days): array|string
    {
        if (!is_array($days)) {
            return 'days is not an object';
        }

        $ranges = [];
        foreach ($days as $name => $list) {
            $day = is_string($name) ? (self::DAY_NAMES[strtolower($name)] ?? null) : null;
            if ($day === null) {
                return 'invalid day';
            }

            if (!is_array($list) || !array_is_list($list)) {
                return 'day ranges are not a list';
            }

            if (count($ranges) + count($list) > self::MAX_SLOTS) {
                return 'too many ranges';
            }

            foreach ($list as $range) {
                if (!is_array($range)) {
                    return 'range is not a list';
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
