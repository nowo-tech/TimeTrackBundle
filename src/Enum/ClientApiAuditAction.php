<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Enum;

use Nowo\TimeTrackBundle\Event\ClientApiEvent;

/**
 * Audited actions of the client API ({@see ClientApiEvent}).
 */
enum ClientApiAuditAction: string
{
    /** A session token was issued (login or device token exchange). */
    case TokenIssued = 'token_issued';

    /** A session token was revoked by its client (logout). */
    case TokenRevoked = 'token_revoked';

    /** Every session token of a credential was revoked (host deleted the credential). */
    case CredentialRevoked = 'credential_revoked';

    /** The user approved a device authorization request. */
    case DeviceApproved = 'device_approved';

    /** The user denied a device authorization request. */
    case DeviceDenied = 'device_denied';

    /** A request was refused (401 / 403 / 429); see the event reason. Rate limited per credential. */
    case RequestDenied = 'request_denied';
}
