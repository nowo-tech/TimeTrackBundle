<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Enum;

/**
 * Since 1.13: kind of a balance ledger line (contract §3.4).
 */
enum LedgerKind: string
{
    /** Initial balance (once per owner and scope unless reversed). */
    case Opening = 'opening';
    /** Manual correction (signed). */
    case Adjustment = 'adjustment';
    /** Overtime ratified by a timesheet approval (informational for the overtime counter; not part of the balance). */
    case OvertimeRatified = 'overtime_ratified';
    /** Overtime paid instead of rest (negative). */
    case OvertimePaid = 'overtime_paid';
    /** Overtime compensated with rest (art. 35.1 ET; written by the host, e.g. on approved compensatory leave). */
    case OvertimeCompensated = 'overtime_compensated';
    /** Cancels another line (lines are never edited). */
    case Reversal = 'reversal';

    /**
     * Whether a line of this kind moves the hours balance (`opening`, `adjustment`, `overtime_paid`, `reversal` of
     * such a line). Ratified and compensated overtime only feed the overtime counter.
     */
    public function movesBalance(): bool
    {
        return match ($this) {
            self::Opening, self::Adjustment, self::OvertimePaid, self::Reversal => true,
            self::OvertimeRatified, self::OvertimeCompensated                   => false,
        };
    }

    /**
     * Whether the line needs a reason (`opening`, `adjustment`, `overtime_paid`, `reversal`).
     */
    public function requiresReason(): bool
    {
        return $this->movesBalance();
    }
}
