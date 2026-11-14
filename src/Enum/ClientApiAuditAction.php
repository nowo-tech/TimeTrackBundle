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

    /** Since 1.13: a workday action was applied or refused as a conflict (the reason is the action). */
    case WorkdayAction = 'workday_action';

    /** Since 1.13: the owner submitted a timesheet through the client API. */
    case TimesheetSubmitted = 'timesheet_submitted';

    /** Since 1.13: the owner withdrew a timesheet through the client API. */
    case TimesheetWithdrawn = 'timesheet_withdrawn';

    /** Since 1.26: the worker acknowledged a monitoring policy version (the reason is `v<version>`). */
    case MonitoringAcknowledged = 'monitoring_acknowledged';

    /** Since 1.26: a screenshot was uploaded and stored (no image, no app or window name). */
    case MonitoringUploaded = 'monitoring_uploaded';

    /** Since 1.26: a monitoring request was refused (the reason is the refusal code); throttled like request_denied. */
    case MonitoringRefused = 'monitoring_refused';
}
