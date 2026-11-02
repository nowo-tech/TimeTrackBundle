<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Nowo\TimeTrackBundle\Enum\ClientScope;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Creates the host credential of a device approved through the device flow (contract v0.2 §2), so the device shows in
 * the same user-visible list as the other credentials (e.g. Beacon's app passwords) and is revoked the same way
 * (the host then calls {@see ClientTokenRevoker::revokeByCredential()}). Default: {@see OpaqueClientCredentialIssuer}.
 */
interface ClientCredentialIssuerInterface
{
    /**
     * Creates a credential for the device and returns its id (stored on the session tokens, max 191 characters).
     *
     * @param UserInterface $user User who approved the device
     * @param string $clientName Name the device sent (already trimmed and length-capped by the bundle)
     * @param list<ClientScope> $scopes Scopes the user approved
     */
    public function issueForDevice(UserInterface $user, string $clientName, array $scopes): string;
}
