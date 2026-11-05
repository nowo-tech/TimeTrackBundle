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

    /**
     * @param string $errorCode One of the class constants
     */
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
