<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Subject;

use InvalidArgumentException;
use Nowo\TimeTrackBundle\Timesheet\ScopeKeys;

use function is_string;
use function preg_match;
use function strlen;

/**
 * Since 2.1.0 (Beacon spec 256): what time is logged on besides (or instead of) a task, such as a customer or the
 * organisation itself. Both parts are opaque host values compared byte for byte, never parsed (REQ-SEC-006).
 *
 * - `type`: lower-case ASCII, 1–32 bytes ({@see self::TYPE_PATTERN}); it is also the key of the provider that answers for
 *   it ({@see TimeSubjectProviderInterface::subjectType()}).
 * - `id`: 1–64 bytes, the scope-key rule (no edge blank, no control, format or invisible character, valid UTF-8), so
 *   `acme` and `acme ` never meet.
 *
 * Framework-free, immutable and built per call (FrankenPHP worker mode, kernel reset false). A reference that exists is
 * valid; it says nothing about whether the subject exists or who may use it: that is the provider's answer.
 */
final readonly class TimeSubjectRef
{
    /** The subject type rule. */
    public const TYPE_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/D';

    /** The longest subject id, in bytes. */
    public const MAX_ID_LENGTH = 64;

    /**
     * @param string $type The subject type (e.g. `customer`, `organisation`)
     * @param string $id The host's id of the subject in that type
     *
     * @throws InvalidArgumentException When the type or the id breaks its rule
     */
    public function __construct(public string $type, public string $id)
    {
        if (!self::isType($type)) {
            throw new InvalidArgumentException('A TimeTrack subject type must match ' . self::TYPE_PATTERN . '.');
        }
        if (!self::isId($id)) {
            throw new InvalidArgumentException('A TimeTrack subject id must be 1-64 bytes of valid UTF-8 with no edge blank and no control, format or invisible character.');
        }
    }

    /**
     * Whether the value is a valid subject type.
     */
    public static function isType(string $type): bool
    {
        return preg_match(self::TYPE_PATTERN, $type) === 1;
    }

    /**
     * Whether the value is a valid subject id.
     */
    public static function isId(string $id): bool
    {
        return $id !== '' && strlen($id) <= self::MAX_ID_LENGTH && ScopeKeys::isUsable($id);
    }

    /**
     * The reference of two stored columns, or null when both are null. Used by the bundle's readers.
     *
     * @throws InvalidArgumentException When only one is set, or a value breaks its rule (a row written by hand)
     */
    public static function fromColumns(?string $type, ?string $id): ?self
    {
        if ($type === null && $id === null) {
            return null;
        }
        if ($type === null || $id === null) {
            throw new InvalidArgumentException('A TimeTrack subject needs both its type and its id.');
        }

        return new self($type, $id);
    }

    /**
     * The reference of two stored columns, or null when they are not a valid pair (a row a host wrote by hand that the
     * `check-allocation` command reports). Readers never fail on such a row; they show no subject.
     */
    public static function tryFromColumns(mixed $type, mixed $id): ?self
    {
        if (!is_string($type) || !is_string($id) || !self::isType($type) || !self::isId($id)) {
            return null;
        }

        return new self($type, $id);
    }

    /**
     * Whether both references name the same subject (byte for byte).
     */
    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }

    /**
     * Whether two optional references name the same subject (both null counts as equal).
     */
    public static function same(?self $a, ?self $b): bool
    {
        return $a instanceof self ? $b instanceof self && $a->equals($b) : (!$b instanceof self);
    }

    /**
     * A key for grouping maps inside one call (`type` NUL `id`); never stored.
     */
    public function key(): string
    {
        return $this->type . "\0" . $this->id;
    }

    /**
     * Wire shape `{type, id}`.
     *
     * @return array{type: string, id: string}
     */
    public function toArray(): array
    {
        return ['type' => $this->type, 'id' => $this->id];
    }
}
