<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Exception;

use RuntimeException;

/**
 * Since 1.14: an entry without a timer was refused (`POST entries`, an accepted suggestion). The message is the stable
 * code: `invalid_range` (end ≤ start), `entry_too_long` (> 24 h), `entry_in_future`, `entry_too_old`
 * (`manual_entries.max_age_days`, or `calendar.lookback_days` for suggestions), `not_trackable`, `overlap` (a live entry
 * or the running timer of the user), since 1.21 `billable_forbidden`. Period locks are {@see PeriodWriteRefusedException}.
 */
final class ManualEntryException extends RuntimeException
{
    public const INVALID_RANGE = 'invalid_range';
    public const TOO_LONG      = 'entry_too_long';
    public const IN_FUTURE     = 'entry_in_future';
    public const TOO_OLD       = 'entry_too_old';
    public const NOT_TRACKABLE = 'not_trackable';
    public const OVERLAP       = 'overlap';

    /** Since 1.21 (audit MV-T-L3): an explicit `billable` the `edit_billable` access check refused (403). */
    public const BILLABLE_FORBIDDEN = 'billable_forbidden';

    /**
     * @param string $errorCode One of the class constants
     */
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }

    /**
     * HTTP status of the client API answer: 403 (task), 409 (overlap) or 422 (the times).
     */
    public function httpStatus(): int
    {
        return match ($this->errorCode) {
            self::NOT_TRACKABLE, self::BILLABLE_FORBIDDEN => 403,
            self::OVERLAP                                 => 409,
            default                                       => 422,
        };
    }
}
