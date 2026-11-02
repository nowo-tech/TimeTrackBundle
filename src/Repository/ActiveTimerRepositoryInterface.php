<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use Nowo\TimeTrackBundle\Entity\ActiveTimer;

/**
 * Store of the running timers (one per user).
 */
interface ActiveTimerRepositoryInterface
{
    /**
     * Persists a timer.
     */
    public function save(ActiveTimer $timer): void;

    /**
     * Deletes a timer.
     */
    public function remove(ActiveTimer $timer): void;

    /**
     * Running timer of a user.
     */
    public function findByUserId(string $userId): ?ActiveTimer;
}
