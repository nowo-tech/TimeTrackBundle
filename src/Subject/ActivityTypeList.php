<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Subject;

use InvalidArgumentException;

use function array_filter;
use function array_values;
use function count;

/**
 * Since 2.1.0 (Beacon spec 256): every activity type of one tenant, retired ones included
 * ({@see ActivityTypeProviderInterface::activityTypesFor()}). Keys are unique; at most {@see self::MAX_TYPES} types
 * (more is a host bug: the bundle logs a warning and uses an empty list rather than an unbounded payload).
 * Framework-free, immutable, built per call.
 */
final readonly class ActivityTypeList
{
    /** Most activity types per tenant. */
    public const MAX_TYPES = 500;

    /**
     * @param array<string, ActivityType> $types Keyed by key
     */
    private function __construct(private array $types)
    {
    }

    /**
     * The list of these types.
     *
     * @param list<ActivityType> $types
     *
     * @throws InvalidArgumentException When a key appears twice or there are more than {@see self::MAX_TYPES} types
     */
    public static function of(array $types): self
    {
        if (count($types) > self::MAX_TYPES) {
            throw new InvalidArgumentException('A TimeTrack activity type list holds at most ' . self::MAX_TYPES . ' types.');
        }
        $keyed = [];
        foreach ($types as $type) {
            if (isset($keyed[$type->key])) {
                throw new InvalidArgumentException('A TimeTrack activity type list cannot hold the same key twice.');
            }
            $keyed[$type->key] = $type;
        }

        return new self($keyed);
    }

    /**
     * No activity type.
     */
    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * The type with this key (compared byte for byte), retired or not; null when the tenant has none.
     */
    public function find(string $key): ?ActivityType
    {
        $type = $this->types[$key] ?? null;

        return $type !== null && $type->key === $key ? $type : null;
    }

    /**
     * The types new entries may use.
     *
     * @return list<ActivityType>
     */
    public function active(): array
    {
        return array_values(array_filter($this->types, static fn (ActivityType $type): bool => $type->active));
    }

    /**
     * Every type, retired ones included.
     *
     * @return list<ActivityType>
     */
    public function all(): array
    {
        return array_values($this->types);
    }
}
