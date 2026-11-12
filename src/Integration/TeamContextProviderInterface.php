<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Integration;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Resolves team membership and manager relationships for reports and ACL.
 *
 * @deprecated since 1.23.0, implement {@see TeamDirectoryInterface} instead (framework-free, REQ-ARCH-001 rule 3); removed in 2.0.
 */
interface TeamContextProviderInterface
{
    /**
     * @return list<string> Team ids the user belongs to
     */
    public function getTeamIdsForUser(UserInterface $user): array;

    /**
     * Whether the manager manages the member.
     */
    public function isManagerOf(UserInterface $manager, UserInterface $member): bool;

    /**
     * @return list<string> User ids managed by the given manager
     */
    public function getManagedUserIds(UserInterface $manager): array;
}
