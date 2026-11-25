<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Port\Internal\ClientCredentialIssuerInterface;
use Nowo\TimeTrackBundle\ValueObject\Uuid;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Default device credential issuer for hosts without a credential list: an opaque id `device:<uuid>`. The device's
 * tokens can still be revoked with {@see ClientTokenRevoker::revokeByCredential()} or expire after `token_ttl`.
 */
final readonly class OpaqueClientCredentialIssuer implements ClientCredentialIssuerInterface
{
    /**
     * Returns a new opaque credential id.
     */
    public function issueForDevice(UserInterface $user, ClientType $clientType, string $clientName, array $scopes): string
    {
        return 'device:' . Uuid::generate()->toString();
    }

    /**
     * Since 2.2.0: returns a new opaque credential id for a request of any scope family.
     */
    public function issueForFamily(UserInterface $user, DeviceCredentialRequest $request): string
    {
        return 'device:' . Uuid::generate()->toString();
    }
}
