<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port;

use Nowo\TimeTrackBundle\Integration\TeamContextProviderInterface;
use Nowo\TimeTrackBundle\Integration\TeamDirectoryInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Asks a host {@see TeamDirectoryInterface} (1.23.0) where the bundle asks the deprecated
 * {@see TeamContextProviderInterface}. Exceptions propagate. Built by {@see Ports}; stateless.
 *
 * @internal
 */
final readonly class TeamDirectoryGate implements TeamContextProviderInterface
{
    /**
     * Wraps the host directory.
     */
    public function __construct(private TeamDirectoryInterface $directory)
    {
    }

    /**
     * The directory's team ids for the user's reference.
     */
    public function getTeamIdsForUser(UserInterface $user): array
    {
        return $this->directory->teamIdsOf(TimeTrackUserRefs::fromUser($user));
    }

    /**
     * The directory's answer for both references.
     */
    public function isManagerOf(UserInterface $manager, UserInterface $member): bool
    {
        return $this->directory->manages(TimeTrackUserRefs::fromUser($manager), TimeTrackUserRefs::fromUser($member));
    }

    /**
     * The directory's managed user ids for the manager's reference.
     */
    public function getManagedUserIds(UserInterface $manager): array
    {
        return $this->directory->managedUserIdsOf(TimeTrackUserRefs::fromUser($manager));
    }
}
