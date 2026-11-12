<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port;

use Nowo\TimeTrackBundle\Security\TaskAccessCheckerInterface;
use Nowo\TimeTrackBundle\Security\TaskAccessPolicyInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Asks a host {@see TaskAccessPolicyInterface} (1.23.0) where the bundle asks the deprecated
 * {@see TaskAccessCheckerInterface}. Exceptions propagate. Built by {@see Ports}; stateless.
 *
 * @internal
 */
final readonly class TaskAccessPolicyGate implements TaskAccessCheckerInterface
{
    /**
     * Wraps the host policy.
     */
    public function __construct(private TaskAccessPolicyInterface $policy)
    {
    }

    /**
     * The policy's answer for the user's reference.
     */
    public function canTrack(UserInterface $user, string $taskId): bool
    {
        return $this->policy->mayTrack(TimeTrackUserRefs::fromUser($user), $taskId);
    }
}
