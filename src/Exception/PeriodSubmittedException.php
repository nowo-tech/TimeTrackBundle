<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Exception;

use Nowo\TimeTrackBundle\Enum\WriteKind;

/**
 * Since 1.13: the period is submitted (soft lock of the owner until approve, reject or withdraw): 409
 * `period_submitted`.
 */
final class PeriodSubmittedException extends PeriodWriteRefusedException
{
    /**
     * Creates the exception for the blocking timesheet.
     */
    public function __construct(string $timesheetId, string $periodEnd, WriteKind $writeKind)
    {
        parent::__construct($timesheetId, $periodEnd, $writeKind, 'The period is submitted for approval.');
    }

    /**
     * `period_submitted`.
     */
    public function errorCode(): string
    {
        return 'period_submitted';
    }
}
