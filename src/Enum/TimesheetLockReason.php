<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Enum;

/**
 * Since 1.13: why a timesheet is locked (`lock_reason`).
 */
enum TimesheetLockReason: string
{
    case Approved = 'approved';
    case Auto     = 'auto';
    case Manual   = 'manual';
}
