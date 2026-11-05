<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Timesheet;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\Enum\TimesheetPeriodKind;

use function checkdate;
use function preg_match;
use function sprintf;

/**
 * Since 1.13: one timesheet period: an ISO week (Monday to Sunday, key `2026-W45`) or a calendar month (key
 * `2026-11`), as local dates (inclusive). An entry belongs to the period of its local start date (contract §5.1).
 */
final readonly class Period
{
    /** `2026-W45` (ISO year, two-digit ISO week). */
    public const WEEK_KEY_PATTERN = '/^(\d{4})-W(\d{2})$/D';

    /** `2026-11`. */
    public const MONTH_KEY_PATTERN = '/^(\d{4})-(\d{2})$/D';

    /** `2026-11-05`. */
    public const DATE_PATTERN = '/^(\d{4})-(\d{2})-(\d{2})$/D';

    /**
     * @param string $start First local date (Y-m-d)
     * @param string $end Last local date (Y-m-d, inclusive)
     */
    private function __construct(
        public TimesheetPeriodKind $kind,
        public string $key,
        public string $start,
        public string $end,
    ) {
    }

    /**
     * The period that contains a local date.
     *
     * @throws InvalidArgumentException When the date is not a valid `Y-m-d`
     */
    public static function containing(TimesheetPeriodKind $kind, string $localDate): self
    {
        $date = self::date($localDate);
        if ($kind === TimesheetPeriodKind::Month) {
            return self::fromKey($kind, $date->format('Y-m'));
        }

        return self::fromKey($kind, sprintf('%s-W%s', $date->format('o'), $date->format('W')));
    }

    /**
     * The period of a key (`2026-W45` or `2026-11`).
     *
     * @throws InvalidArgumentException When the key does not match the kind or does not exist (week 53 of a 52-week year)
     */
    public static function fromKey(TimesheetPeriodKind $kind, string $key): self
    {
        if ($kind === TimesheetPeriodKind::Week) {
            if (preg_match(self::WEEK_KEY_PATTERN, $key, $m) !== 1) {
                throw new InvalidArgumentException('Invalid week key.');
            }
            $year  = (int) $m[1];
            $week  = (int) $m[2];
            $start = (new DateTimeImmutable('@0'))->setTimezone(new DateTimeZone('UTC'))->setISODate($year, $week, 1);
            if ($week < 1 || (int) $start->format('o') !== $year || (int) $start->format('W') !== $week) {
                throw new InvalidArgumentException('Invalid week key.');
            }

            return new self($kind, $key, $start->format('Y-m-d'), $start->modify('+6 days')->format('Y-m-d'));
        }

        if (preg_match(self::MONTH_KEY_PATTERN, $key, $m) !== 1 || !checkdate((int) $m[2], 1, (int) $m[1])) {
            throw new InvalidArgumentException('Invalid month key.');
        }
        $start = self::date($key . '-01');

        return new self($kind, $key, $start->format('Y-m-d'), $start->format('Y-m-t'));
    }

    /**
     * The period right before this one.
     */
    public function previous(): self
    {
        return self::containing($this->kind, self::date($this->start)->modify('-1 day')->format('Y-m-d'));
    }

    /**
     * The period right after this one.
     */
    public function next(): self
    {
        return self::containing($this->kind, self::date($this->end)->modify('+1 day')->format('Y-m-d'));
    }

    /**
     * Whether a local date (`Y-m-d`) is inside the period.
     */
    public function contains(string $localDate): bool
    {
        return $localDate >= $this->start && $localDate <= $this->end;
    }

    /**
     * First instant of the period in a time zone (local midnight of the first day).
     */
    public function startsAt(string $timezone): DateTimeImmutable
    {
        return new DateTimeImmutable($this->start . ' 00:00:00', new DateTimeZone($timezone));
    }

    /**
     * First instant after the period in a time zone (local midnight after the last day; exclusive end).
     */
    public function endsBefore(string $timezone): DateTimeImmutable
    {
        return (new DateTimeImmutable($this->end . ' 00:00:00', new DateTimeZone($timezone)))->modify('+1 day');
    }

    /**
     * The local dates of the period, in order.
     *
     * @return list<string>
     */
    public function dates(): array
    {
        $dates = [];
        for ($day = self::date($this->start); $day->format('Y-m-d') <= $this->end; $day = $day->modify('+1 day')) {
            $dates[] = $day->format('Y-m-d');
        }

        return $dates;
    }

    /**
     * A valid local date at UTC midnight.
     *
     * @throws InvalidArgumentException When the value is not a valid `Y-m-d`
     */
    public static function date(string $localDate): DateTimeImmutable
    {
        if (preg_match(self::DATE_PATTERN, $localDate, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidArgumentException('Invalid date.');
        }

        return new DateTimeImmutable($localDate . ' 00:00:00', new DateTimeZone('UTC'));
    }
}
