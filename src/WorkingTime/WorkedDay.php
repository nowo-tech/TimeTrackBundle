<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\WorkingTime;

use DateTimeImmutable;

/**
 * Since 1.13: worked time of one local date (contract §4.3). `workedSeconds` excludes breaks that are not work;
 * `firstStart` / `lastEnd` / `breakSeconds` feed the informational rest and break flags (register sources).
 */
final readonly class WorkedDay
{
    /**
     * @param string $date Local date (Y-m-d)
     * @param list<string> $flags Host flags (missing_clock_out, late_entry, corrected…)
     */
    public function __construct(
        public string $date,
        public int $workedSeconds,
        public int $breakSeconds = 0,
        public ?DateTimeImmutable $firstStart = null,
        public ?DateTimeImmutable $lastEnd = null,
        public array $flags = [],
    ) {
    }
}
