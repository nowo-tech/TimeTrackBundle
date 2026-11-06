<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Enum;

use Nowo\TimeTrackBundle\Event\CalendarConnectionChangedEvent;

/**
 * Since 1.14: state of a calendar connection (contract §3.5). A revoked connection is deleted, so `revoked` is only
 * reported in the {@see CalendarConnectionChangedEvent} of the revocation.
 */
enum CalendarConnectionStatus: string
{
    case Active  = 'active';
    case Paused  = 'paused';
    case Error   = 'error';
    case Revoked = 'revoked';
}
