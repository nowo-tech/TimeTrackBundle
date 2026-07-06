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
    public function findForUser(string $taskId, UserInterface $user): ?TaskReference;

    /**
     * @return list<TaskReference>
     */
    public function listTrackableForUser(UserInterface $user, TaskListQuery $query): array;

    public function canUserTrack(UserInterface $user, string $taskId): bool;
}
