<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

use Nowo\TimeTrackBundle\Dto\TaskReference;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

final class TimerStartEvent extends Event
{
    public function __construct(
        private readonly UserInterface $user,
        private readonly TaskReference $task,
        private readonly ClientType $clientType,
    ) {
    }

    public function getUser(): UserInterface
    {
        return $this->user;
    }

    public function getTask(): TaskReference
    {
        return $this->task;
    }

    public function getClientType(): ClientType
    {
        return $this->clientType;
    }
}
