<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Entry;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\Support\NoteSanitizer;

/**
 * Since 1.40.0: what {@see TimeEntryOperationsInterface::correct()} changes on an entry. Every part is optional: a null
 * time keeps the stored one, a null duration keeps the stored duration when the times do not change and becomes the new
 * span when they do, and the metadata is a patch (a key set to null is removed; keys not listed are kept).
 *
 * The `note` key is the person's note (cleaned and cut to {@see NoteSanitizer::MAX_LENGTH} characters like every note;
 * an empty note removes it). The `closed_by` key is the bundle's own timer mark and cannot be set. Since 1.40.1 a patch
 * holds at most {@see EntryMetadataRules::MAX_KEYS} keys, and the stored metadata after it at most that many keys and
 * {@see EntryMetadataRules::MAX_ENCODED_BYTES} bytes (`metadata_too_large`).
 *
 * Since 1.40.1 (audit ZAN-2) a new start may be at most `$maxAgeDays` days old (null = `manual_entries.max_age_days`),
 * like a manual entry; the age is checked only when the start changes, so an old entry can still get a note or duration
 * fix. Your own correction window, if stricter, stays in your code. Immutable; built per call.
 */
final readonly class TimeEntryCorrection
{
    /** Metadata keys: a lower-case letter, then up to 63 lower-case letters, digits or underscores. */
    public const METADATA_KEY_PATTERN = EntryMetadataRules::KEY_PATTERN;

    /** Longest string value of a metadata key, in bytes (the note is cut to its own limit). */
    public const METADATA_VALUE_MAX_BYTES = EntryMetadataRules::VALUE_MAX_BYTES;

    /** Metadata keys a correction may not set or remove. */
    public const RESERVED_METADATA_KEYS = EntryMetadataRules::RESERVED_KEYS;

    /**
     * @param DateTimeImmutable|null $startedAt The new start (null = unchanged)
     * @param DateTimeImmutable|null $endedAt The new end (null = unchanged)
     * @param int|null $durationSeconds The new tracked duration, 1 to the span in seconds (null = see the class comment)
     * @param array<string, scalar|null> $metadata Metadata patch: key => new value, null removes the key
     * @param int|null $maxAgeDays Since 1.40.1: oldest allowed new start in days, 0 to {@see EntryMetadataRules::MAX_AGE_DAYS_CAP}
     *                             (null = `manual_entries.max_age_days`)
     *
     * @throws InvalidArgumentException When the duration is not positive, the allowed age is out of range, or a metadata
     *                                  key or value is not accepted
     */
    public function __construct(
        public ?DateTimeImmutable $startedAt = null,
        public ?DateTimeImmutable $endedAt = null,
        public ?int $durationSeconds = null,
        public array $metadata = [],
        public ?int $maxAgeDays = null,
    ) {
        if ($durationSeconds !== null && $durationSeconds < 1) {
            throw new InvalidArgumentException('A corrected duration must be at least one second.');
        }
        EntryMetadataRules::assertMaxAgeDays($maxAgeDays, 'a correction');
        EntryMetadataRules::assertValid($metadata, true, 'a correction');
    }

    /**
     * A correction of the times only (the duration becomes the new span).
     */
    public static function times(DateTimeImmutable $startedAt, DateTimeImmutable $endedAt): self
    {
        return new self($startedAt, $endedAt);
    }

    /**
     * This correction with the person's note set (null or blank removes it).
     */
    public function withNote(?string $note): self
    {
        return new self($this->startedAt, $this->endedAt, $this->durationSeconds, ['note' => $note] + $this->metadata, $this->maxAgeDays);
    }
}
