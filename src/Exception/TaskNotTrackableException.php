<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Exception;

use InvalidArgumentException;

use function sprintf;

/**
 * The task is unknown or the user may not track time on it. The client API answers it with the same
 * `403 task_forbidden` for both cases (no existence oracle).
 */
final class TaskNotTrackableException extends InvalidArgumentException
{
    /**
     * The task cannot be tracked by the user.
     */
    public static function forTask(string $taskId): self
    {
        return new self(sprintf('User cannot track task %s.', $taskId));
    }
}
