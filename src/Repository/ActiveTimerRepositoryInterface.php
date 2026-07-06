<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use Nowo\TimeTrackBundle\Entity\ActiveTimer;

interface ActiveTimerRepositoryInterface
{
    public function save(ActiveTimer $timer): void;

    public function remove(ActiveTimer $timer): void;

    public function findByUserId(string $userId): ?ActiveTimer;
}
