<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\TaskMove;

/**
 * Since 1.17: why {@see TaskTimeMover} left an entry with its old board and scope. The entry and its time stay as they were.
 */
enum TaskTimeKeptReason: string
{
    /** On a move into another scope, the entry's period is locked, approved or submitted in its current scope: moving it would change what that period bills. */
    case SourcePeriodLocked = 'source_period_locked';

    /** On a move into another scope, the owner's period at the entry's date is locked, approved or submitted in the target scope: the entry would land in it. */
    case TargetPeriodLocked = 'target_period_locked';

    /** The entry was anonymised: an anonymised entry is never written again. */
    case Anonymised = 'anonymised';

    /** The caller named the board the move left, and the entry is on neither that board nor the target: a stale or replayed call. */
    case OnAnotherBoard = 'on_another_board';

    /** With timesheets on, the entry has no user and no person reference, so no period can be checked (the guard's rule). */
    case OwnerUnknown = 'owner_unknown';
}
