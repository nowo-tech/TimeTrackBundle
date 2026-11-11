<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use function base64_encode;
use function hash_equals;
use function is_string;
use function preg_match;
use function random_bytes;
use function rtrim;
use function strtr;

/**
 * Since 1.19 (audit LZ-I1): the OAuth `state` of one authorization flow. Create it with {@see self::create()}, keep it in
 * the session bound to the user (with the PKCE verifier), compare it on the callback with {@see self::matches()}
 * (constant time) and discard it after that one use. Pure functions, no state.
 */
final class CalendarOAuthState
{
    /** What {@see CalendarOAuthClientInterface::authorizationUrl()} accepts: 32-512 unreserved characters. */
    public const PATTERN = '/^[A-Za-z0-9._~-]{32,512}$/D';

    /**
     * A new random state (32 random bytes, 43 base64url characters).
     */
    public static function create(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Whether the callback's state is the stored one (constant time; false when either is missing or malformed).
     */
    public static function matches(?string $expected, mixed $received): bool
    {
        return $expected !== null && is_string($received) && self::isValid($expected) && hash_equals($expected, $received);
    }

    /**
     * Whether a state has the accepted shape and enough length.
     */
    public static function isValid(string $state): bool
    {
        return preg_match(self::PATTERN, $state) === 1;
    }
}
