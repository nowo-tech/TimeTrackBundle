<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

use Nowo\TimeTrackBundle\Dto\TaskReference;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after a timer was started.
 */
final class TimerStartEvent extends Event
{
    /**
     * Creates the event for the timer owner, the task and the client.
     */
    public function __construct(
        private readonly UserInterface $user,
        private readonly TaskReference $task,
        private readonly ClientType $clientType,
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
}
