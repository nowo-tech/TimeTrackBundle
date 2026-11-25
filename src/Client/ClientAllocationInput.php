<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use InvalidArgumentException;
use Nowo\TimeTrackBundle\Subject\ActivityType;
use Nowo\TimeTrackBundle\Subject\TimeAllocationPatch;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;
use Nowo\TimeTrackBundle\Timesheet\ScopeKeys;

use function array_key_exists;
use function array_keys;
use function is_array;
use function is_string;
use function sort;

/**
 * Since 2.1.0 (Beacon spec 256, client API v0.4): reads the allocation fields of a client API body: `subject` (`{type,
 * id}`, or null to clear where a change is allowed), `activityTypeKey` (a key, or null to clear) and `scopeKey`. A
 * malformed value throws (the controllers answer `400 invalid_request`) before any provider is asked; the id from the
 * client is used only after `canLog`. Stateless.
 *
 * @internal
 */
final class ClientAllocationInput
{
    /**
     * The `subject` field: [whether it is present, the reference or null].
     *
     * @param array<string, mixed> $payload
     *
     * @throws InvalidArgumentException When it is not null nor exactly `{type, id}` with valid values
     *
     * @return array{0: bool, 1: TimeSubjectRef|null}
     */
    public static function subject(array $payload): array
    {
        if (!array_key_exists('subject', $payload)) {
            return [false, null];
        }
        $subject = $payload['subject'];
        if ($subject === null) {
            return [true, null];
        }
        if (!is_array($subject) || !self::hasExactlyKeys($subject, ['id', 'type']) || !is_string($subject['type']) || !is_string($subject['id'])) {
            throw new InvalidArgumentException('subject must be {"type": "...", "id": "..."} or null.');
        }

        return [true, new TimeSubjectRef($subject['type'], $subject['id'])];
    }

    /**
     * The `activityTypeKey` field: [whether it is present, the key or null].
     *
     * @param array<string, mixed> $payload
     *
     * @throws InvalidArgumentException When it is not null nor a valid key
     *
     * @return array{0: bool, 1: string|null}
     */
    public static function activityTypeKey(array $payload): array
    {
        if (!array_key_exists('activityTypeKey', $payload)) {
            return [false, null];
        }
        $key = $payload['activityTypeKey'];
        if ($key !== null && (!is_string($key) || !ActivityType::isKey($key))) {
            throw new InvalidArgumentException('activityTypeKey must match ' . ActivityType::KEY_PATTERN . ' or be null.');
        }

        return [true, $key];
    }

    /**
     * The optional `scopeKey` field of time without a task.
     *
     * @param array<string, mixed> $payload
     *
     * @throws InvalidArgumentException When it is not a usable non-empty scope key
     */
    public static function scopeKey(array $payload): ?string
    {
        $scopeKey = $payload['scopeKey'] ?? null;
        if ($scopeKey !== null && (!is_string($scopeKey) || $scopeKey === '' || !ScopeKeys::isUsable($scopeKey))) {
            throw new InvalidArgumentException('scopeKey must be one of your scope keys.');
        }

        return $scopeKey;
    }

    /**
     * The allocation change of `timer/stop` and `timer/allocate` (absent fields keep the current value).
     *
     * @param array<string, mixed> $payload
     *
     * @throws InvalidArgumentException When a field is malformed
     */
    public static function patch(array $payload): TimeAllocationPatch
    {
        $patch                    = TimeAllocationPatch::keep();
        [$hasSubject, $subject]   = self::subject($payload);
        [$hasActivity, $activity] = self::activityTypeKey($payload);
        if ($hasSubject) {
            $patch = $subject instanceof TimeSubjectRef ? $patch->withSubject($subject) : $patch->withoutSubject();
        }
        if ($hasActivity) {
            $patch = $activity === null ? $patch->withoutActivityType() : $patch->withActivityType($activity);
        }

        return $patch;
    }

    /**
     * @param array<mixed> $value
     * @param list<string> $keys Sorted
     */
    private static function hasExactlyKeys(array $value, array $keys): bool
    {
        $present = array_keys($value);
        sort($present);

        return $present === $keys;
    }
}
