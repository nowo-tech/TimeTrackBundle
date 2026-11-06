<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Support;

use DateTimeImmutable;
use DateTimeZone;

use function date_default_timezone_get;
use function intdiv;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * Since 1.14: strict RFC 3339 date-times of the 1.14 client routes — `YYYY-MM-DDTHH:MM[:SS[.ffffff]]` with `Z` or an
 * offset between -12:00 and +14:00, nothing else (no date-only value, no week syntax, no space for a decoded `+`). The
 * fraction is dropped (storage is to the second); the result is in the PHP default zone (what Doctrine stores, EF-M1).
 */
final class Rfc3339
{
    /** Earliest offset, in minutes. */
    public const MIN_OFFSET_MINUTES = -720;

    /** Latest offset, in minutes. */
    public const MAX_OFFSET_MINUTES = 840;

    /**
     * The instant, or null when the value is not a valid date-time of this profile.
     *
     * @param bool $secondsOptional Accept `HH:MM` without seconds (meetings API)
     */
    public static function parse(mixed $value, bool $secondsOptional = false): ?DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})(?::(\d{2})(?:\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/D', $value, $parts) !== 1) {
            return null;
        }
        if ($parts[3] === '' && !$secondsOptional) {
            return null;
        }
        $normalized = sprintf('%sT%s:%s%s', $parts[1], $parts[2], $parts[3] !== '' ? $parts[3] : '00', $parts[4] === 'Z' ? '+00:00' : $parts[4]);
        $time       = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $normalized);
        if (!$time instanceof DateTimeImmutable || $time->format('Y-m-d\TH:i:sP') !== $normalized) {
            return null;
        }
        $offset = intdiv($time->getOffset(), 60);
        if ($offset < self::MIN_OFFSET_MINUTES || $offset > self::MAX_OFFSET_MINUTES) {
            return null;
        }

        return $time->setTimezone(new DateTimeZone(date_default_timezone_get()));
    }
}
