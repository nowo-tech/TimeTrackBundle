<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

use DateTimeImmutable;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

final class TimeEntryListQueryEvent extends Event
{
    /** @var list<string> */
    private array $userIds = [];

    public function __construct(
        private readonly UserInterface $viewer,
        private readonly DateTimeImmutable $from,
        private readonly DateTimeImmutable $to,
    ) {
    }

    public function getViewer(): UserInterface
    {
        return $this->viewer;
    }

    public function getFrom(): DateTimeImmutable
    {
        return $this->from;
    }

    public function getTo(): DateTimeImmutable
    {
        return $this->to;
    }

    /**
     * @return list<string>
     */
    public function getUserIds(): array
    {
        return $this->userIds;
    }

    /**
     * @param list<string> $userIds
     */
    public function setUserIds(array $userIds): void
    {
        $this->userIds = $userIds;
    }
}
