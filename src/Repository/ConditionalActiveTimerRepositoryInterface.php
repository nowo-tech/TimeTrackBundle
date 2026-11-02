<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Service\TimerService;

/**
 * Active timer store that can remove a timer atomically (1.9.1). {@see TimerService::stop()}
 * uses it so that concurrent stops (also via `timer/switch`) record the stopped span once. A store that only implements
 * {@see ActiveTimerRepositoryInterface} keeps the 1.x read-then-write behaviour.
 */
interface ConditionalActiveTimerRepositoryInterface extends ActiveTimerRepositoryInterface
{
    /**
     * Deletes the timer only while it still exists (conditional delete) and, when this call deleted it, runs
     * `$onRemoved` in the same unit of work (rolled back together when it throws).
     *
     * @param callable(): void $onRemoved Work bound to the removal (records the time entry)
     *
     * @return bool True when this call removed the timer and ran `$onRemoved`; false when another call removed it first
     */
    public function removeIfPresent(ActiveTimer $timer, callable $onRemoved): bool;
}
