<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use RuntimeException;

/**
 * Since 1.14: a calendar sync failed. Carries a **code** only (stored as `last_error_code`); the message is the code,
 * never a provider message, URL or token.
 */
final class CalendarSyncException extends RuntimeException
{
    public const TOKEN_EXPIRED    = 'token_expired';
    public const RATE_LIMITED     = 'rate_limited';
    public const FORBIDDEN        = 'forbidden';
    public const PROVIDER_ERROR   = 'provider_error';
    public const INVALID_RESPONSE = 'invalid_response';
    public const TOO_LARGE        = 'too_large';
    public const NO_CREDENTIAL    = 'no_credential';
    public const NO_SOURCE        = 'no_source';
    public const NO_HTTP_CLIENT   = 'no_http_client';

    /** Since 1.20: the provider has no OAuth app (`calendar.oauth.app_provider` answered null or an incomplete app). */
    public const NOT_CONFIGURED = 'not_configured';

    /**
     * Since 2.3: Entra ID refused a token for a scope the person has not (or no longer) consented to (AADSTS65001);
     * the calendar grant itself may still work. Only from the extra-scope calls of {@see MicrosoftCalendarOAuthClient}.
     */
    public const CONSENT_REQUIRED = 'consent_required';

    /** Since 2.3: the stored grant lacks the requested extra scope; nothing was sent (ask for consent first). */
    public const SCOPE_NOT_GRANTED = 'scope_not_granted';

    /**
     * @param string $errorCode One of the class constants
     */
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
