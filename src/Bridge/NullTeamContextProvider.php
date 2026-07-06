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
    public function getTeamIdsForUser(UserInterface $user): array
    {
        return [];
    }

    public function isManagerOf(UserInterface $manager, UserInterface $member): bool
    {
        return false;
    }

    public function getManagedUserIds(UserInterface $manager): array
    {
        return [];
    }
}
