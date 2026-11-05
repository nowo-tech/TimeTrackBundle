<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

/**
 * Since 1.13: total closed time per task (live and imported entries): the `loggedSeconds` of the client API tasks —
 * of one user (`$userId`), or of every user (null; shown only to users who can manage the task, audit GB-M3).
 */
interface TaskEffortRepositoryInterface
{
    /**
     * Seconds per task id (tasks without entries are omitted), of one user or (null) of every user.
     *
     * @param list<string> $taskIds
     *
     * @return array<string, int>
     */
    public function loggedSecondsByTask(array $taskIds, ?string $userId = null): array;
}
