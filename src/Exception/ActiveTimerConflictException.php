<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Exception;

use RuntimeException;
use Throwable;

use function sprintf;

final class ActiveTimerConflictException extends RuntimeException
{
    public static function alreadyRunning(string $taskId): self
    {
        return new self(sprintf('An active timer is already running for task %s.', $taskId));
    }

    public static function concurrentStart(?Throwable $previous = null): self
    {
        return new self('An active timer is already running.', 0, $previous);
    }
}
