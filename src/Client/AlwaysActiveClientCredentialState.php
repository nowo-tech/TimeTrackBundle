<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Nowo\TimeTrackBundle\Entity\ClientToken;

/**
 * Default credential state (1.x behaviour): a token is valid until it expires or is revoked.
 */
final readonly class AlwaysActiveClientCredentialState implements ClientCredentialStateInterface
{
    /**
     * Always true.
     */
    public function isActive(ClientToken $token): bool
    {
        return true;
    }
}
