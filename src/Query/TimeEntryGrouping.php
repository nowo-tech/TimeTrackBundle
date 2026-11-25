<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Query;

/**
 * Since 2.1.0 (Beacon spec 256): what {@see TimeEntryTotalsQueryInterface::totals()} groups by.
 */
enum TimeEntryGrouping
{
    /** Subject (type and id); entries without a subject form one group with a null subject. */
    case Subject;

    /** Subject type. */
    case SubjectType;

    /** Activity type key. */
    case ActivityType;

    /** Subject and activity type. */
    case SubjectAndActivityType;

    /** Owner id. */
    case Owner;

    /** Task id (entries without a task form one group). */
    case Task;

    /** Allocation ({@see AllocationFilter}: task, task + subject, subject, none). */
    case Allocation;
}
