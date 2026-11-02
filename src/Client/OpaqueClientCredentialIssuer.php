<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Nowo\TimeTrackBundle\Enum\ClientType;
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
}
