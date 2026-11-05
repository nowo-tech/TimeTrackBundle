<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use RuntimeException;

/**
 * Since 1.14: a calendar operation was refused. The message is the stable error code (`calendar_disabled`,
 * `invalid_scope`, `provider_not_allowed`, `mode_not_allowed`, `invalid_credential_ref`, `invalid_consent_version`,
 * `invalid_pattern`, `too_many_rules`, `not_trackable`, `not_found`), never user data.
 */
final class CalendarException extends RuntimeException
{
    /**
     * @param string $errorCode Stable code
     */
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
