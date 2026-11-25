<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port;

use Nowo\TimeTrackBundle\Client\ClientCredentialMinterInterface;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Port\Internal\ClientCredentialIssuerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Asks a host {@see ClientCredentialMinterInterface} (1.23.0) where the bundle asks its internal shape
 * {@see ClientCredentialIssuerInterface} (the 1.x interface of that name was removed in 2.0). Exceptions propagate. Built by {@see Ports}; stateless.
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
