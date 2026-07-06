<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Repository\ActiveTimerRepositoryInterface;

final class InMemoryActiveTimerRepository implements ActiveTimerRepositoryInterface
{
    /** @var array<string, ActiveTimer> */
    private array $byUser = [];

    public function save(ActiveTimer $timer): void
    {
        $user                                  = $timer->getUser();
        $this->byUser[(string) $user->getId()] = $timer;
    }

    public function remove(ActiveTimer $timer): void
    {
        $user = $timer->getUser();
        unset($this->byUser[(string) $user->getId()]);
    }

    public function findByUserId(string $userId): ?ActiveTimer
    {
        return $this->byUser[$userId] ?? null;
    }
}
