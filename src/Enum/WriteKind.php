<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Enum;

use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;

/**
 * Since 1.13: the bundle write path a period lock check is made for ({@see PeriodLockGuard}).
 */
enum WriteKind: string
{
    case TimerStart  = 'timer_start';
    case TimerStop   = 'timer_stop';
    case IdleResolve = 'idle_resolve';
    case Import      = 'import';
    case Delete      = 'delete';
    case Edit        = 'edit';
    case Ledger      = 'ledger';
    case EntryCreate = 'entry_create';
}
