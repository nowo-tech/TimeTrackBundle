<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/**
 * Since 1.23.0: framework-free twin of {@see ClientCredentialIssuerInterface} (REQ-ARCH-001 rule 3), same
 * `client_api.credential_issuer` option. Creates the host credential of a device approved through the device flow.
 */
interface ClientCredentialMinterInterface
{
    /**
     * Creates a credential for the device and returns its id (stored on the session tokens, max 191 characters).
     *
     * @param TimeTrackUserRef $user User who approved the device
     * @param ClientType $clientType Client type the device declared, for the host's list
     * @param string $clientName Name the device sent (already trimmed and length-capped by the bundle)
     * @param list<ClientScope> $scopes Scopes the user approved
     */
    public function mintForDevice(TimeTrackUserRef $user, ClientType $clientType, string $clientName, array $scopes): string;
}
