<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Subject;

use InvalidArgumentException;

use function preg_match;

/**
 * Since 2.1.0 (Beacon spec 256): a kind of work a tenant logs time as (e.g. `support_call`, `internal_meeting`), as an
 * {@see ActivityTypeProviderInterface} lists it. Independent of the allocation: a task entry, a subject entry and an
 * unallocated entry may each carry one.
 *
 * - `key`: {@see self::KEY_PATTERN}, compared byte for byte, stored on the entry.
 * - `label` and `group`: cleaned like a subject label ({@see TimeSubjectLabel::clean()}); plain text.
 * - `defaultBillable`: the billable flag a new entry gets when no explicit one is given (precedence: explicit, then this,
 *   then `TimeEntryBillableDefaultProviderInterface`, then none; Q-TS-9).
 * - `active`: false = retired: still labelled on old entries, refused on new writes and on a correction that sets it.
 *
 * Framework-free, immutable, built per call.
 */
final readonly class ActivityType
{
    /** The activity type key rule. */
    public const KEY_PATTERN = '/^[a-z0-9][a-z0-9_.-]{0,63}$/D';

    /** The cleaned label. */
    public string $label;

    /** The cleaned group, or null. */
    public ?string $group;

    /**
     * @param string $key The key stored on entries
     * @param string $label The name to show
     * @param bool|null $defaultBillable Billable default of new entries of this type (null = none)
     * @param bool $active false = retired (labelled, refused on new writes)
     * @param string|null $group Optional grouping in pickers ("Internal", "Customer")
     *
     * @throws InvalidArgumentException When the key breaks its rule or the label is empty after cleaning
     */
    public function __construct(
        public string $key,
        string $label,
        public ?bool $defaultBillable = null,
        public bool $active = true,
        ?string $group = null,
    ) {
        if (!self::isKey($key)) {
            throw new InvalidArgumentException('A TimeTrack activity type key must match ' . self::KEY_PATTERN . '.');
        }
        $clean = TimeSubjectLabel::clean($label);
        if ($clean === null) {
            throw new InvalidArgumentException('A TimeTrack activity type label cannot be empty.');
        }
        $this->label = $clean;
        $this->group = $group === null ? null : TimeSubjectLabel::clean($group);
    }

    /**
     * Whether the value is a valid activity type key.
     */
    public static function isKey(string $key): bool
    {
        return preg_match(self::KEY_PATTERN, $key) === 1;
    }

    /**
     * Wire shape `{key, label, group, active}`.
     *
     * @return array{key: string, label: string, group: string|null, active: bool}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'group' => $this->group, 'active' => $this->active];
    }
}
