<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Exception;

use Nowo\TimeTrackBundle\Enum\WriteKind;

/**
 * Since 1.13: the period is hard-locked (approved, auto or manual lock): 409 `period_locked` with `lockedThrough`.
 * Only an audited reopen unlocks it.
 */
final class PeriodLockedException extends PeriodWriteRefusedException
{
    /**
     * Creates the exception for the blocking timesheet.
     */
    public function __construct(string $timesheetId, string $lockedThrough, WriteKind $writeKind)
    {
        parent::__construct($timesheetId, $lockedThrough, $writeKind, 'The period is locked.');
    }

    /**
     * `period_locked`.
     */
    public function errorCode(): string
    {
        return 'period_locked';
    }

    /**
     * Last locked local date (Y-m-d).
     */
    public function getLockedThrough(): string
    {
        return $this->getPeriodEnd();
    }
}
