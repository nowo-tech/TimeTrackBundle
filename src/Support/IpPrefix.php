<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Support;

use function filter_var;
use function inet_ntop;
use function inet_pton;
use function str_repeat;
use function str_starts_with;
use function substr;

use const FILTER_FLAG_IPV4;
use const FILTER_FLAG_IPV6;
use const FILTER_VALIDATE_IP;

/**
 * Truncates a client IP for the audit trail: IPv4 to its /24 (`203.0.113.0/24`), IPv6 to its /64
 * (`2001:db8:1:2::/64`); an IPv4-mapped IPv6 address counts as its IPv4 address. Anything else (no IP, garbage) gives null. {@see self::rateLimitKey()} keys the per-IP limits.
 */
final class IpPrefix
{
    /**
     * Network prefix of the IP, or null.
     */
    public static function of(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        $ip = self::unmapIpv4($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return self::mask($ip, 3, 4) . '/24';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return self::mask($ip, 8, 16) . '/64';
        }

        return null;
    }

    /**
     * Rate-limit key of a client IP: an IPv4 address as is, an IPv6 address by its /64 (one subscriber usually holds a
     * whole /64, so per-address keys would let it rotate past every per-IP limit). Anything else is returned unchanged.
     */
    public static function rateLimitKey(?string $ip): string
    {
        $ip = self::unmapIpv4((string) $ip);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return self::mask($ip, 8, 16) . '/64';
        }

        return $ip;
    }

    /**
     * IPv4 address of an IPv4-mapped IPv6 address (`::ffff:203.0.113.7` → `203.0.113.7`), so mapped clients are keyed
     * per IPv4 address instead of sharing one `::/64` bucket. Any other value is returned unchanged.
     */
    private static function unmapIpv4(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $ip;
        }

        /** @var string $packed validated above */
        $packed = inet_pton($ip);

        return str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff") ? (string) inet_ntop(substr($packed, 12)) : $ip;
    }

    private static function mask(string $ip, int $keepBytes, int $totalBytes): string
    {
        /** @var string $packed validated above */
        $packed = inet_pton($ip);

        return (string) inet_ntop(substr($packed, 0, $keepBytes) . str_repeat("\0", $totalBytes - $keepBytes));
    }
}
