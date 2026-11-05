<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\WorkingTime;

use DateTimeImmutable;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.13 (host, `working_time.calendar_provider`): expected working time per local date (schedule − holidays −
 * leave), e.g. Beacon spec 198 schedules + spec 204 calendars and leave. Default: {@see DerivedWorkingCalendar}.
 * Must be scope-safe, side-effect free and fast.
 */
interface WorkingCalendarProviderInterface
{
    /**
     * One CalendarDay per local date in [$from, $to] (inclusive, ≤ 366 days), in the scope's time zone.
     * Return null when the host has no schedule for this user and scope (feature off for them).
     *
     * @return list<CalendarDay>|null
     */
    public function days(UserInterface $user, string $scopeKey, DateTimeImmutable $from, DateTimeImmutable $to): ?array;
}
