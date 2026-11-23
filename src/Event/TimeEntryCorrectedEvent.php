<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entry\TimeEntryOperationsInterface;
use Nowo\TimeTrackBundle\Query\TimeEntryRecord;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 1.40.0 ({@see TimeTrackEvents::TIME_ENTRY_CORRECTED}): an entry was corrected through
 * {@see TimeEntryOperationsInterface::correct()} — its values before and after and the changed columns. Dispatched after
 * the bundle's unit (inside a transaction you opened: before your commit), once per real change. Ids, scalars and
 * read models only (REQ-ARCH-001 rule 4); never the entity or the user.
 */
final class TimeEntryCorrectedEvent extends Event
{
    /**
     * @param string $entryId Id of the entry
     * @param string $ownerId Id of the entry owner
     * @param TimeEntryRecord $before The entry before the correction
     * @param TimeEntryRecord $after The entry after it
     * @param list<string> $changedFields The changed columns (`started_at`, `ended_at`, `duration_seconds`, `metadata`)
     * @param DateTimeImmutable $occurredAt When it was corrected
     */
    public function __construct(
        public readonly string $entryId,
        public readonly string $ownerId,
        public readonly TimeEntryRecord $before,
        public readonly TimeEntryRecord $after,
        public readonly array $changedFields,
        public readonly DateTimeImmutable $occurredAt,
    ) {
    }
}
