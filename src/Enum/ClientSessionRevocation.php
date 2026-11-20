<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Enum;

use Nowo\TimeTrackBundle\Client\ClientTokenRevoker;

/**
 * Since 1.34.0: the result of {@see ClientTokenRevoker::revokeById()}.
 */
enum ClientSessionRevocation: string
{
    /** The session existed, the actor may revoke it, and this call deleted it: its token no longer authenticates. */
    case Revoked = 'revoked';

    /**
     * Nothing was revoked by this call: no such session (never existed, expired and purged, already revoked — also by a
     * concurrent call), or the actor may not revoke it. The same answer for all of them, so a caller who does not own
     * the session learns nothing about it; a host maps it to `404`.
     */
    case NotFound = 'not_found';

    /**
     * Whether this call revoked the session.
     */
    public function isRevoked(): bool
    {
        return $this === self::Revoked;
    }
}
