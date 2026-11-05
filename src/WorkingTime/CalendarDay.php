<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\WorkingTime;

/**
 * Since 1.13: one local date of a user's working calendar in a scope (contract §4.2). `expectedSeconds` is after
 * holidays, leave and part-time; `compensatory` (time off in lieu) keeps its normal expected seconds so taking it
 * reduces the balance; `label` is for public holidays only (null for any personal leave or absence).
 */
final readonly class CalendarDay
{
    /**
     * @param string $date Local date (Y-m-d)
     * @param string $timezone IANA zone
     * @param int $expectedSeconds 0..86400
     * @param float $fraction 0.5 = half day off
     * @param int|null $ordinaryMaxSeconds The agreement's daily ordinary cap if known (art. 34.3 ET)
     */
    public function __construct(
        public string $date,
        public string $timezone,
        public int $expectedSeconds,
        public CalendarDayKind $kind,
        public ?string $label = null,
        public float $fraction = 1.0,
        public ?int $ordinaryMaxSeconds = null,
    ) {
    }
}
