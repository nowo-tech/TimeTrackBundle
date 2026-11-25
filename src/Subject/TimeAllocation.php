<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Subject;

use InvalidArgumentException;

/**
 * Since 2.1.0 (Beacon spec 256): what a new timer or entry is logged on (Q-TS-1): a task alone, a task and a subject
 * (only for a subject type whose provider {@see TimeSubjectProviderInterface::allowsTask()}), a subject alone, or nothing
 * (the 1.36 entry without a task, with `entries.allow_without_task`), plus an optional activity type on any of them.
 *
 * One value for every write path, so a half-set subject cannot exist. Whether a task and a subject may be combined, and
 * whether the actor may log on the subject, is checked by the write services. Framework-free, immutable, built per call.
 */
final readonly class TimeAllocation
{
    /**
     * @throws InvalidArgumentException When the task id is empty or the activity type key breaks its rule
     */
    private function __construct(
        public ?string $taskId,
        public ?TimeSubjectRef $subject,
        public ?string $activityTypeKey,
    ) {
        if ($taskId === '') {
            throw new InvalidArgumentException('A TimeTrack allocation task id cannot be empty.');
        }
        if ($activityTypeKey !== null && !ActivityType::isKey($activityTypeKey)) {
            throw new InvalidArgumentException('A TimeTrack activity type key must match ' . ActivityType::KEY_PATTERN . '.');
        }
    }

    /**
     * Time on a task, optionally for a subject next to it (a type that allows tasks).
     *
     * @throws InvalidArgumentException When the task id is empty or the activity type key breaks its rule
     */
    public static function task(string $taskId, ?TimeSubjectRef $subject = null, ?string $activityTypeKey = null): self
    {
        return new self($taskId, $subject, $activityTypeKey);
    }

    /**
     * Time on a subject alone.
     *
     * @throws InvalidArgumentException When the activity type key breaks its rule
     */
    public static function subject(TimeSubjectRef $subject, ?string $activityTypeKey = null): self
    {
        return new self(null, $subject, $activityTypeKey);
    }

    /**
     * Time on nothing (the 1.36 case without a task, with `entries.allow_without_task`).
     *
     * @throws InvalidArgumentException When the activity type key breaks its rule
     */
    public static function none(?string $activityTypeKey = null): self
    {
        return new self(null, null, $activityTypeKey);
    }

    /**
     * The 2.0 shapes as an allocation: a task id, or null for none.
     */
    public static function ofTask(?string $taskId): self
    {
        return $taskId === null ? self::none() : self::task($taskId);
    }

    /**
     * Whether the time has a task or a subject.
     */
    public function isAllocated(): bool
    {
        return $this->taskId !== null || $this->subject instanceof TimeSubjectRef;
    }
}
