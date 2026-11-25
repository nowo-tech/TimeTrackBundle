<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Exception;

use Nowo\TimeTrackBundle\Timer\TimerSnapshot;

/**
 * Since 2.1.0 (Beacon spec 256, Q-TS-2): an interactive stop (client API `timer/stop`, `HostTimerApi::stop()`, the web
 * UI) of a timer with neither task nor subject was refused under `entries.require_allocation: true`
 * (`allocation_required`), or of a timer without an activity type under `entries.require_activity_type: true`
 * (`activity_type_required`). Nothing was written: the timer keeps running ({@see self::$timer}); stop it again with an
 * allocation. System stops (start-replacing, switch, idle resolution, user deletion) are never refused.
 */
final class TimeAllocationRequiredException extends TimeAllocationException
{
    /**
     * @param TimerSnapshot $timer The still-running timer
     * @param string $errorCode `allocation_required` or `activity_type_required`
     */
    public function __construct(public readonly TimerSnapshot $timer, string $errorCode = self::ALLOCATION_REQUIRED)
    {
        parent::__construct($errorCode);
    }

    /**
     * Always 409: the client opens its "allocate this time" dialog and stops again.
     */
    public function httpStatus(): int
    {
        return 409;
    }
}
