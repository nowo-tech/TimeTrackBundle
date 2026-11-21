<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Query;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Dto\TimeEntryView;

/**
 * Since 1.35.0 (DDD-host checklist B7): one entry as {@see TimeEntryQueryInterface} returns it: the scalar
 * {@see TimeEntryView} (the same snapshot the events carry) plus the person's own text and the stored metadata, which the
 * events never carry. Never the Doctrine entity, never the host user. Built per call and never kept by the bundle.
 *
 * The task title is the stored snapshot. A host that shows another person's imported entries applies the same masking as
 * the bundle's surfaces (`TimerService::hiddenTaskEntryIds()`, USAGE "Masking is per surface").
 */
final readonly class TimeEntryRecord
{
    /**
     * @param TimeEntryView $entry Ids, task, times, source and origin of the entry
     * @param string|null $note The person's note (`metadata.note`), null when none
     * @param string|null $description The description an imported entry came with
     * @param bool|null $billable The billable flag (null = not set)
     * @param array<string, mixed> $metadata The stored metadata (empty when none), the note included
     * @param DateTimeImmutable $createdAt When the entry was written
     */
    public function __construct(
        public TimeEntryView $entry,
        public ?string $note,
        public ?string $description,
        public ?bool $billable,
        public array $metadata,
        public DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * Whether nobody may edit the entry (an imported entry).
     */
    public function isReadOnly(): bool
    {
        return $this->entry->source->isReadOnly();
    }
}
