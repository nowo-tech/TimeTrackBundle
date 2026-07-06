<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Exception;

use RuntimeException;

use function sprintf;

final class ActiveTimerConflictException extends RuntimeException
{
    public static function alreadyRunning(string $taskId): self
    {
        return new self(sprintf('An active timer is already running for task %s.', $taskId));
    }
}
