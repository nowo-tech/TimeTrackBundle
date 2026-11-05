<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Enum;

/**
 * Since 1.13: role of the actor of a timesheet transition, as decided by the guard at that time.
 */
enum TimesheetActorRole: string
{
    case Owner    = 'owner';
    case Approver = 'approver';
    case Admin    = 'admin';
    case System   = 'system';
}
