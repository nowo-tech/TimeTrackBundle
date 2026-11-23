<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Exception;

use Nowo\TimeTrackBundle\Entry\TimeEntryOperationsInterface;
use RuntimeException;

/**
 * Since 1.40.0: a correction ({@see TimeEntryOperationsInterface::correct()}) was refused. The message is the stable
 * code. Period locks are {@see PeriodWriteRefusedException}.
 */
final class TimeEntryCorrectionException extends RuntimeException
{
    /** Unknown id or owner, another owner's, an ownerless or anonymised entry: one answer (no oracle). */
    public const NOT_FOUND = 'not_found';

    /** An imported entry: read-only. */
    public const READ_ONLY = 'read_only';

    /** The entry changed since it was read (another version): read it again. */
    public const CONFLICT = 'conflict';

    /** The end is not after the start. */
    public const INVALID_RANGE = ManualEntryException::INVALID_RANGE;

    /** The span is longer than 24 h. */
    public const TOO_LONG = ManualEntryException::TOO_LONG;

    /** The new end is in the future (beyond the clock skew). */
    public const IN_FUTURE = ManualEntryException::IN_FUTURE;

    /** The duration is longer than the span. */
    public const INVALID_DURATION = 'invalid_duration';

    /** The new span overlaps another live entry or the running timer of the owner. */
    public const OVERLAP = ManualEntryException::OVERLAP;

    /**
     * @param string $errorCode One of the class constants
     */
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }

    /**
     * The HTTP status a host answers: 404 (not found), 403 (read-only), 409 (conflict, overlap) or 422 (the values).
     */
    public function httpStatus(): int
    {
        return match ($this->errorCode) {
            self::NOT_FOUND               => 404,
            self::READ_ONLY               => 403,
            self::CONFLICT, self::OVERLAP => 409,
            default                       => 422,
        };
    }
}
