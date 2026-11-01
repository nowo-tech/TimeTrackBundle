<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Bridge;

use Nowo\TimeTrackBundle\Integration\TeamContextProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * No-op team context until TaskBoardBundle provides team/manager data.
 */
final class NullTeamContextProvider implements TeamContextProviderInterface
{
    /**
     * No teams.
     */
    public function getTeamIdsForUser(UserInterface $user): array
    {
        return [];
    }

    /**
     * Nobody manages anybody.
     */
    public function isManagerOf(UserInterface $manager, UserInterface $member): bool
    {
        return false;
    }

    /**
     * No managed users.
     */
    public function getManagedUserIds(UserInterface $manager): array
    {
        return [];
    }
}
