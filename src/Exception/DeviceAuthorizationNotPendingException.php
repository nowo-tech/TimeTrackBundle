<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Exception;

use RuntimeException;

/**
 * Since 1.27.0 (Beacon spec 239): the device request was decided (approved or denied), exchanged or expired by another
 * request between the page load and this decision; nothing was issued or changed.
 */
final class DeviceAuthorizationNotPendingException extends RuntimeException
{
    /**
     * Creates the exception (a fixed message: no code, no user).
     */
    public function __construct()
    {
        parent::__construct('The device request is no longer pending.');
    }
}
