<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port\Internal;

use Nowo\TimeTrackBundle\Dto\TaskListQuery;
use Nowo\TimeTrackBundle\Dto\TaskReference;
use Nowo\TimeTrackBundle\Integration\TaskDirectoryInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Resolves tasks from an external source (e.g. nowo-tech/task-board-bundle).
 *
 * @internal since 2.0 (REQ-ARCH-001 rule 3): the shape the bundle asks this port through; the 1.x public interface
 * `Nowo\TimeTrackBundle\Integration\TaskProviderInterface` (deprecated since 1.23.0) is removed in 2.0. Hosts implement {@see TaskDirectoryInterface} (a gate adapts it).
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
