<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Workday;

/**
 * Since 1.13 (R-GDPR §8): an action on the working-time register, with its route segment.
 */
enum WorkdayAction: string
{
    case ClockIn    = 'clock-in';
    case BreakStart = 'break-start';
    case BreakEnd   = 'break-end';
    case ClockOut   = 'clock-out';
}
