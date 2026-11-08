<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

use Nowo\TimeTrackBundle\TaskMove\TaskTimeMoveResult;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 1.17 ({@see TimeTrackEvents::TASK_TIME_MOVED}): the time of tasks moved to another board followed them, or a lock kept
 * some of it. Fired once per call, after the last batch's commit, when anything moved or was kept. Carries ids and counts only;
 * the bundle has no audit table, so hosts store this event. No time is created or deleted.
 */
final class TaskTimeMovedEvent extends Event
{
    /**
     * Creates the object.
     *
     * @param list<string> $taskIds
     */
    public function __construct(
        private readonly array $taskIds,
        private readonly ?string $targetBoardId,
        private readonly ?string $targetScopeKey,
        private readonly TaskTimeMoveResult $result,
        private readonly ?string $actorId = null,
    ) {
    }

    /**
     * The tasks whose time was moved.
     *
     * @return list<string>
     */
    public function getTaskIds(): array
    {
        return $this->taskIds;
    }

    /**
     * The board the time now points at.
     */
    public function getTargetBoardId(): ?string
    {
        return $this->targetBoardId;
    }

    /**
     * The scope (tenant) the time now counts in.
     */
    public function getTargetScopeKey(): ?string
    {
        return $this->targetScopeKey;
    }

    /**
     * What moved, what was already there and what stayed, with why.
     */
    public function getResult(): TaskTimeMoveResult
    {
        return $this->result;
    }

    /**
     * The user who moved the tasks, when the host says.
     */
    public function getActorId(): ?string
    {
        return $this->actorId;
    }
}
