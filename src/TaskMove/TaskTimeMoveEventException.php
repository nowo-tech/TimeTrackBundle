<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\TaskMove;

use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use RuntimeException;
use Throwable;

/**
 * Since 1.17 (audit ID-L1): a {@see TimeTrackEvents::TASK_TIME_MOVED} listener threw. The move itself
 * finished and committed: {@see self::getResult()} says what it did, and the listener's exception is the previous one. Calling
 * again with the same arguments dispatches the event again, for the entries already at the target.
 */
final class TaskTimeMoveEventException extends RuntimeException
{
    /**
     * Creates the exception.
     */
    public function __construct(private readonly TaskTimeMoveResult $result, Throwable $previous)
    {
        parent::__construct('The task time moved, but a TASK_TIME_MOVED listener failed.', 0, $previous);
    }

    /**
     * What the move did (it committed).
     */
    public function getResult(): TaskTimeMoveResult
    {
        return $this->result;
    }
}
