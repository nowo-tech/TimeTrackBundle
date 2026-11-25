<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Subject;

use InvalidArgumentException;

/**
 * Since 2.1.0 (Beacon spec 256): a change of the subject and activity type of a running timer or an entry, tri-state per
 * field because a change must tell *keep* from *clear*: {@see self::keep()}, then {@see self::withSubject()},
 * {@see self::withoutSubject()}, {@see self::withActivityType()}, {@see self::withoutActivityType()}. The task itself is
 * never changed by a patch (moving task time stays `TaskTimeMover`). Framework-free, immutable, built per call.
 */
final readonly class TimeAllocationPatch
{
    private function __construct(
        public bool $changesSubject,
        public ?TimeSubjectRef $subject,
        public bool $changesActivityType,
        public ?string $activityTypeKey,
    ) {
    }

    /**
     * Changes nothing.
     */
    public static function keep(): self
    {
        return new self(false, null, false, null);
    }

    /**
     * This patch, setting the subject.
     */
    public function withSubject(TimeSubjectRef $subject): self
    {
        return new self(true, $subject, $this->changesActivityType, $this->activityTypeKey);
    }

    /**
     * This patch, clearing the subject.
     */
    public function withoutSubject(): self
    {
        return new self(true, null, $this->changesActivityType, $this->activityTypeKey);
    }

    /**
     * This patch, setting the activity type.
     *
     * @throws InvalidArgumentException When the key breaks its rule
     */
    public function withActivityType(string $key): self
    {
        if (!ActivityType::isKey($key)) {
            throw new InvalidArgumentException('A TimeTrack activity type key must match ' . ActivityType::KEY_PATTERN . '.');
        }

        return new self($this->changesSubject, $this->subject, true, $key);
    }

    /**
     * This patch, clearing the activity type.
     */
    public function withoutActivityType(): self
    {
        return new self($this->changesSubject, $this->subject, true, null);
    }

    /**
     * Whether the patch changes nothing.
     */
    public function isKeep(): bool
    {
        return !$this->changesSubject && !$this->changesActivityType;
    }

    /**
     * The subject after the patch.
     */
    public function subjectAfter(?TimeSubjectRef $current): ?TimeSubjectRef
    {
        return $this->changesSubject ? $this->subject : $current;
    }

    /**
     * The activity type key after the patch.
     */
    public function activityTypeAfter(?string $current): ?string
    {
        return $this->changesActivityType ? $this->activityTypeKey : $current;
    }

    /**
     * Whether the patch really changes the subject (a different one, or a set one cleared).
     */
    public function changesSubjectOf(?TimeSubjectRef $current): bool
    {
        return $this->changesSubject && !TimeSubjectRef::same($current, $this->subject);
    }

    /**
     * Whether the patch really changes the activity type.
     */
    public function changesActivityTypeOf(?string $current): bool
    {
        return $this->changesActivityType && $current !== $this->activityTypeKey;
    }
}
