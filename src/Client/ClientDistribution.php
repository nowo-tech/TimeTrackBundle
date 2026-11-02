<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use function is_string;
use function preg_match;

/**
 * Download / store links of the browser extension and the desktop app (contract v0.2 §4/§6). Only absolute
 * `https://` links (or `http://` ones) are kept; anything else (relative, `javascript:`, empty) is dropped.
 */
final readonly class ClientDistribution
{
    /** Link keys in display order. */
    public const LINKS = ['chrome', 'edge', 'firefox', 'windows', 'macos', 'linux'];

    /** @var array<string, string> */
    private array $links;

    /**
     * @param array<string, mixed> $links Links by key ({@see self::LINKS}); other keys are ignored
     * @param string|null $latestVersion Latest desktop version (shown next to the desktop links)
     * @param string|null $updateManifestUrl Tauri `latest.json` URL (for the desktop app, not rendered)
     */
    public function __construct(
        array $links = [],
        public ?string $latestVersion = null,
        public ?string $updateManifestUrl = null,
    ) {
        $kept = [];
        foreach (self::LINKS as $key) {
            $url = $links[$key] ?? null;
            if (is_string($url) && self::isSafeUrl($url)) {
                $kept[$key] = $url;
            }
        }
        $this->links = $kept;
    }

    /**
     * Valid links by key, in display order.
     *
     * @return array<string, string>
     */
    public function links(): array
    {
        return $this->links;
    }

    /**
     * Whether at least one link is set.
     */
    public function hasLinks(): bool
    {
        return $this->links !== [];
    }

    /**
     * Whether the URL is an absolute http(s) URL without whitespace or control characters.
     */
    public static function isSafeUrl(string $url): bool
    {
        return preg_match('#^https?://[^\s\x00-\x1f"<>]+\z#i', $url) === 1;
    }
}
