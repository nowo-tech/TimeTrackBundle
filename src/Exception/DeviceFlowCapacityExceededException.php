<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Exception;

use RuntimeException;

/**
 * `device/code` refused a new request: too many requests from the client's IPv4 /24 or IPv6 /64
 * (`client_api.device_flow.max_pending_per_prefix`), or too many unexpired requests in total
 * (`client_api.device_flow.max_pending`).
 */
final class DeviceFlowCapacityExceededException extends RuntimeException
{
    /**
     * Creates the exception with the seconds until the client may try again.
     */
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Too many pending device requests.');
    }
}
