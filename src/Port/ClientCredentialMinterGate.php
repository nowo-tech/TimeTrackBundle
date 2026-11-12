<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port;

use Nowo\TimeTrackBundle\Client\ClientCredentialIssuerInterface;
use Nowo\TimeTrackBundle\Client\ClientCredentialMinterInterface;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Asks a host {@see ClientCredentialMinterInterface} (1.23.0) where the bundle asks the deprecated
 * {@see ClientCredentialIssuerInterface}. Exceptions propagate. Built by {@see Ports}; stateless.
 *
 * @internal
 */
final readonly class ClientCredentialMinterGate implements ClientCredentialIssuerInterface
{
    /**
     * Wraps the host minter.
     */
    public function __construct(private ClientCredentialMinterInterface $minter)
    {
    }

    /**
     * The minter's credential id for the user's reference.
     */
    public function issueForDevice(UserInterface $user, ClientType $clientType, string $clientName, array $scopes): string
    {
        return $this->minter->mintForDevice(TimeTrackUserRefs::fromUser($user), $clientType, $clientName, $scopes);
    }
}
