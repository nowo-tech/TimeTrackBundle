<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use LogicException;
use Nowo\TimeTrackBundle\DependencyInjection\Compiler\ScopeFamilyMinterPass;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Port\Internal\ClientCredentialIssuerInterface;
use Nowo\TimeTrackBundle\ValueObject\Uuid;
use Symfony\Component\Security\Core\User\UserInterface;

use function sprintf;

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
     * Since 2.2.0: a new opaque credential id for a `time` request. Since audit ZBV-I1 it refuses every other family:
     * a host that registers a family configures a {@see ClientFamilyCredentialMinterInterface} (the build fails
     * otherwise, {@see ScopeFamilyMinterPass}).
     *
     * @throws LogicException For a family other than `time`
     */
    public function issueForFamily(UserInterface $user, DeviceCredentialRequest $request): string
    {
        if ($request->scopeFamily !== DeviceScopeFamily::TIME) {
            throw new LogicException(sprintf('nowo_time_track: the default opaque credential issuer mints time credentials only; configure a %s for the "%s" family.', ClientFamilyCredentialMinterInterface::class, $request->scopeFamily));
        }

        return 'device:' . Uuid::generate()->toString();
    }
}
