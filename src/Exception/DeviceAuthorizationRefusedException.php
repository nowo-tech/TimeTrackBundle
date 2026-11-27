<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Exception;

use RuntimeException;

/**
 * Since 2.2.0 (audit ZBV-M1, ZBV-L1): the approval of a device request was refused under its lock and the request was
 * denied; no credential was minted and no token can be exchanged.
 *
 * - {@see self::LINK_MISMATCH}: the device proved its device with a token of **another** user (`device/code` with a
 *   Bearer token): only the owner of that token may approve it.
 * - {@see self::FAMILY_UNAVAILABLE}: the request's scope family (or one of its scopes) is no longer registered.
 * - {@see self::FAMILY_NOT_GRANTED}: since 2.4.0, the host's capabilities provider does not let the approving user use
 *   the request's scope family (Beacon spec 262 block B).
 */
final class DeviceAuthorizationRefusedException extends RuntimeException
{
    /** The approving user is not the owner of the token the device linked. */
    public const LINK_MISMATCH = 'device_link_mismatch';

    /** The request's scope family or one of its scopes is no longer registered. */
    public const FAMILY_UNAVAILABLE = 'scope_family_unavailable';

    /** Since 2.4.0: the approving user may not use the request's scope family (host capabilities provider). */
    public const FAMILY_NOT_GRANTED = 'scope_family_not_granted';

    /**
     * @param string $reason {@see self::LINK_MISMATCH}, {@see self::FAMILY_UNAVAILABLE} or {@see self::FAMILY_NOT_GRANTED}
     */
    public function __construct(public readonly string $reason)
    {
        parent::__construct('The device request was refused: ' . $reason . '.');
    }
}
