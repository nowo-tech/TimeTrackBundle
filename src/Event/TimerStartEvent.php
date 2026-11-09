<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

use Nowo\TimeTrackBundle\Dto\ClampedTime;
use Nowo\TimeTrackBundle\Dto\TaskReference;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after a timer was started.
 */
final class TimerStartEvent extends Event
{
    /**
     * Creates the event for the timer owner, the task and the client.
     *
     * @param ClampedTime|null $startTime The client's `startedAt` and the applied start (since 1.10; null without one)
     */
    public function __construct(
        private readonly UserInterface $user,
        private readonly TaskReference $task,
        private readonly ClientType $clientType,
        private readonly ?ClampedTime $startTime = null,
    ) {
    }

    /**
     * Timer owner.
     */
    public function getUser(): UserInterface
    {
        return $this->user;
    }

    /**
     * Tracked task.
     */
    public function getTask(): TaskReference
    {
        return $this->task;
    }

    /**
     * Client that started the timer.
     */
    public function getClientType(): ClientType
    {
        return $this->clientType;
    }

    /**
     * The client-supplied start and the applied one (since 1.10; null when the client sent no `startedAt`).
     */
    public function getStartTime(): ?ClampedTime
    {
        return $this->startTime;
    }

    /** Since 1.18: id of {@see self::getUser()}, the timer owner (acting user), as a string (`getId()`). */
    public function getUserId(): string
    {
        return UserIdResolver::getId($this->user);
    }

    /** Since 1.18: id of the started task. */
    public function getTaskId(): string
    {
        return $this->task->id;
    }

    /** Since 1.18: board id of the started task (null when the host gave none). */
    public function getBoardId(): ?string
    {
        return $this->task->boardId;
    }
}
