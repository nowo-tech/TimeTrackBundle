<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Enum;

enum ClientType: string
{
    case Extension = 'extension';
    case Desktop   = 'desktop';
    case Web       = 'web';
}
