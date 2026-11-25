<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Query;

/**
 * Since 2.1.0 (Beacon spec 256): which allocations a {@see TimeEntryCriteria} keeps (`task_id IS [NOT] NULL AND
 * subject_type IS [NOT] NULL`). Also the group key of {@see TimeEntryGrouping::Allocation}.
 */
enum AllocationFilter: string
{
    /** Every entry. */
    case Any = 'any';

    /** A task and no subject. */
    case TaskOnly = 'task';

    /** A task and a subject. */
    case TaskAndSubject = 'task_subject';

    /** A subject and no task. */
    case SubjectOnly = 'subject';

    /** Neither a task nor a subject. */
    case Unallocated = 'none';

    /**
     * The allocation of a row.
     */
    public static function of(bool $hasTask, bool $hasSubject): self
    {
        return match (true) {
            $hasTask && $hasSubject => self::TaskAndSubject,
            $hasTask                => self::TaskOnly,
            $hasSubject             => self::SubjectOnly,
            default                 => self::Unallocated,
        };
    }

    /**
     * Whether a row with this task / subject presence matches.
     */
    public function matches(bool $hasTask, bool $hasSubject): bool
    {
        return $this === self::Any || self::of($hasTask, $hasSubject) === $this;
    }
}
