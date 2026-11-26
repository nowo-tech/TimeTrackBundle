<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Stub;

use Nowo\TimeTrackBundle\Client\ClientFamilyCredentialMinterInterface;
use Nowo\TimeTrackBundle\Client\DeviceCredentialRequest;
use Nowo\TimeTrackBundle\Client\OrphanedCredentialRevokerInterface;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use RuntimeException;

use function count;

/**
 * Since 2.2.1 (audit ZBV-v-I2): a family minter that also revokes orphaned credentials (or fails to).
 */
final class RevokingFamilyCredentialMinter implements ClientFamilyCredentialMinterInterface, OrphanedCredentialRevokerInterface
{
    /** @var list<array{0: string, 1: string}> */
    public array $revoked = [];

    public bool $fail = false;

    private int $minted = 0;

    public function mintForFamily(DeviceCredentialRequest $request): string
    {
        return 'cred-' . ++$this->minted;
    }

    public function mintForDevice(TimeTrackUserRef $user, ClientType $clientType, string $clientName, array $scopes): string
    {
        return 'legacy-' . count($scopes);
    }

    public function revokeOrphanedCredential(string $credentialId, string $scopeFamily): void
    {
        if ($this->fail) {
            throw new RuntimeException('host store down');
        }
        $this->revoked[] = [$credentialId, $scopeFamily];
    }
}
