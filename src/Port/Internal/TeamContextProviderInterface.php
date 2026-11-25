<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port\Internal;

use Nowo\TimeTrackBundle\Integration\TeamDirectoryInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Resolves team membership and manager relationships for reports and ACL.
 *
 * @internal since 2.0 (REQ-ARCH-001 rule 3): the shape the bundle asks this port through; the 1.x public interface
 * `Nowo\TimeTrackBundle\Integration\TeamContextProviderInterface` (deprecated since 1.23.0) is removed in 2.0. Hosts implement {@see TeamDirectoryInterface} (a gate adapts it).
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
