<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

/**
 * Since 1.13: total closed time per task, of every user (live and imported entries): the `loggedSeconds` of the
 * client API tasks. A total only: it never says who tracked the time.
 */
interface TaskEffortRepositoryInterface
{
    /**
     * Seconds per task id (tasks without entries are omitted).
     *
     * @param list<string> $taskIds
     *
     * @return array<string, int>
     */
    public function loggedSecondsByTask(array $taskIds): array;
}
