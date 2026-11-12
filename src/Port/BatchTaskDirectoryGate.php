<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port;

use Nowo\TimeTrackBundle\Dto\TaskListQuery;
use Nowo\TimeTrackBundle\Dto\TaskReference;
use Nowo\TimeTrackBundle\Integration\BatchTaskDirectoryInterface;
use Nowo\TimeTrackBundle\Integration\BatchTaskProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Asks a host {@see BatchTaskDirectoryInterface} (1.23.0) where the bundle asks the deprecated {@see BatchTaskProviderInterface}.
 * Exceptions propagate. Built by {@see Ports}; stateless.
 *
 * @internal
 */
final readonly class BatchTaskDirectoryGate implements BatchTaskProviderInterface
{
    /**
     * Wraps the host directory.
     */
    public function __construct(private BatchTaskDirectoryInterface $directory)
    {
    }

    /**
     * The directory's task for the user's reference.
     */
    public function findForUser(string $taskId, UserInterface $user): ?TaskReference
    {
        return $this->directory->findTaskFor($taskId, TimeTrackUserRefs::fromUser($user));
    }

    /**
     * The directory's trackable tasks for the user's reference.
     */
    public function listTrackableForUser(UserInterface $user, TaskListQuery $query): array
    {
        return $this->directory->listTrackableFor(TimeTrackUserRefs::fromUser($user), $query);
    }

    /**
     * The directory's answer for the user's reference.
     */
    public function canUserTrack(UserInterface $user, string $taskId): bool
    {
        return $this->directory->canTrackTask(TimeTrackUserRefs::fromUser($user), $taskId);
    }

    /**
     * The directory's trackable subset for the user's reference.
     */
    public function filterTrackable(UserInterface $user, array $taskIds): array
    {
        return $this->directory->filterTrackableFor(TimeTrackUserRefs::fromUser($user), $taskIds);
    }
}
