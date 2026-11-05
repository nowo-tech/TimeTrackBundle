<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Meeting;

use function in_array;
use function is_array;
use function is_string;
use function parse_url;
use function preg_match;
use function str_ends_with;
use function strlen;
use function strtolower;

/**
 * Since 1.14 (contract §22.4, AC-27): re-validates a meeting join link before it leaves the API — `https` only, no user
 * information, no port but 443, no whitespace, control, quote, angle-bracket or backslash characters, at most 2048
 * characters, and a host on the allow-list (the ChatKit 1.19 providers): Teams (`teams.microsoft.com`,
 * `teams.live.com`), Meet (`meet.google.com`), Zoom (`zoom.us`, `*.zoom.us`, `zoomgov.com`, `*.zoomgov.com`), Jitsi
 * (`meet.jit.si` plus `meetings.jitsi_hosts`) and Webex (`*.webex.com`). Hosts are ASCII only (no IDN look-alikes).
 * Anything else is no link.
 */
final readonly class MeetingLinkDetector
{
    /** Longest accepted link. */
    public const MAX_LENGTH = 2048;

    /** Exact hosts per provider. */
    private const EXACT = [
        'teams.microsoft.com' => 'teams',
        'teams.live.com'      => 'teams',
        'meet.google.com'     => 'meet',
        'zoom.us'             => 'zoom',
        'zoomgov.com'         => 'zoom',
        'meet.jit.si'         => 'jitsi',
    ];

    /** Parent domains whose subdomains are accepted, per provider. */
    private const SUBDOMAINS = [
        '.zoom.us'     => 'zoom',
        '.zoomgov.com' => 'zoom',
        '.webex.com'   => 'webex',
    ];

    /**
     * @param list<string> $jitsiHosts Extra self-hosted Jitsi hosts (`meetings.jitsi_hosts`, exact, lowercase)
     */
    public function __construct(private array $jitsiHosts = [])
    {
    }

    /**
     * The accepted link and its provider, or null.
     *
     * @return array{url: string, provider: 'jitsi'|'meet'|'teams'|'webex'|'zoom'}|null
     */
    public function detect(?string $candidate): ?array
    {
        if ($candidate === null || $candidate === '' || strlen($candidate) > self::MAX_LENGTH
            || preg_match('/[\s\x00-\x1F\x7F"\'`<>\\\\]/', $candidate) === 1 || preg_match('#^https://#i', $candidate) !== 1) {
            return null;
        }
        $parts = parse_url($candidate);
        $host  = is_array($parts) && is_string($parts['host'] ?? null) ? strtolower($parts['host']) : '';
        if ($host === '' || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)
            || preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/D', $host) !== 1) {
            return null;
        }
        $provider = $this->provider($host);

        return $provider !== null ? ['url' => $candidate, 'provider' => $provider] : null;
    }

    /**
     * @return 'jitsi'|'meet'|'teams'|'webex'|'zoom'|null
     */
    private function provider(string $host): ?string
    {
        if (isset(self::EXACT[$host])) {
            return self::EXACT[$host];
        }
        if (in_array($host, $this->jitsiHosts, true)) {
            return 'jitsi';
        }
        foreach (self::SUBDOMAINS as $suffix => $provider) {
            if (str_ends_with($host, $suffix)) {
                return $provider;
            }
        }

        return null;
    }
}
