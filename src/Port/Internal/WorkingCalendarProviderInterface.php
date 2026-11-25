<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port\Internal;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\WorkingTime\CalendarDay;
use Nowo\TimeTrackBundle\WorkingTime\DerivedWorkingCalendar;
use Nowo\TimeTrackBundle\WorkingTime\WorkingCalendarSourceInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.13 (host, `working_time.calendar_provider`): expected working time per local date (schedule − holidays −
 * leave), e.g. Beacon spec 198 schedules + spec 204 calendars and leave. Default: {@see DerivedWorkingCalendar}.
 * Must be scope-safe, side-effect free and fast.
 *
 * @internal since 2.0 (REQ-ARCH-001 rule 3): the shape the bundle asks this port through; the 1.x public interface
 * `Nowo\TimeTrackBundle\WorkingTime\WorkingCalendarProviderInterface` (deprecated since 1.23.0) is removed in 2.0. Hosts implement {@see WorkingCalendarSourceInterface} (a gate adapts it).
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
