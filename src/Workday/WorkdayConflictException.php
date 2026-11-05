<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Workday;

use RuntimeException;

/**
 * Since 1.13: the action is not allowed in the current state (409 `invalid_transition` with the state).
 */
final class WorkdayConflictException extends RuntimeException
{
    /**
     * Carries the current state.
     */
    public function __construct(public readonly WorkdayState $state)
    {
        parent::__construct('Invalid workday transition.');
    }
}
