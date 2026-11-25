<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Query;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Dto\TimeEntryView;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Entry\TimeEntryOperationsInterface;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;

use function array_is_list;
use function hash;
use function is_array;
use function is_string;
use function json_encode;
use function ksort;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

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
     * Since 1.40.0: the record of an entry the bundle just read or wrote (the same values
     * {@see TimeEntryQueryInterface::find()} returns for it).
     *
     * @internal built by the bundle's services
     */
    public static function fromEntry(TimeEntry $entry): self
    {
        $metadata = $entry->getMetadata() ?? [];
        $note     = $metadata['note'] ?? null;

        return new self(
            TimeEntryView::fromEntry($entry),
            is_string($note) && $note !== '' ? $note : null,
            $entry->getDescription(),
            $entry->getBillable(),
            $metadata,
            $entry->getCreatedAt(),
        );
    }

    /**
     * Since 1.40.0: the optimistic-concurrency version of the entry as read: an opaque token (64 hexadecimal characters)
     * that changes whenever its owner, task, board, scope, times, duration, source, billable flag, description or
     * metadata change, and since 2.1.0 its subject or activity type. The two 2.1 values are hashed only when set, so the
     * token of an entry that has neither is byte-identical to 2.0 (no wave of spurious `conflict`s after the upgrade). Pass it back to {@see TimeEntryOperationsInterface::correct()}: when the entry changed since it was
     * read, the correction is refused with `conflict` instead of overwriting the other change. Not a secret and not
     * stored: it is computed from the row (seconds precision, metadata keys in any order).
     */
    public function version(): string
    {
        $entry = $this->entry;

        $values = [
            'v1',
            $entry->id,
            $entry->userId,
            $entry->taskIdOrNull(),
            $entry->boardId,
            $entry->scopeKey,
            $entry->startedAt->getTimestamp(),
            $entry->endedAt->getTimestamp(),
            $entry->durationSeconds,
            $entry->source->value,
            $this->billable,
            $this->description,
            self::canonical($this->metadata),
        ];
        if ($entry->subject instanceof TimeSubjectRef) {
            $values[] = ['subject', $entry->subject->type, $entry->subject->id];
        }
        if ($entry->activityTypeKey !== null) {
            $values[] = ['activity_type', $entry->activityTypeKey];
        }

        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Whether nobody may edit the entry (an imported entry).
     */
    public function isReadOnly(): bool
    {
        return $this->entry->source->isReadOnly();
    }

    /**
     * The value with every map sorted by key, recursively (a JSON column may store keys in another order).
     */
    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::canonical($item);
        }

        return $value;
    }
}
