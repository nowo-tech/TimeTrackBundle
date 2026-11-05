<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use DateTimeImmutable;
use DateTimeZone;

use function array_is_list;
use function array_values;
use function checkdate;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function ksort;
use function preg_match;
use function preg_replace;
use function sprintf;
use function trim;

use const SORT_STRING;

/**
 * Since 1.12: validates the host's days off ({@see ClientUserDaysOffProviderInterface}) for `GET me.daysOff`.
 *
 * Strict: the list must be a JSON list of at most {@see self::MAX_ENTRIES} entries, each with a real `YYYY-MM-DD`
 * `date`, a `kind` of {@see self::KINDS} and an optional `label` (string or null, valid UTF-8, no control characters).
 * Any malformed entry makes the whole value null (fail closed: the clients treat null as "unknown"). Labels are
 * trimmed, empty ones become null and long ones are cut to {@see self::LABEL_MAX_LENGTH} characters. Entries outside
 * the requested range are dropped, a date listed twice keeps its first entry, and the result is sorted by date. Nothing
 * is HTML-escaped: the value is JSON, the clients render it as text.
 */
final class ClientDaysOffNormalizer
{
    /** Kinds of a day off. */
    public const KINDS = ['holiday', 'vacation'];

    /** Most entries accepted from the provider (more = malformed, null). */
    public const MAX_ENTRIES = 400;

    /** Longest label sent, in characters. */
    public const LABEL_MAX_LENGTH = 80;

    /** Days ahead of today covered by `daysOff` (today → today + 60). */
    public const HORIZON_DAYS = 60;

    /**
     * The range of `daysOff` for `$now`: start of today and end of today + {@see self::HORIZON_DAYS} in `$timezone`
     * (a valid time zone identifier).
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    public static function range(DateTimeImmutable $now, string $timezone): array
    {
        $from = $now->setTimezone(new DateTimeZone($timezone))->setTime(0, 0);

        return [$from, $from->modify(sprintf('+%d days', self::HORIZON_DAYS))->setTime(23, 59, 59)];
    }

    /**
     * Normalised days off between `$fromDate` and `$toDate` (inclusive `YYYY-MM-DD`), or null when unknown or malformed.
     *
     * @param array<mixed>|null $daysOff Provider output
     *
     * @return list<array{date: string, kind: string, label: string|null}>|null
     */
    public static function normalize(?array $daysOff, string $fromDate, string $toDate): ?array
    {
        if ($daysOff === null || !array_is_list($daysOff) || count($daysOff) > self::MAX_ENTRIES) {
            return null;
        }

        $byDate = [];
        foreach ($daysOff as $item) {
            $entry = self::entry($item);
            if ($entry === null) {
                return null;
            }

            if ($entry['date'] < $fromDate || $entry['date'] > $toDate || isset($byDate[$entry['date']])) {
                continue;
            }

            $byDate[$entry['date']] = $entry;
        }

        ksort($byDate, SORT_STRING);

        return array_values($byDate);
    }

    /**
     * One validated entry, or null when malformed.
     *
     * @return array{date: string, kind: string, label: string|null}|null
     */
    private static function entry(mixed $item): ?array
    {
        if (!is_array($item)) {
            return null;
        }

        $date  = $item['date'] ?? null;
        $kind  = $item['kind'] ?? null;
        $label = $item['label'] ?? null;
        if (!is_string($date) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts) !== 1
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        if (!in_array($kind, self::KINDS, true)) {
            return null;
        }

        if ($label !== null && (!is_string($label) || preg_match('/^[^\p{Cc}]*$/uD', $label) !== 1)) {
            return null;
        }

        return ['date' => $date, 'kind' => $kind, 'label' => self::label($label)];
    }

    /**
     * Trimmed label cut to {@see self::LABEL_MAX_LENGTH} characters (null when empty).
     */
    private static function label(?string $label): ?string
    {
        $label = trim((string) $label);
        if ($label === '') {
            return null;
        }

        return trim((string) preg_replace('/^(.{' . self::LABEL_MAX_LENGTH . '}).+$/us', '$1', $label));
    }
}
