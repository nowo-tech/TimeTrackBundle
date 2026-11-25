<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Subject;

use Nowo\TimeTrackBundle\Exception\TimeAllocationException;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefs;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 2.1.0 (Beacon spec 256, design §7.1): the subject and activity type checks of every write path, run before the
 * unit of work (so a slow host provider never holds the bundle's row locks). The task checks stay where they were (task
 * access, then trackability): on a task entry the task is the gate, and the subject is checked in addition.
 *
 * - Subject, when set or changed: a provider must answer for its type, a subject next to a task needs a type that allows
 *   tasks (`allocation_conflict`), and the owner must be allowed to log on it in the entry's scope; unknown type, unknown
 *   id and no access share `subject_forbidden` (no oracle).
 * - Activity type, when set or changed: it must exist and be active in the entry's scope.
 * - Requirements (`entries.require_allocation`, `entries.require_activity_type`) on interactive writes only.
 *
 * Stateless: every answer is asked per call (FrankenPHP worker mode, kernel reset false).
 *
 * @internal
 */
final readonly class AllocationGuard
{
    /**
     * @param bool $requireAllocation `entries.require_allocation`
     * @param bool $requireActivityType `entries.require_activity_type` (effective only with an activity type provider)
     * @param bool $allowWithoutTask `entries.allow_without_task` (whether time may stay unallocated)
     */
    public function __construct(
        private TimeSubjectRegistry $subjects,
        private ActivityTypeCatalog $activityTypes,
        private bool $requireAllocation = false,
        private bool $requireActivityType = false,
        private bool $allowWithoutTask = false,
    ) {
    }

    /**
     * A guard with no provider and no requirement (the 2.0 behaviour).
     */
    public static function off(): self
    {
        return new self(TimeSubjectRegistry::none(), new ActivityTypeCatalog());
    }

    /**
     * The subject providers.
     */
    public function subjects(): TimeSubjectRegistry
    {
        return $this->subjects;
    }

    /**
     * The activity types.
     */
    public function activityTypes(): ActivityTypeCatalog
    {
        return $this->activityTypes;
    }

    /**
     * Whether `entries.require_allocation` is on.
     */
    public function requiresAllocation(): bool
    {
        return $this->requireAllocation;
    }

    /**
     * Whether `entries.require_activity_type` is on and effective (an activity type provider is configured).
     */
    public function requiresActivityType(): bool
    {
        return $this->requireActivityType && $this->activityTypes->isEnabled();
    }

    /**
     * Checks a subject that is set or changed on the owner's time.
     *
     * @param string|null $scopeKey The entry's tenant scope (the task's on a task entry; null = none)
     * @param bool $withTask Whether the subject sits next to a task
     *
     * @throws TimeAllocationException `subject_forbidden` or `allocation_conflict`
     */
    public function assertSubject(UserInterface $owner, ?string $scopeKey, TimeSubjectRef $subject, bool $withTask): void
    {
        if (!$this->subjects->has($subject->type)) {
            throw new TimeAllocationException(TimeAllocationException::SUBJECT_FORBIDDEN);
        }
        if ($withTask && !$this->subjects->allowsTask($subject->type)) {
            throw new TimeAllocationException(TimeAllocationException::ALLOCATION_CONFLICT);
        }
        if (!$this->subjects->canLog(new TimeSubjectContext(TimeTrackUserRefs::fromUser($owner), $scopeKey ?? ''), $subject)) {
            throw new TimeAllocationException(TimeAllocationException::SUBJECT_FORBIDDEN);
        }
    }

    /**
     * Checks a subject next to a task against the constant `allowsTask()` map only (no host call; used again under the
     * owner's lock).
     *
     * @throws TimeAllocationException `allocation_conflict`
     */
    public function assertAllowsTask(?TimeSubjectRef $subject, bool $withTask): void
    {
        if ($withTask && $subject instanceof TimeSubjectRef && !$this->subjects->allowsTask($subject->type)) {
            throw new TimeAllocationException(TimeAllocationException::ALLOCATION_CONFLICT);
        }
    }

    /**
     * The activity type a write sets, checked in the entry's scope.
     *
     * @param ActivityTypeList|null $types The scope's types when the caller already read them
     *
     * @throws TimeAllocationException `activity_type_unknown` or `activity_type_inactive`
     */
    public function activityType(?string $scopeKey, string $key, ?ActivityTypeList $types = null): ActivityType
    {
        if (!$this->activityTypes->isEnabled()) {
            throw new TimeAllocationException(TimeAllocationException::ACTIVITY_TYPE_UNKNOWN);
        }
        $type = ($types ?? $this->activityTypes->listFor($scopeKey))->find($key);
        if (!$type instanceof ActivityType) {
            throw new TimeAllocationException(TimeAllocationException::ACTIVITY_TYPE_UNKNOWN);
        }
        if (!$type->active) {
            throw new TimeAllocationException(TimeAllocationException::ACTIVITY_TYPE_INACTIVE);
        }

        return $type;
    }

    /**
     * Checks the allocation of a new timer or entry (§7.1 steps 4–6) and returns its activity type (null = none).
     *
     * @param string|null $scopeKey The entry's tenant scope (the task's with a task)
     * @param bool $interactive Whether the requirements apply (a manual entry; never a system write)
     *
     * @throws TimeAllocationException
     */
    public function checkNew(UserInterface $owner, ?string $scopeKey, TimeAllocation $allocation, bool $interactive): ?ActivityType
    {
        if ($allocation->subject instanceof TimeSubjectRef) {
            $this->assertSubject($owner, $scopeKey, $allocation->subject, $allocation->taskId !== null);
        }
        $type = $allocation->activityTypeKey === null ? null : $this->activityType($scopeKey, $allocation->activityTypeKey);
        if ($interactive) {
            $this->assertRequirements($allocation->taskId !== null, $allocation->subject, $allocation->activityTypeKey);
        }

        return $type;
    }

    /**
     * The interactive requirements: a task or a subject with `require_allocation`, an activity type with an effective
     * `require_activity_type`.
     *
     * @throws TimeAllocationException `allocation_required` or `activity_type_required`
     */
    public function assertRequirements(bool $hasTask, ?TimeSubjectRef $subject, ?string $activityTypeKey): void
    {
        $code = $this->missingRequirement($hasTask, $subject, $activityTypeKey);
        if ($code !== null) {
            throw new TimeAllocationException($code);
        }
    }

    /**
     * The code of the first interactive requirement the time misses, or null.
     */
    public function missingRequirement(bool $hasTask, ?TimeSubjectRef $subject, ?string $activityTypeKey): ?string
    {
        if ($this->requireAllocation && !$hasTask && !$subject instanceof TimeSubjectRef) {
            return TimeAllocationException::ALLOCATION_REQUIRED;
        }
        if ($this->requiresActivityType() && $activityTypeKey === null) {
            return TimeAllocationException::ACTIVITY_TYPE_REQUIRED;
        }

        return null;
    }

    /**
     * Refuses a change that clears the subject of time without a task (it would be left unallocated) when
     * `entries.require_allocation` is on or `entries.allow_without_task` is off.
     *
     * @throws TimeAllocationException `allocation_required`
     */
    public function assertMayClearSubject(bool $hasTask, ?TimeSubjectRef $before, ?TimeSubjectRef $after): void
    {
        if (!$hasTask && $before instanceof TimeSubjectRef && !$after instanceof TimeSubjectRef && ($this->requireAllocation || !$this->allowWithoutTask)) {
            throw new TimeAllocationException(TimeAllocationException::ALLOCATION_REQUIRED);
        }
    }

    /**
     * The billable default of a new entry (Q-TS-9): the activity type's, else the host provider's (asked by the caller).
     *
     * @param callable(): ?bool $hostDefault The `TimeEntryBillableDefaultProviderInterface` answer
     */
    public static function billableDefault(?ActivityType $type, callable $hostDefault): ?bool
    {
        return $type->defaultBillable ?? $hostDefault();
    }
}
