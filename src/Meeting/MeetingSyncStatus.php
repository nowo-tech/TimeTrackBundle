<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Meeting;

/**
 * Since 1.14 (contract §22.4): the state of the user's meeting sync in the host. `Disabled` (the organization switched
 * calendars off, FR-006) is never returned by the API: the route answers 404 instead.
 */
enum MeetingSyncStatus: string
{
    case Connected      = 'connected';
    case NotConnected   = 'not_connected';
    case NeedsReconnect = 'needs_reconnect';
    case Syncing        = 'syncing';
    case Disabled       = 'disabled';
}
