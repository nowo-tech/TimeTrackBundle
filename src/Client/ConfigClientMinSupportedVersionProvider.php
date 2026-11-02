<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Nowo\TimeTrackBundle\Enum\ClientType;

/**
 * Minimum supported client versions from the bundle configuration
 * (`client_distribution.min_supported_version.extension|desktop|web`, semver validated at compile time).
 */
final readonly class ConfigClientMinSupportedVersionProvider implements ClientMinSupportedVersionProviderInterface
{
    /**
     * @param array<string, string|null> $versions Version per client type value (`extension`, `desktop`, `web`)
     */
    public function __construct(private array $versions = [])
    {
    }

    /**
     * The configured version for the client type, or null.
     */
    public function getMinSupportedVersion(ClientType $clientType): ?string
    {
        return $this->versions[$clientType->value] ?? null;
    }
}
