<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Exception;

use RuntimeException;

/**
 * The user entered too many user codes in the window (brute-force guard of the device verification page).
 */
final class DeviceCodeAttemptsExceededException extends RuntimeException
{
    /**
     * Creates the exception with the seconds until the next attempt.
     */
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Too many device code attempts.');
    }
}
