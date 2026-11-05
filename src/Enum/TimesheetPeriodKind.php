<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Enum;

/**
 * Since 1.13: length of a timesheet period: an ISO week (Monday to Sunday, key `2026-W45`) or a calendar month
 * (key `2026-11`). One kind per host (`timesheets.period`).
 */
enum TimesheetPeriodKind: string
{
    case Week  = 'week';
    case Month = 'month';
}
