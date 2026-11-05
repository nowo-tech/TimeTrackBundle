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

    /** Since 1.11: whether imported entries are listed (true by default; false for live-time views). */
    private bool $includeImported = true;

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

    /**
     * Since 1.11: whether the list includes imported entries.
     */
    public function includesImported(): bool
    {
        return $this->includeImported;
    }

    /**
     * Since 1.11: false keeps imported entries out of the list (live-time views).
     */
    public function setIncludeImported(bool $includeImported): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->includeImported = $includeImported;
    }
}
