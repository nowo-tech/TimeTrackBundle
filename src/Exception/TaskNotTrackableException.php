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

    /**
     * Since 1.36.0: time without a task is refused (`entries.allow_without_task` off, or a requested scope that is not
     * one of the user's). One answer for both, like an unknown task.
     */
    public static function withoutTask(): self
    {
        return new self('User cannot track time without a task here.');
    }
}
