<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Enum;

/**
 * Client that runs a timer or holds a Bearer token.
 */
enum ClientType: string
{
    case Extension = 'extension';
    case Desktop   = 'desktop';
    case Web       = 'web';
}
