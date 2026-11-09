<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

use Nowo\TimeTrackBundle\Dto\TimeEntryView;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryOutcome;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 1.11 ({@see TimeTrackEvents::TIME_ENTRY_IMPORTED}): the imported-entry writer created or changed an entry,
 * after the flush. Never dispatched for an unchanged entry; never a timer event. Hosts that keep task totals add
 * `entry duration − previousDurationSeconds`. The event carries no description or title beyond the entry itself:
 * listeners must not log it.
 */
final class TimeEntryImportedEvent extends Event
{
    /**
     * Creates the object.
     */
    public function __construct(
        private readonly TimeEntry $entry,
        private readonly ImportedTimeEntryOutcome $outcome,
        private readonly int $previousDurationSeconds = 0,
        private readonly ?string $previousTaskId = null,
    ) {
    }

    /**
     * Get entry.
     */
    public function getEntry(): TimeEntry
    {
        return $this->entry;
    }

    /**
     * Get outcome.
     */
    public function getOutcome(): ImportedTimeEntryOutcome
    {
        return $this->outcome;
    }

    /** Seconds before the update (0 for a created entry). */
    public function getPreviousDurationSeconds(): int
    {
        return $this->previousDurationSeconds;
    }

    /** Task id before the update when it changed (null otherwise). */
    public function getPreviousTaskId(): ?string
    {
        return $this->previousTaskId;
    }

    /** Since 1.18: id of the entry owner (null for an ownerless entry). */
    public function getUserId(): ?string
    {
        return $this->entry->getUserId();
    }

    /** Since 1.18: id of the entry. */
    public function getEntryId(): string
    {
        return $this->entry->getId();
    }

    /** Since 1.18: scalar snapshot of the entry ({@see TimeEntryView}), so the listener does not hold the entity. */
    public function getEntryView(): TimeEntryView
    {
        return TimeEntryView::fromEntry($this->entry);
    }
}
