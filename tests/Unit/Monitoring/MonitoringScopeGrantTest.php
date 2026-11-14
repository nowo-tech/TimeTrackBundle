<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Monitoring;

use Nowo\TimeTrackBundle\Client\ClientAuthenticatorInterface;
use Nowo\TimeTrackBundle\Client\ClientAuthResult;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Integration\TaskProviderInterface;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\ClientApiStack;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

use function json_decode;
use function json_encode;

/**
 * Since 1.26.0 (Beacon Q5): a password login (app password) never grants `monitoring:write`, whatever the host
 * authenticator returns; only the device flow (explicit approval on the consent page) can.
 */
final class MonitoringScopeGrantTest extends TestCase
{
    public function testAPasswordNeverGrantsMonitoringWrite(): void
    {
        $authenticator = $this->createStub(ClientAuthenticatorInterface::class);
        $authenticator->method('authenticate')->willReturn(ClientAuthResult::success(new TestUser('u1', 'a@example.com'), 'cred-1', [ClientScope::TimerRead, ClientScope::MonitoringWrite]));
        $stack = new ClientApiStack($authenticator, $this->createStub(TaskProviderInterface::class));

        self::assertSame(['timer:read'], $stack->authService->login('a@example.com', 'x', ClientType::Desktop)['scopes'] ?? null);
        $response = $stack->apiController()->login(Request::create('/api/time-track/login', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['username' => 'a@example.com', 'password' => 'x', 'clientType' => 'desktop'])));
        self::assertSame(['timer:read'], json_decode((string) $response->getContent(), true)['scopes'] ?? null);
        self::assertSame([ClientScope::TimerRead], ClientScope::grantableByPassword([ClientScope::TimerRead, ClientScope::MonitoringWrite]));
        self::assertSame(['monitoring:write'], $stack->authService->issueToken(new TestUser('u1', 'a@example.com'), ClientType::Desktop, null, [ClientScope::MonitoringWrite])['scopes'], 'the device flow still can');
    }
}
