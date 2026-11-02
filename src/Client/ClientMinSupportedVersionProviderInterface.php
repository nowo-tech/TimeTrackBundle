<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Nowo\TimeTrackBundle\Enum\ClientType;

/**
 * Oldest client version the host still supports, per client type (`GET me` → `minSupportedVersion`). Default:
 * {@see ConfigClientMinSupportedVersionProvider} (`client_distribution.min_supported_version.*`); a host may back it
 * with its release manifest.
 */
interface ClientMinSupportedVersionProviderInterface
{
    /**
     * Minimum supported semver for the client type, or null when the host sets none (the `me` key is then absent).
     */
    public function getMinSupportedVersion(ClientType $clientType): ?string;
}
