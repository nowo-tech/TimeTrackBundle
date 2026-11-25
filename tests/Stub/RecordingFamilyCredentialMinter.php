<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Stub;

use Nowo\TimeTrackBundle\Client\ClientFamilyCredentialMinterInterface;
use Nowo\TimeTrackBundle\Client\DeviceCredentialRequest;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

use function count;

/**
 * Test family-aware minter (2.2.0): records every request and returns `cred-<n>`.
 */
final class RecordingFamilyCredentialMinter implements ClientFamilyCredentialMinterInterface
{
    /** @var list<DeviceCredentialRequest> */
    public array $requests = [];

    public function mintForFamily(DeviceCredentialRequest $request): string
    {
        $this->requests[] = $request;

        return 'cred-' . count($this->requests);
    }

    public function mintForDevice(TimeTrackUserRef $user, ClientType $clientType, string $clientName, array $scopes): string
    {
        return 'legacy-' . $clientName;
    }
}
