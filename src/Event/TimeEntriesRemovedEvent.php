<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

use Nowo\TimeTrackBundle\Import\ImportedTimeEntryRemoval;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryScope;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 1.11 ({@see TimeTrackEvents::TIME_ENTRIES_REMOVED}): imported entries were deleted (one, or in bulk). Carries
 * ids and counts only. `secondsByTask` lets hosts that keep task totals subtract what was removed.
 */
final class TimeEntriesRemovedEvent extends Event
{
    /**
     * @param string $scope one of {@see ImportedTimeEntryScope} kinds
     * @param string|null $actorId the user who deleted (the deleter path), null for host calls
     */
    public function __construct(
        private readonly string $externalSource,
        private readonly string $scope,
        private readonly ImportedTimeEntryRemoval $removal,
        private readonly ?string $actorId = null,
    ) {
    }

    /**
     * Get external source.
     */
    public function getExternalSource(): string
    {
        return $this->externalSource;
    }

    /**
     * Get scope.
     */
    public function getScope(): string
    {
        return $this->scope;
    }

    /**
     * Get count.
     */
    public function getCount(): int
    {
        return $this->removal->count;
    }

    /**
     * @return array<string, int> task id => removed seconds
     */
    public function getSecondsByTask(): array
    {
        return $this->removal->secondsByTask;
    }

    /**
     * @return list<string>
     */
    public function getUserIds(): array
    {
        return $this->removal->userIds;
    }

    /**
     * Get actor id.
     */
    public function getActorId(): ?string
    {
        return $this->actorId;
    }
}
