<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Client\ClientCredentialIssuerInterface;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Port\ClientCredentialMinterGate;
use Nowo\TimeTrackBundle\Port\TaskAccessPolicyGate;
use Nowo\TimeTrackBundle\Security\TaskAccessCheckerInterface;
use Nowo\TimeTrackBundle\Tests\Support\Ports\TwinClientPorts;

use function array_filter;
use function array_values;
use function str_starts_with;

/**
 * 1.23.0 (REQ-ARCH-001 rule 3): a host that configures only the framework-free `TaskAccessPolicyInterface` and
 * `ClientCredentialMinterInterface` (`client_api.task_access_checker`, `client_api.credential_issuer`) is asked through
 * the bundle's gates on the real client API paths.
 */
final class TwinClientPortsTest extends FunctionalTestCase
{
    protected static function timeTrackConfig(): array
    {
        return ['client_api' => ['task_access_checker' => TwinClientPorts::class, 'credential_issuer' => TwinClientPorts::class]];
    }

    public function testTheTaskAccessPolicyDecidesTimerStarts(): void
    {
        self::assertInstanceOf(TaskAccessPolicyGate::class, self::getContainer()->get(TaskAccessCheckerInterface::class));
        $token = $this->login();

        $this->api('POST', '/api/time-track/timer/start', ['taskId' => 'task-forbidden'], $token);
        self::assertResponseStatusCodeSame(403);
        $this->api('POST', '/api/time-track/timer/start', ['taskId' => 'task-1'], $token);
        self::assertResponseStatusCodeSame(201);

        $id = (string) $this->user->getId();
        self::assertSame(['mayTrack:' . $id . ':task-forbidden', 'mayTrack:' . $id . ':task-1'], array_values(array_filter($this->twin()->asked, static fn (string $call): bool => str_starts_with($call, 'mayTrack:'))));
    }

    public function testTheMinterIssuesTheDeviceCredential(): void
    {
        $issuer = self::getContainer()->get(ClientCredentialIssuerInterface::class);
        self::assertInstanceOf(ClientCredentialMinterGate::class, $issuer);

        self::assertSame('twin-' . $this->user->getId() . '-desktop', $issuer->issueForDevice($this->user, ClientType::Desktop, 'Laptop', [ClientScope::TimerRead]));
        self::assertSame(['mint:' . $this->user->getId() . ':Laptop'], $this->twin()->asked);
    }

    private function twin(): TwinClientPorts
    {
        $twin = self::getContainer()->get(TwinClientPorts::class);
        self::assertInstanceOf(TwinClientPorts::class, $twin);

        return $twin;
    }
}
