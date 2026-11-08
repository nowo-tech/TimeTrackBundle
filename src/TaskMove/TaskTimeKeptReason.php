<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\TaskMove;

/**
 * Since 1.17: why {@see TaskTimeMover} left an entry with its old board and scope. The entry and its time stay as they were.
 */
enum TaskTimeKeptReason: string
{
    /** The entry's period is locked, approved or submitted in its current scope: moving it would change what that period bills. */
    case SourcePeriodLocked = 'source_period_locked';

    /** The owner's period at the entry's date is locked, approved or submitted in the target scope: the entry would land in it. */
    case TargetPeriodLocked = 'target_period_locked';

    /** The entry was anonymised: an anonymised entry is never written again. */
    case Anonymised = 'anonymised';

    /** With timesheets on, the entry has no user and no person reference, so no period can be checked (the guard's rule). */
    case OwnerUnknown = 'owner_unknown';
}
