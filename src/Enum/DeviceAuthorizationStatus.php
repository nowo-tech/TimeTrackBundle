<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Enum;

/**
 * State of a device authorization request (RFC 8628 device flow).
 */
enum DeviceAuthorizationStatus: string
{
    /** Waiting for the user to enter the user code and decide. */
    case Pending = 'pending';

    /** Approved by the user; the device may exchange its device code once. */
    case Approved = 'approved';

    /** Denied by the user. */
    case Denied = 'denied';

    /** Approved and exchanged for a session token (single use). */
    case Consumed = 'consumed';
}
