<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Enum;

/**
 * Since 1.13: status of a timesheet (contract §5.2). The lock is separate ({@see TimesheetLockReason}): an auto-locked
 * period keeps its status.
 */
enum TimesheetStatus: string
{
    case Open      = 'open';
    case Submitted = 'submitted';
    case Approved  = 'approved';
    case Rejected  = 'rejected';

    /**
     * Whether the owner may edit the period's entries in this status (ignoring the lock): open and rejected.
     */
    public function isEditable(): bool
    {
        return $this === self::Open || $this === self::Rejected;
    }
}
