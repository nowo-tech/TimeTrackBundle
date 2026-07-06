<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Stub;

use Nowo\TimeTrackBundle\Integration\TeamContextProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class StubTeamContextProvider implements TeamContextProviderInterface
{
    /** @param list<string> $managedUserIds */
    public function __construct(
        private array $managedUserIds = [],
        private bool $isManager = false,
    ) {
    }

    public function getTeamIdsForUser(UserInterface $user): array
    {
        return [];
    }

    public function isManagerOf(UserInterface $manager, UserInterface $member): bool
    {
        return $this->isManager;
    }

    public function getManagedUserIds(UserInterface $manager): array
    {
        return $this->managedUserIds;
    }
}
