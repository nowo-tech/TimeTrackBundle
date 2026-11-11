<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use DateTimeZone;
use InvalidArgumentException;

use function in_array;
use function is_string;
use function preg_match;
use function strlen;
use function strtolower;

/**
 * Since 1.19: field helpers shared by the calendar readers (pure functions, no state).
 *
 * @internal
 */
final class CalendarFields
{
    /** Longest accepted provider calendar id. */
    public const MAX_CALENDAR_ID_LENGTH = 512;

    /**
     * A string field, or ''.
     */
    public static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * A lower-case e-mail field, or null when empty.
     */
    public static function email(mixed $value): ?string
    {
        $email = strtolower(self::text($value));

        return $email === '' ? null : $email;
    }

    /**
     * An IANA zone name the PHP runtime knows, or null.
     */
    public static function zone(mixed $value): ?string
    {
        return is_string($value) && strlen($value) <= 64 && in_array($value, DateTimeZone::listIdentifiers(), true) ? $value : null;
    }

    /**
     * The calendar id to read: non-empty, at most 512 bytes, no control characters, not only dots (a `.` / `..` path
     * segment; audit LZ-I2). Hosts pass only ids that {@see CalendarReaderInterface::calendars()} returned for the same
     * connection.
     *
     * @throws InvalidArgumentException
     */
    public static function calendarId(string $calendarId): string
    {
        if ($calendarId === '' || strlen($calendarId) > self::MAX_CALENDAR_ID_LENGTH || preg_match('/[\x00-\x1F\x7F]|^\.+$/D', $calendarId) === 1) {
            throw new InvalidArgumentException('The calendar id must be 1-512 characters without control characters and not only dots.');
        }

        return $calendarId;
    }
}
