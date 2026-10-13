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
     * @return array{from: DateTimeImmutable, to: DateTimeImmutable}
     */
    public static function resolve(string $from, string $to): array
    {
        $toDate   = self::parseDate($to, self::DEFAULT_TO);
        $fromDate = self::parseDate($from, self::DEFAULT_FROM);

        $earliest = $toDate->modify(sprintf('-%d days', self::MAX_SPAN_DAYS));
        if ($fromDate < $earliest) {
            $fromDate = $earliest;
        }

        return ['from' => $fromDate, 'to' => $toDate];
    }

    public static function parseDate(string $value, string $fallback): DateTimeImmutable
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

        return new DateTimeImmutable($fallback);
    }
}
