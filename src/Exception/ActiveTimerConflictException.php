<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Exception;

use RuntimeException;
use Throwable;

use function sprintf;

/**
 * Thrown when a user starts a timer while one is running (HTTP 409 in the client API).
 */
final class ActiveTimerConflictException extends RuntimeException
{
    /**
     * A timer is already running on the task.
     */
    public static function alreadyRunning(string $taskId): self
    {
        return new self(sprintf('An active timer is already running for task %s.', $taskId));
    }

    /**
     * Two starts raced and the unique index rejected the second one.
     */
    public static function concurrentStart(?Throwable $previous = null): self
    {
        return new self('An active timer is already running.', 0, $previous);
    }
}
