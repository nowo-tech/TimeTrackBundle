<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Enum\TimesheetLockReason;
use Nowo\TimeTrackBundle\Enum\TimesheetPeriodKind;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\Timesheet\Period;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Builds timesheets in a given state for the lock tests.
 */
final class TimesheetFixtures
{
    public static function open(UserInterface $user, string $scopeKey, string $weekKey, string $timezone = 'UTC'): Timesheet
    {
        return new Timesheet($user, UserIdResolver::getId($user), $scopeKey, Period::fromKey(TimesheetPeriodKind::Week, $weekKey), $timezone, new DateTimeImmutable('2026-11-01 08:00:00'));
    }

    public static function submitted(UserInterface $user, string $scopeKey, string $weekKey, string $timezone = 'UTC'): Timesheet
    {
        $timesheet = self::open($user, $scopeKey, $weekKey, $timezone);
        $timesheet->applySubmit(new DateTimeImmutable('2026-11-01 09:00:00'), UserIdResolver::getId($user), str_repeat('a', 64), ['entries' => 0]);

        return $timesheet;
    }

    public static function locked(UserInterface $user, string $scopeKey, string $weekKey, string $timezone = 'UTC', TimesheetLockReason $reason = TimesheetLockReason::Manual): Timesheet
    {
        $timesheet = self::open($user, $scopeKey, $weekKey, $timezone);
        $timesheet->applyLock(new DateTimeImmutable('2026-11-01 10:00:00'), $reason);

        return $timesheet;
    }
}
