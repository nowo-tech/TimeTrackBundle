<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support\Ports;

use Nowo\TimeTrackBundle\Client\ClientCredentialMinterInterface;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Security\TaskAccessPolicyInterface;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/**
 * 1.23.0: a host implementing only the framework-free client API twins (task access policy, credential minter). It
 * allows every task except `task-forbidden` and mints `twin-<id>-<client type>`, recording what it was asked.
 */
final class TwinClientPorts implements TaskAccessPolicyInterface, ClientCredentialMinterInterface
{
    /** @var list<string> */
    public array $asked = [];

    public function mayTrack(TimeTrackUserRef $user, string $taskId): bool
    {
        TwinPorts::user($user);
        $this->asked[] = 'mayTrack:' . $user->id . ':' . $taskId;

        return $taskId !== 'task-forbidden';
    }

    public function mintForDevice(TimeTrackUserRef $user, ClientType $clientType, string $clientName, array $scopes): string
    {
        TwinPorts::user($user);
        $this->asked[] = 'mint:' . $user->id . ':' . $clientName;

        return 'twin-' . $user->id . '-' . $clientType->value;
    }
}
