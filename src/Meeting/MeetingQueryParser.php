<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Meeting;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\TimeTrackBundle\Support\Rfc3339;

use function array_keys;
use function ctype_digit;
use function in_array;
use function is_string;
use function strlen;
use function timezone_identifiers_list;

/**
 * Since 1.14 (contract §22.3): validates `GET me/meetings` — `from`, `to` (RFC 3339 with an offset, seconds optional),
 * `tz` (IANA) and `limit` (1-200), and nothing else. `from` defaults to now (to the minute) and may go back
 * `meetings.max_past_days`; `to` defaults to `from` + 24 h, must be after `from`, within `meetings.max_range_days` of it
 * and at most 14 days ahead.
 */
final readonly class MeetingQueryParser
{
    /** Accepted query parameters. */
    public const PARAMETERS = ['from', 'to', 'tz', 'limit'];

    /** Largest `limit`. */
    public const MAX_LIMIT = 200;

    /** Furthest `to`, in days from now. */
    public const MAX_FUTURE_DAYS = 14;

    /**
     * Receives the settings.
     */
    public function __construct(private MeetingSettings $settings)
    {
    }

    /**
     * The query of a request.
     *
     * @param array<array-key, mixed> $parameters The query parameters
     * @param string|null $defaultTimezone The user's working-hours zone (null = UTC)
     *
     * @throws MeetingRequestException
     */
    public function parse(array $parameters, DateTimeImmutable $now, ?string $defaultTimezone): MeetingQuery
    {
        foreach (array_keys($parameters) as $name) {
            if (!in_array($name, self::PARAMETERS, true)) {
                throw new MeetingRequestException('unknown_parameter', (string) $name);
            }
        }
        $utc    = new DateTimeZone('UTC');
        $nowUtc = $now->setTimezone($utc);
        $minute = $nowUtc->setTime((int) $nowUtc->format('H'), (int) $nowUtc->format('i'));
        $from   = isset($parameters['from']) ? $this->time($parameters['from'], 'from') : $minute;
        $to     = isset($parameters['to']) ? $this->time($parameters['to'], 'to') : $from->modify('+24 hours');
        $fromTs = $from->getTimestamp();
        $toTs   = $to->getTimestamp();
        $nowTs  = $now->getTimestamp();
        if ($fromTs < $nowTs - $this->settings->maxPastDays * 86_400) {
            throw new MeetingRequestException('invalid_range', 'from');
        }
        if ($toTs <= $fromTs || $toTs - $fromTs > $this->settings->maxRangeDays * 86_400 || $toTs > $nowTs + self::MAX_FUTURE_DAYS * 86_400) {
            throw new MeetingRequestException('invalid_range', 'to');
        }

        return new MeetingQuery($from, $to, $this->timezone($parameters['tz'] ?? null, $defaultTimezone), $this->limit($parameters['limit'] ?? null));
    }

    private function time(mixed $value, string $field): DateTimeImmutable
    {
        $time = Rfc3339::parse($value, true);
        if (!$time instanceof DateTimeImmutable) {
            throw new MeetingRequestException('invalid_datetime', $field);
        }

        return $time->setTimezone(new DateTimeZone('UTC'));
    }

    private function timezone(mixed $value, ?string $default): DateTimeZone
    {
        if ($value === null) {
            return new DateTimeZone($default !== null && in_array($default, timezone_identifiers_list(), true) ? $default : 'UTC');
        }
        if (!is_string($value) || !in_array($value, timezone_identifiers_list(), true)) {
            throw new MeetingRequestException('invalid_timezone', 'tz');
        }

        return new DateTimeZone($value);
    }

    private function limit(mixed $value): int
    {
        if ($value === null) {
            return $this->settings->defaultLimit;
        }
        if (!is_string($value) || strlen($value) > 3 || !ctype_digit($value) || (int) $value < 1 || (int) $value > self::MAX_LIMIT) {
            throw new MeetingRequestException('invalid_limit', 'limit');
        }

        return (int) $value;
    }
}
