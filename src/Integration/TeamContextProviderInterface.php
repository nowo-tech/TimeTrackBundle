<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Integration;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Resolves team membership and manager relationships for reports and ACL.
 */
interface TeamContextProviderInterface
{
    /**
     * @return list<string> Team ids the user belongs to
     */
    public function getTeamIdsForUser(UserInterface $user): array;

    public function isManagerOf(UserInterface $manager, UserInterface $member): bool;

    /**
     * @return list<string> User ids managed by the given manager
     */
    public function getManagedUserIds(UserInterface $manager): array;
}
