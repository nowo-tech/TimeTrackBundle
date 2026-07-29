<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use LogicException;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Repository\ActiveTimerRepositoryInterface;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Symfony\Component\Security\Core\User\UserInterface;

final class InMemoryActiveTimerRepository implements ActiveTimerRepositoryInterface
{
    /** @var array<string, ActiveTimer> */
    private array $byUser = [];

    public function save(ActiveTimer $timer): void
    {
        $user = $timer->getUser();
        if (!$user instanceof UserInterface) {
            throw new LogicException('Active timer user must implement UserInterface.');
        }

        $this->byUser[UserIdResolver::getId($user)] = $timer;
    }

    public function remove(ActiveTimer $timer): void
    {
        $user = $timer->getUser();
        if (!$user instanceof UserInterface) {
            throw new LogicException('Active timer user must implement UserInterface.');
        }

        unset($this->byUser[UserIdResolver::getId($user)]);
    }

    public function findByUserId(string $userId): ?ActiveTimer
    {
        return $this->byUser[$userId] ?? null;
    }
}
