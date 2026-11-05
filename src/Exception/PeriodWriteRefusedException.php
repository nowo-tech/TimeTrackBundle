<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Exception;

use Nowo\TimeTrackBundle\Enum\WriteKind;
use RuntimeException;

/**
 * Since 1.13: a bundle write path refused to change time in a locked or submitted period (HTTP 409 with
 * {@see self::errorCode()}). The message carries no personal data.
 */
abstract class PeriodWriteRefusedException extends RuntimeException
{
    /**
     * @param string $timesheetId The blocking timesheet
     * @param string $periodEnd Last local date of the blocking period (Y-m-d)
     */
    public function __construct(
        private readonly string $timesheetId,
        private readonly string $periodEnd,
        private readonly WriteKind $writeKind,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * Stable error code of the client API (`period_locked`, `period_submitted`).
     */
    abstract public function errorCode(): string;

    /**
     * Id of the blocking timesheet.
     */
    public function getTimesheetId(): string
    {
        return $this->timesheetId;
    }

    /**
     * Last local date of the blocking period.
     */
    public function getPeriodEnd(): string
    {
        return $this->periodEnd;
    }

    /**
     * The refused write path.
     */
    public function getWriteKind(): WriteKind
    {
        return $this->writeKind;
    }
}
