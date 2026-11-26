<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port;

use LogicException;
use Nowo\TimeTrackBundle\Client\ClientCredentialMinterInterface;
use Nowo\TimeTrackBundle\Client\ClientFamilyCredentialMinterInterface;
use Nowo\TimeTrackBundle\Client\DeviceCredentialRequest;
use Nowo\TimeTrackBundle\Client\DeviceScopeFamily;
use Nowo\TimeTrackBundle\Client\OrphanedCredentialRevokerInterface;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Port\Internal\ClientCredentialIssuerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function sprintf;

/**
 * Asks a host {@see ClientCredentialMinterInterface} (1.23.0) where the bundle asks its internal shape
 * {@see ClientCredentialIssuerInterface} (the 1.x interface of that name was removed in 2.0). Exceptions propagate. Built by {@see Ports}; stateless.
 *
 * @internal
 */
final readonly class ClientCredentialMinterGate implements ClientCredentialIssuerInterface, OrphanedCredentialRevokerInterface
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

    /**
     * Since 2.2.0: {@see ClientFamilyCredentialMinterInterface::mintForFamily()} when the minter implements it; a plain
     * minter gets {@see ClientCredentialMinterInterface::mintForDevice()} for the `time` family only.
     *
     * @throws LogicException For another family with a minter that does not know scope families
     */
    public function issueForFamily(UserInterface $user, DeviceCredentialRequest $request): string
    {
        if ($this->minter instanceof ClientFamilyCredentialMinterInterface) {
            return $this->minter->mintForFamily($request);
        }
        if ($request->scopeFamily !== DeviceScopeFamily::TIME) {
            throw new LogicException(sprintf('nowo_time_track: the device request is for the "%s" scope family, but the credential minter "%s" does not implement %s.', $request->scopeFamily, $this->minter::class, ClientFamilyCredentialMinterInterface::class));
        }

        return $this->minter->mintForDevice($request->user, $request->clientType, $request->clientName, ClientScope::normalize($request->scopes));
    }

    /**
     * Since 2.2.1 (audit ZBV-v-I2): forwards to the minter when it implements {@see OrphanedCredentialRevokerInterface};
     * nothing otherwise (the host revokes the credential itself).
     */
    public function revokeOrphanedCredential(string $credentialId, string $scopeFamily): void
    {
        if ($this->minter instanceof OrphanedCredentialRevokerInterface) {
            $this->minter->revokeOrphanedCredential($credentialId, $scopeFamily);
        }
    }
}
