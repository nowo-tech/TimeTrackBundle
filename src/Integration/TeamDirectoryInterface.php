<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Integration;

use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/**
 * Since 1.23.0: framework-free twin of {@see TeamContextProviderInterface} (REQ-ARCH-001 rule 3), same
 * `team_context_provider` option. Team membership and manager relationships for reports and ACL.
 */
interface TeamDirectoryInterface
{
    /**
     * @return list<string> Team ids the user belongs to
     */
    public function teamIdsOf(TimeTrackUserRef $user): array;

    /**
     * Whether the manager manages the member.
     */
    public function manages(TimeTrackUserRef $manager, TimeTrackUserRef $member): bool;

    /**
     * @return list<string> User ids managed by the given manager
     */
    public function managedUserIdsOf(TimeTrackUserRef $manager): array;
}
