<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Integration;

use Nowo\TimeTrackBundle\Dto\TaskListQuery;
use Nowo\TimeTrackBundle\Dto\TaskReference;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Resolves tasks from an external source (e.g. nowo-tech/task-board-bundle).
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
