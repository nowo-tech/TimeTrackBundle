<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Support;

use DateTimeImmutable;

use function sprintf;
use function str_contains;

use const DATE_ATOM;

/**
 * Parses the `from` / `to` query parameters of the reports page and the client entries API.
 *
 * Only strict formats are accepted (ATOM / RFC 3339 or `Y-m-d`); anything else falls back to the
 * default instead of reaching the lenient `DateTimeImmutable` parser (which throws on garbage). The
 * resulting span is capped so a single request cannot load an unbounded number of entries.
 */
final class QueryPeriodResolver
{
    public const MAX_SPAN_DAYS = 366;

    public const DEFAULT_FROM = '-7 days';

    public const DEFAULT_TO = 'now';

    /**
     * Resolves the period from the raw query values (defaults: the last 7 days; span capped at 366 days).
     *
     * @param string $from Raw `from` value
     * @param string $to Raw `to` value
     * @param DateTimeImmutable|null $now Reference time of the defaults (from the injected clock; default: now)
     *
     * @return array{from: DateTimeImmutable, to: DateTimeImmutable}
     */
    public static function resolve(string $from, string $to, ?DateTimeImmutable $now = null): array
    {
        $toDate   = self::parseDate($to, self::DEFAULT_TO, $now);
        $fromDate = self::parseDate($from, self::DEFAULT_FROM, $now);

        $earliest = $toDate->modify(sprintf('-%d days', self::MAX_SPAN_DAYS));
        if ($fromDate < $earliest) {
            $fromDate = $earliest;
        }

        return ['from' => $fromDate, 'to' => $toDate];
    }

    /**
     * Parses an ATOM / RFC 3339 or `Y-m-d` value, else returns the fallback.
     *
     * @param string $value Raw value
     * @param string $fallback Relative format applied to $now when the value is invalid (`-7 days`, `now`)
     * @param DateTimeImmutable|null $now Reference time of the fallback (default: now)
     */
    public static function parseDate(string $value, string $fallback, ?DateTimeImmutable $now = null): DateTimeImmutable
    {
        if ($value !== '' && !str_contains($value, "\0")) {
            foreach ([DATE_ATOM, '!Y-m-d'] as $format) {
                $parsed = DateTimeImmutable::createFromFormat($format, $value);
                $errors = DateTimeImmutable::getLastErrors();
                if ($parsed instanceof DateTimeImmutable && ($errors === false || $errors['warning_count'] === 0)) {
                    return $parsed;
                }
            }
        }

        // modify() returns false (PHP 8.2) for an invalid relative format: fall back to the system clock parser.
        return ($now instanceof DateTimeImmutable ? $now->modify($fallback) : false) ?: new DateTimeImmutable($fallback);
    }
}
