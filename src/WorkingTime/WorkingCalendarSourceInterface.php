<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\WorkingTime;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/**
 * Since 1.23.0: framework-free twin of {@see WorkingCalendarProviderInterface} (REQ-ARCH-001 rule 3), same
 * `working_time.calendar_provider` option. Must be scope-safe, side-effect free and fast.
 */
interface WorkingCalendarSourceInterface
{
    /**
     * One CalendarDay per local date in [$from, $to] (inclusive, ≤ 366 days), in the scope's time zone; null when the
     * host has no schedule for this user and scope.
     *
     * @return list<CalendarDay>|null
     */
    public function daysFor(TimeTrackUserRef $user, string $scopeKey, DateTimeImmutable $from, DateTimeImmutable $to): ?array;
}
