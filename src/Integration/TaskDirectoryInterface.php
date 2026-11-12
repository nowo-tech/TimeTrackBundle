<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Integration;

use Nowo\TimeTrackBundle\Dto\TaskListQuery;
use Nowo\TimeTrackBundle\Dto\TaskReference;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/**
 * Since 1.23.0: framework-free twin of {@see TaskProviderInterface} (REQ-ARCH-001 rule 3), same `task_provider` option.
 * Resolves tasks from an external source (e.g. nowo-tech/task-board-bundle).
 */
interface TaskDirectoryInterface
{
    /**
     * Finds a task the user may see.
     */
    public function findTaskFor(string $taskId, TimeTrackUserRef $user): ?TaskReference;

    /**
     * @return list<TaskReference>
     */
    public function listTrackableFor(TimeTrackUserRef $user, TaskListQuery $query): array;

    /**
     * Whether the user may track time on the task.
     */
    public function canTrackTask(TimeTrackUserRef $user, string $taskId): bool;
}
