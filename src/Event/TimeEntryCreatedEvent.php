<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 1.14 ({@see TimeTrackEvents::TIME_ENTRY_CREATED}, contract §9.1, §9.3): an entry was created without a timer —
 * `POST entries` (`origin` `manual` or `calendar`) or an accepted calendar suggestion (`calendar`). Dispatched after the
 * flush. TaskBoard aggregates `total_time_seconds` from it; like {@see TimeEntryImportedEvent}, the entry is the managed
 * one (task title snapshot, note): listeners must not log or forward personal fields.
 */
final class TimeEntryCreatedEvent extends Event
{
    /**
     * Creates the event.
     *
     * @param string $origin `manual` or `calendar`
     */
    public function __construct(
        private readonly TimeEntry $entry,
        public readonly string $origin,
    ) {
    }

    /**
     * The new entry.
     */
    public function getEntry(): TimeEntry
    {
        return $this->entry;
    }
}
