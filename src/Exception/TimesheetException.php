<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Exception;

use RuntimeException;
use Throwable;

/**
 * Since 1.13: a timesheet action was refused, with a stable client error code and its HTTP status:
 * `not_found` (404, also for other users' timesheets: no existence oracle), `invalid_transition`, `timer_running`,
 * `not_started`, `not_ended`, `stale` (the entries changed since submit), `conflict` (a concurrent change won) — 409 —
 * and `comment_required` (422). The message carries no personal data.
 */
final class TimesheetException extends RuntimeException
{
    /** Status of each code. */
    public const STATUS = [
        'not_found'          => 404,
        'invalid_transition' => 409,
        'timer_running'      => 409,
        'not_started'        => 409,
        'not_ended'          => 409,
        'stale'              => 409,
        'conflict'           => 409,
        'comment_required'   => 422,
    ];

    /**
     * Creates the exception for a code of {@see self::STATUS}.
     */
    public function __construct(private readonly string $errorCode, ?Throwable $previous = null)
    {
        parent::__construct('Timesheet action refused: ' . $errorCode . '.', 0, $previous);
    }

    /**
     * Stable error code.
     */
    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * HTTP status of the code.
     */
    public function httpStatus(): int
    {
        return self::STATUS[$this->errorCode] ?? 409;
    }
}
