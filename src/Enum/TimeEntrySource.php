<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Enum;

enum TimeEntrySource: string
{
    case Web       = 'web';
    case Extension = 'extension';
    case Desktop   = 'desktop';
}
