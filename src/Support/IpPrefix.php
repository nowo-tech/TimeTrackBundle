<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Support;

use function filter_var;
use function inet_ntop;
use function inet_pton;
use function str_repeat;
use function substr;

use const FILTER_FLAG_IPV4;
use const FILTER_FLAG_IPV6;
use const FILTER_VALIDATE_IP;

/**
 * Truncates a client IP for the audit trail: IPv4 to its /24 (`203.0.113.0/24`), IPv6 to its /64
 * (`2001:db8:1:2::/64`). Anything else (no IP, garbage) gives null.
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

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return self::mask($ip, 3, 4) . '/24';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return self::mask($ip, 8, 16) . '/64';
        }

        return null;
    }

    private static function mask(string $ip, int $keepBytes, int $totalBytes): string
    {
        /** @var string $packed validated above */
        $packed = inet_pton($ip);

        return (string) inet_ntop(substr($packed, 0, $keepBytes) . str_repeat("\0", $totalBytes - $keepBytes));
    }
}
