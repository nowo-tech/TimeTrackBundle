<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port\Internal;

use LogicException;
use Nowo\TimeTrackBundle\Client\ClientCredentialMinterInterface;
use Nowo\TimeTrackBundle\Client\ClientTokenRevoker;
use Nowo\TimeTrackBundle\Client\DeviceCredentialRequest;
use Nowo\TimeTrackBundle\Client\OpaqueClientCredentialIssuer;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Creates the host credential of a device approved through the device flow (contract v0.2 §2), so the device shows in
 * the same user-visible list as the other credentials (e.g. Beacon's app passwords) and is revoked the same way
 * (the host then calls {@see ClientTokenRevoker::revokeByCredential()}). Default: {@see OpaqueClientCredentialIssuer}.
 *
 * @internal since 2.0 (REQ-ARCH-001 rule 3): the shape the bundle asks this port through; the 1.x public interface
 * `Nowo\TimeTrackBundle\Client\ClientCredentialIssuerInterface` (deprecated since 1.23.0) is removed in 2.0. Hosts implement {@see ClientCredentialMinterInterface} (a gate adapts it).
 */
interface ClientCredentialIssuerInterface
{
    /**
     * Creates a credential for the device and returns its id (stored on the session tokens, max 191 characters).
     *
     * @param UserInterface $user User who approved the device
     * @param ClientType $clientType Client type the device declared (e.g. desktop app or browser extension), for the host's list
     * @param string $clientName Name the device sent (already trimmed and length-capped by the bundle)
     * @param list<ClientScope> $scopes Scopes the user approved
     */
    public function issueForDevice(UserInterface $user, ClientType $clientType, string $clientName, array $scopes): string;

    /**
     * Since 2.2.0: creates the credential of an approved request of any scope family and returns its id.
     *
     * @throws LogicException When the host minter does not know scope families and the family is not `time`
     */
    public function issueForFamily(UserInterface $user, DeviceCredentialRequest $request): string;
}
