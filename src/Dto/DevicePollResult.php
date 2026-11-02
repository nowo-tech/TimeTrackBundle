<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Dto;

/**
 * Answer of `POST device/token`: an error code (`authorization_pending`, `slow_down`, `expired_token`,
 * `access_denied`) or the issued session token.
 */
final readonly class DevicePollResult
{
    public const PENDING = 'authorization_pending';

    public const SLOW_DOWN = 'slow_down';

    public const EXPIRED = 'expired_token';

    public const DENIED = 'access_denied';

    /**
     * @param string|null $error Error code (null on success)
     * @param array{token: string, expiresAt: string, scopes: list<string>}|null $token Issued token (null on error)
     */
    private function __construct(
        public ?string $error,
        public ?array $token = null,
    ) {
    }

    /** Polling error. */
    public static function error(string $code): self
    {
        return new self($code);
    }

    /**
     * Issued token.
     *
     * @param array{token: string, expiresAt: string, scopes: list<string>} $token
     */
    public static function issued(array $token): self
    {
        return new self(null, $token);
    }
}
