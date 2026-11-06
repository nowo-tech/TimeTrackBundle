<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Psr\Log\LoggerInterface;

use function array_key_exists;
use function in_array;
use function is_array;
use function is_string;
use function parse_url;
use function strtolower;

/**
 * Since 1.15: the `privacy` block of `GET me`. The clients open `url`, so one that is not an absolute `http(s)` URL is
 * sent as null (with a warning that names no value); everything else is the host's text, passed as is.
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
        $parts = is_string($url) ? parse_url($url) : false;

        return is_array($parts)
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== '';
    }
}
