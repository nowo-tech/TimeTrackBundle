<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Entry;

use InvalidArgumentException;

use function count;
use function in_array;
use function is_finite;
use function is_float;
use function is_scalar;
use function is_string;
use function json_encode;
use function preg_match;
use function strlen;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_PARTIAL_OUTPUT_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Since 1.40.1 (audit ZAN-3 / ZAN-4): the rules for the metadata a host gives {@see TimeEntryCorrection} and
 * {@see ManualTimeEntryDraft}, and the limits of an entry's stored metadata after a write by id. Stateless.
 */
final class EntryMetadataRules
{
    /** Keys: a lower-case letter, then up to 63 lower-case letters, digits or underscores. */
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    /** Longest string value, in bytes (the note is cut to its own limit). */
    public const VALUE_MAX_BYTES = 2000;

    /** Keys a host may not set or remove: the bundle's own timer mark. */
    public const RESERVED_KEYS = ['closed_by'];

    /** Most keys an entry's metadata may hold after a write by id. */
    public const MAX_KEYS = 32;

    /** Largest JSON encoding of an entry's metadata after a write by id, in bytes. */
    public const MAX_ENCODED_BYTES = 16_384;

    /**
     * Keys whose string values are not personal free text: the personal-data erasure that keeps the owner
     * (`TimeEntryErasure::FreeText`) keeps them and clears every other string value (since 1.40.1).
     */
    public const STRUCTURAL_KEYS = ['closed_by', 'origin', 'provider'];

    /** Longest allowed age of a start, in days, a host may ask for (about ten years). */
    public const MAX_AGE_DAYS_CAP = 3650;

    /**
     * Checks a metadata map given by a host: keys, values, and at most {@see self::MAX_KEYS} keys.
     *
     * @param array<array-key, mixed> $metadata
     * @param bool $allowNull Whether a null value is accepted (a patch: null removes the key)
     * @param string $what Named in the error message
     *
     * @throws InvalidArgumentException When a key or a value is not accepted, or there are too many keys
     */
    public static function assertValid(array $metadata, bool $allowNull, string $what): void
    {
        if (count($metadata) > self::MAX_KEYS) {
            throw new InvalidArgumentException('The metadata of ' . $what . ' may hold at most ' . self::MAX_KEYS . ' keys.');
        }
        foreach ($metadata as $key => $value) {
            if (!is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1 || in_array($key, self::RESERVED_KEYS, true)) {
                throw new InvalidArgumentException('A metadata key of ' . $what . ' must match ' . self::KEY_PATTERN . ' and not be reserved.');
            }
            if (!self::isAcceptedValue($value, $allowNull)) {
                throw new InvalidArgumentException('A metadata value of ' . $what . ' must be ' . ($allowNull ? 'null or ' : '') . 'a scalar (finite numbers, valid UTF-8 strings up to ' . self::VALUE_MAX_BYTES . ' bytes).');
            }
        }
    }

    /**
     * Whether a stored metadata map is within {@see self::MAX_KEYS} and {@see self::MAX_ENCODED_BYTES}.
     *
     * @param array<array-key, mixed> $metadata
     */
    public static function fits(array $metadata): bool
    {
        return count($metadata) <= self::MAX_KEYS && self::encodedBytes($metadata) <= self::MAX_ENCODED_BYTES;
    }

    /**
     * Whether a write turns `$before` into metadata beyond the limits and larger than it was: an entry stored before the
     * limits existed may still lose keys or get smaller values, never grow.
     *
     * @param array<array-key, mixed> $before
     * @param array<array-key, mixed> $after
     */
    public static function grewBeyondLimits(array $before, array $after): bool
    {
        return !self::fits($after) && (count($after) > count($before) || self::encodedBytes($after) > self::encodedBytes($before));
    }

    /**
     * Checks an allowed age in days given by a host (null = the configured `manual_entries.max_age_days`).
     *
     * @throws InvalidArgumentException When it is negative or above {@see self::MAX_AGE_DAYS_CAP}
     */
    public static function assertMaxAgeDays(?int $maxAgeDays, string $what): void
    {
        if ($maxAgeDays !== null && ($maxAgeDays < 0 || $maxAgeDays > self::MAX_AGE_DAYS_CAP)) {
            throw new InvalidArgumentException('The allowed age of ' . $what . ' must be 0 to ' . self::MAX_AGE_DAYS_CAP . ' days.');
        }
    }

    private static function isAcceptedValue(mixed $value, bool $allowNull): bool
    {
        if ($value === null) {
            return $allowNull;
        }
        if (is_float($value)) {
            return is_finite($value);
        }

        return is_scalar($value) && (!is_string($value) || (strlen($value) <= self::VALUE_MAX_BYTES && preg_match('//u', $value) === 1));
    }

    /**
     * @param array<array-key, mixed> $metadata
     */
    private static function encodedBytes(array $metadata): int
    {
        return strlen((string) json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }
}
