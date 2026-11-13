<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Exception;

use Nowo\TimeTrackBundle\Timer\HostTimerApi;
use RuntimeException;
use Throwable;

use function sprintf;

/**
 * Thrown when a user starts a timer while one is running (HTTP 409 in the client API).
 */
final class ActiveTimerConflictException extends RuntimeException
{
    /**
     * Since 1.25.0 (audit PA-2): ids of the entries this call already recorded before it gave up, newest last — the
     * timers {@see HostTimerApi::start()} stopped on its attempts. They are written and
     * committed; the caller answering 409 can name them. Empty for every other conflict.
     *
     * @var list<string>
     */
    public array $stoppedEntryIds = [];

    /**
     * A timer is already running on the task.
     */
    public static function alreadyRunning(string $taskId): self
    {
        return new self(sprintf('An active timer is already running for task %s.', $taskId));
    }

    /**
     * Two starts raced and the unique index rejected the second one.
     *
     * @param list<string> $stoppedEntryIds Since 1.25.0: entries this call recorded before it gave up ({@see self::$stoppedEntryIds})
     */
    public static function concurrentStart(?Throwable $previous = null, array $stoppedEntryIds = []): self
    {
        $exception                  = new self('An active timer is already running.', 0, $previous);
        $exception->stoppedEntryIds = $stoppedEntryIds;

        return $exception;
    }
}
