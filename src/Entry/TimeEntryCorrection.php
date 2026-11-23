<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Entry;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\Support\NoteSanitizer;

use function in_array;
use function is_scalar;
use function is_string;
use function preg_match;
use function strlen;

/**
 * Since 1.40.0: what {@see TimeEntryOperationsInterface::correct()} changes on an entry. Every part is optional: a null
 * time keeps the stored one, a null duration keeps the stored duration when the times do not change and becomes the new
 * span when they do, and the metadata is a patch (a key set to null is removed; keys not listed are kept).
 *
 * The `note` key is the person's note (cleaned and cut to {@see NoteSanitizer::MAX_LENGTH} characters like every note;
 * an empty note removes it). The `closed_by` key is the bundle's own timer mark and cannot be set. Immutable; built per
 * call.
 */
final readonly class TimeEntryCorrection
{
    /** Metadata keys: a lower-case letter, then up to 63 lower-case letters, digits or underscores. */
    public const METADATA_KEY_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    /** Longest string value of a metadata key, in bytes (the note is cut to its own limit). */
    public const METADATA_VALUE_MAX_BYTES = 2000;

    /** Metadata keys a correction may not set or remove. */
    public const RESERVED_METADATA_KEYS = ['closed_by'];

    /**
     * @param DateTimeImmutable|null $startedAt The new start (null = unchanged)
     * @param DateTimeImmutable|null $endedAt The new end (null = unchanged)
     * @param int|null $durationSeconds The new tracked duration, 1 to the span in seconds (null = see the class comment)
     * @param array<string, scalar|null> $metadata Metadata patch: key => new value, null removes the key
     *
     * @throws InvalidArgumentException When the duration is not positive, or a metadata key or value is not accepted
     */
    public function __construct(
        public ?DateTimeImmutable $startedAt = null,
        public ?DateTimeImmutable $endedAt = null,
        public ?int $durationSeconds = null,
        public array $metadata = [],
    ) {
        if ($durationSeconds !== null && $durationSeconds < 1) {
            throw new InvalidArgumentException('A corrected duration must be at least one second.');
        }
        foreach ($metadata as $key => $value) {
            if (!is_string($key) || preg_match(self::METADATA_KEY_PATTERN, $key) !== 1 || in_array($key, self::RESERVED_METADATA_KEYS, true)) {
                throw new InvalidArgumentException('A metadata key of a correction must match ' . self::METADATA_KEY_PATTERN . ' and not be reserved.');
            }
            if ($value !== null && (!is_scalar($value) || (is_string($value) && strlen($value) > self::METADATA_VALUE_MAX_BYTES))) {
                throw new InvalidArgumentException('A metadata value of a correction must be null or a scalar (strings up to ' . self::METADATA_VALUE_MAX_BYTES . ' bytes).');
            }
        }
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
        return new self($this->startedAt, $this->endedAt, $this->durationSeconds, ['note' => $note] + $this->metadata);
    }
}
