<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Tests\Support\FunctionalKernel;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Since 1.29 (audit SO, probe P1b): the same host firewall that misses v1, plus `access_control ^/ ROLE_USER`. A v1
 * call with a valid token is blocked by the host (fails closed), never served unauthenticated: the host must extend its
 * API firewall pattern to v1 (UPGRADING "Hosts must check").
 */
final class ClientApiV1HostFirewallRequiresUserTest extends FunctionalTestCase
{
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new FunctionalKernel(self::timeTrackConfig(), securityConfig: [
            'firewalls'      => ['time_track_api' => ['pattern' => '^/api/time-track/(?!v1)']],
            'access_control' => [['path' => '^/', 'roles' => 'ROLE_USER']],
        ]);
    }

    public function testV1IsBlockedNotOpened(): void
    {
        $token = $this->login();
        $this->api('GET', '/api/time-track/me', null, $token);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/time-track/v1/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(401);
        // The host firewall answered (the bundle controller never ran).
        self::assertStringStartsWith('<!-- Full authentication is required', (string) $this->client->getResponse()->getContent());
    }
}
