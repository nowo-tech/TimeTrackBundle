<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Integration;

use Nowo\TimeTrackBundle\Dto\TaskListQuery;
use Nowo\TimeTrackBundle\Dto\TaskReference;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Resolves tasks from an external source (e.g. nowo-tech/task-board-bundle).
 *
 * @deprecated since 1.23.0, implement {@see TaskDirectoryInterface} instead (framework-free, REQ-ARCH-001 rule 3); removed in 2.0.
 */
interface TaskProviderInterface
{
    /**
     * Finds a task the user may see.
     */
    public function findForUser(string $taskId, UserInterface $user): ?TaskReference;

    /**
     * @return list<TaskReference>
     */
    public function listTrackableForUser(UserInterface $user, TaskListQuery $query): array;

    /**
     * Whether the user may track time on the task.
     */
    public function canUserTrack(UserInterface $user, string $taskId): bool;
}
