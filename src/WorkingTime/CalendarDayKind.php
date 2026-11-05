<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\WorkingTime;

/**
 * Since 1.13: kind of a calendar day (contract §4.2). Kinds only, never causes: a medical absence is `absence`.
 */
enum CalendarDayKind: string
{
    case Working      = 'working';
    case Rest         = 'rest';
    case Holiday      = 'holiday';
    case Vacation     = 'vacation';
    case Leave        = 'leave';
    case Absence      = 'absence';
    case Compensatory = 'compensatory';
    /** Before the user joined the scope or after they left: excluded from every total. */
    case None = 'none';
}
