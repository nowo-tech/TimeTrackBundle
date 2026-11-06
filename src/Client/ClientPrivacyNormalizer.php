<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Psr\Log\LoggerInterface;

use function array_key_exists;
use function in_array;
use function is_array;
use function is_string;
use function parse_url;
use function preg_match;
use function strtolower;

/**
 * Since 1.15: the `privacy` block of `GET me`. The clients open `url`, so one that is not a plain absolute `http(s)` URL (no
 * credentials, blanks, controls or backslashes; a real host name or address) is sent as null (with a warning that names no value); everything else is the host's text, passed as is.
 */
final class ClientPrivacyNormalizer
{
    /**
     * The block as sent.
     *
     * @param array<string, mixed>|null $privacy
     *
     * @return array<string, mixed>|null
     */
    public static function normalize(?array $privacy, LoggerInterface $logger): ?array
    {
        if ($privacy === null || !array_key_exists('url', $privacy) || $privacy['url'] === null || self::isWebUrl($privacy['url'])) {
            return $privacy;
        }
        $logger->warning('TimeTrack dropped a privacy URL that is not absolute http(s).', ['bundle' => 'time_track', 'action' => 'client_privacy']);
        $privacy['url'] = null;

        return $privacy;
    }

    private static function isWebUrl(mixed $url): bool
    {
        // Blanks, controls and backslashes are read differently by parsers and browsers.
        if (!is_string($url) || preg_match('/[\s\p{Cc}\\\\]/u', $url) !== 0) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts)
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            // Credentials make `https://ex.test@evil.test/` read as `ex.test`.
            && !isset($parts['user']) && !isset($parts['pass'])
            && preg_match('/^(?:\[[0-9A-Fa-f:.]+\]|[\p{L}\p{N}.-]+)$/u', $parts['host'] ?? '') === 1;
    }
}
