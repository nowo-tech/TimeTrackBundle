<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Enum;

/**
 * Since 1.13: action of one audit row of a timesheet (`time_track_timesheet_events.action`, contract §3.3).
 */
enum TimesheetAction: string
{
    case Submitted   = 'submitted';
    case Withdrawn   = 'withdrawn';
    case Approved    = 'approved';
    case Rejected    = 'rejected';
    case Reopened    = 'reopened';
    case AutoLocked  = 'auto_locked';
    case Locked      = 'locked';
    case EditBlocked = 'edit_blocked';
}
