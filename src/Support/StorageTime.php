<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

use function date_default_timezone_get;

/**
 * Since 1.13: instants as the bundle stores them. Doctrine writes the wall time of a `DateTimeImmutable` without its
 * offset and reads it back in the PHP default time zone (EF-M1), so every stored instant is first moved to that zone
 * and cut to the second; hashes use the UTC form ({@see self::utc()}), which does not depend on the zone.
 */
final class StorageTime
{
    /**
     * The same instant, whole seconds, in the PHP default time zone.
     */
    public static function normalize(DateTimeInterface $time): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $time->getTimestamp()))->setTimezone(new DateTimeZone(date_default_timezone_get()));
    }

    /**
     * `Y-m-d\TH:i:s\Z` in UTC (canonical form of hashed rows).
     */
    public static function utc(DateTimeInterface $time): string
    {
        return (new DateTimeImmutable('@' . $time->getTimestamp()))->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Local date (`Y-m-d`) of an instant in a time zone.
     */
    public static function localDate(DateTimeInterface $time, string $timezone): string
    {
        return (new DateTimeImmutable('@' . $time->getTimestamp()))->setTimezone(new DateTimeZone($timezone))->format('Y-m-d');
    }
}
