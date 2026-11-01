<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Enum;

/**
 * Origin of a recorded time entry (web UI, browser extension or desktop app).
 */
enum TimeEntrySource: string
{
    case Web       = 'web';
    case Extension = 'extension';
    case Desktop   = 'desktop';
}
