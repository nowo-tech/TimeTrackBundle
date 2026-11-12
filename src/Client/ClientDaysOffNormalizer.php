<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;

use function array_is_list;
use function array_slice;
use function array_unique;
use function array_values;
use function checkdate;
use function count;
use function function_exists;
use function grapheme_substr;
use function in_array;
use function is_array;
use function is_string;
use function ksort;
use function mb_substr;
use function preg_match;
use function preg_replace;
use function sprintf;
use function strtolower;
use function trim;

use const SORT_STRING;

/**
 * Since 1.12: validates the host's days off ({@see ClientUserDaysOffProviderInterface}) for `GET me.daysOff`.
 *
 * The value must be a list (else null, logged); only its first {@see self::MAX_ENTRIES} entries are read. An entry needs
 * a real `YYYY-MM-DD` `date` and a `kind` of {@see self::KINDS} (any case); a malformed entry is dropped alone and
 * logged (one warning per call, with counts and reasons, no user data). Labels are plain text: control, bidi and
 * zero-width characters are removed (line breaks and tabs become spaces), Unicode blanks are trimmed, empty labels
 * become null, long ones are cut to {@see self::LABEL_MAX_LENGTH} grapheme clusters (code points without ext-intl); a label that is not a string or
 * not valid UTF-8 becomes null (the day off is kept). Entries outside the requested range are dropped, a date listed
 * twice keeps its first entry, and the result is sorted by date. Nothing is HTML-escaped: the value is JSON, the
 * clients render it as text.
 *
 * Since 1.22 a `leave` day (approved leave of any type, spec 204) is accepted too, and its label is **always** null:
 * whatever label the provider gives is dropped (reason `leave label dropped`), so no leave type or reason reaches the
 * user's devices through `daysOff`.
 */
final class ClientDaysOffNormalizer
{
    /** Kinds of a day off (`leave` since 1.22, Beacon spec 204: its label is always sent as null). */
    public const KINDS = ['holiday', 'vacation', 'leave'];

    /** Most entries read from the provider (the rest is dropped and logged). */
    public const MAX_ENTRIES = 400;

    /** Longest label sent, in grapheme clusters (user-perceived characters). */
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
     * Normalised days off between `$fromDate` and `$toDate` (inclusive `YYYY-MM-DD`), or null when unknown or not a list.
     *
     * @param array<mixed>|null $daysOff Provider output
     *
     * @return list<array{date: string, kind: string, label: string|null}>|null
     */
    public static function normalize(?array $daysOff, string $fromDate, string $toDate, ?LoggerInterface $logger = null): ?array
    {
        if ($daysOff === null) {
            return null;
        }

        if (!array_is_list($daysOff)) {
            self::warn($logger, ['not a list'], 0);

            return null;
        }

        $reasons = [];
        $dropped = 0;
        if (count($daysOff) > self::MAX_ENTRIES) {
            $dropped   = count($daysOff) - self::MAX_ENTRIES;
            $reasons[] = 'too many entries';
            $daysOff   = array_slice($daysOff, 0, self::MAX_ENTRIES);
        }

        $byDate = [];
        foreach ($daysOff as $item) {
            $entry = self::entry($item, $reasons);
            if ($entry === null) {
                ++$dropped;
                continue;
            }

            if ($entry['date'] < $fromDate || $entry['date'] > $toDate || isset($byDate[$entry['date']])) {
                continue;
            }

            $byDate[$entry['date']] = $entry;
        }

        if ($reasons !== []) {
            self::warn($logger, $reasons, $dropped);
        }

        ksort($byDate, SORT_STRING);

        return array_values($byDate);
    }

    /**
     * One validated entry, or null when it must be dropped (the reason is added to `$reasons`).
     *
     * @param list<string> $reasons
     *
     * @return array{date: string, kind: string, label: string|null}|null
     */
    private static function entry(mixed $item, array &$reasons): ?array
    {
        if (!is_array($item)) {
            $reasons[] = 'entry is not an object';

            return null;
        }

        $date = $item['date'] ?? null;
        if (!is_string($date) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts) !== 1
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            $reasons[] = 'invalid date';

            return null;
        }

        $kind = $item['kind'] ?? null;
        $kind = is_string($kind) ? strtolower(trim($kind)) : null;
        if (!in_array($kind, self::KINDS, true)) {
            $reasons[] = 'invalid kind';

            return null;
        }

        $label = $item['label'] ?? null;
        if ($label !== null && (!is_string($label) || preg_match('//u', $label) !== 1)) {
            $reasons[] = 'invalid label';
            $label     = null;
        }

        if ($kind === 'leave' && $label !== null) {
            // Since 1.22: a leave day never carries a label (no type, no reason; spec 204 privacy).
            $reasons[] = 'leave label dropped';
            $label     = null;
        }

        return ['date' => $date, 'kind' => $kind, 'label' => self::label($label)];
    }

    /**
     * Plain-text label: control characters become spaces, bidi / zero-width characters are removed (ZWJ is kept for
     * emoji sequences), Unicode blanks trimmed, cut to {@see self::LABEL_MAX_LENGTH} grapheme clusters; null when empty.
     * Public since 1.22 (also cleans the `GET workday.leave` type); the caller makes sure the text is valid UTF-8.
     */
    public static function label(?string $label): ?string
    {
        $label = (string) preg_replace('/\p{Cc}+/u', ' ', (string) $label);
        $label = (string) preg_replace('/[\x{200B}\x{200C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{061C}\x{FEFF}\x{00AD}]/u', '', $label);
        $label = self::trim($label);
        if ($label === '') {
            return null;
        }

        // Grapheme-safe cut with ext-intl (keeps combining marks and ZWJ emoji whole); code points without it.
        $cut = function_exists('grapheme_substr') ? grapheme_substr($label, 0, self::LABEL_MAX_LENGTH) : mb_substr($label, 0, self::LABEL_MAX_LENGTH);

        return self::trim((string) $cut);
    }

    /**
     * Trims Unicode whitespace and separators (NBSP included).
     */
    private static function trim(string $text): string
    {
        return (string) preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $text);
    }

    /**
     * One warning for the call: dropped entries and distinct reasons, no user data.
     *
     * @param list<string> $reasons
     */
    private static function warn(?LoggerInterface $logger, array $reasons, int $dropped): void
    {
        $logger?->warning('TimeTrack client days off from the provider are malformed; bad entries were dropped or cleaned.', [
            'bundle'  => 'time_track',
            'action'  => 'client_me',
            'dropped' => $dropped,
            'reasons' => array_values(array_unique($reasons)),
        ]);
    }
}
