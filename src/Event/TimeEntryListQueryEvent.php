<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

use DateTimeImmutable;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Lets listeners set the user ids whose entries a viewer sees in a period (team reports).
 */
final class TimeEntryListQueryEvent extends Event
{
    /** @var list<string> */
    private array $userIds = [];

    /**
     * Creates the query for a viewer and a period.
     */
    public function __construct(
        private readonly UserInterface $viewer,
        private readonly DateTimeImmutable $from,
        private readonly DateTimeImmutable $to,
    ) {
    }

    /**
     * User asking for the entries.
     */
    public function getViewer(): UserInterface
    {
        return $this->viewer;
    }

    /**
     * Period start.
     */
    public function getFrom(): DateTimeImmutable
    {
        return $this->from;
    }

    /**
     * Period end.
     */
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
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->userIds = $userIds;
    }
}
