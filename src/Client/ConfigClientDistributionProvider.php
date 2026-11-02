<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

/**
 * Links from the bundle configuration (`client_distribution.extension.*`, `client_distribution.desktop.*`).
 */
final readonly class ConfigClientDistributionProvider implements ClientDistributionProviderInterface
{
    private ClientDistribution $distribution;

    /**
     * @param array<string, string|null> $extension `chrome`, `edge`, `firefox` store links
     * @param array<string, string|null> $desktop `windows`, `macos`, `linux` installer links, `latest_version`, `update_manifest_url`
     */
    public function __construct(array $extension = [], array $desktop = [])
    {
        $this->distribution = new ClientDistribution(
            [...$extension, ...$desktop],
            $desktop['latest_version'] ?? null,
            $desktop['update_manifest_url'] ?? null,
        );
    }

    /**
     * The configured links.
     */
    public function getDistribution(): ClientDistribution
    {
        return $this->distribution;
    }
}
