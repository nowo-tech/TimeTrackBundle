<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\ClientApi;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\Client\ClientAuthenticatorInterface;
use Nowo\TimeTrackBundle\Client\ClientAuthResult;
use Nowo\TimeTrackBundle\Client\ClientLoginRateLimiter;
use Nowo\TimeTrackBundle\Client\OpaqueClientCredentialIssuer;
use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Exception\DeviceFlowCapacityExceededException;
use Nowo\TimeTrackBundle\Integration\TaskProviderInterface;
use Nowo\TimeTrackBundle\Service\DeviceAuthorizationService;
use Nowo\TimeTrackBundle\Support\IpPrefix;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\ClientApiStack;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Regression tests of the 1.9 security audit (Appendix CL): scopes fail closed (CL-01), per-IP limits key IPv6 by /64,
 * device requests are capped and logins are throttled per username (CL-02).
 */
final class SecurityAuditClTest extends TestCase
{
    /** CL-01: a host credential restricted to a misspelled scope is refused, never widened to every scope. */
    public function testClientAuthResultRejectsUnknownScope(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ClientAuthResult::success(new TestUser('u1', 'a@example.com'), 'cred-1', ['timer.read']);
    }

    /** CL-01: an empty scope list is refused (omit the list to grant every scope). */
    public function testClientAuthResultRejectsEmptyScopeList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ClientAuthResult::success(new TestUser('u1', 'a@example.com'), 'cred-1', []);
    }

    /** CL-01: only an absent list grants every scope; a known list is kept as is. */
    public function testClientAuthResultScopes(): void
    {
        $user = new TestUser('u1', 'a@example.com');

        self::assertSame(ClientScope::all(), ClientAuthResult::success($user)->getScopes());
        self::assertSame([ClientScope::TimerRead], ClientAuthResult::success($user, 'c', ['timer:read', ClientScope::TimerRead])->getScopes());
        self::assertSame([], ClientAuthResult::failure()->getScopes());
    }

    /** CL-01: a stored list that holds no known scope grants none (a 2.x-only scope on a 1.9 server). */
    public function testNormalizeNeverWidensToEveryScope(): void
    {
        self::assertSame([], ClientScope::normalize(['entries:write']));
        self::assertSame([], ClientScope::normalize([]));
        self::assertSame(ClientScope::all(), ClientScope::normalize(null));
        self::assertSame([ClientScope::TimerWrite], ClientScope::normalize(['timer:write', 'entries:write', 42]));
    }

    /** CL-01: fromRequested() rejects non-string values too. */
    public function testFromRequestedRejectsNonStringValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"int"');
        ClientScope::fromRequested([42]);
    }

    /** CL-01: a token issued before 1.9 (no stored scopes) keeps every scope; an unknown-only token has none. */
    public function testTokenScopes(): void
    {
        $user    = new TestUser('u1', 'a@example.com');
        $expires = new DateTimeImmutable('+1 hour');

        self::assertSame(ClientScope::all(), (new ClientToken('h1', $expires, $user, ClientType::Desktop))->getScopes());

        $unknown = new ClientToken('h2', $expires, $user, ClientType::Desktop, null, 'cred', ['entries:write']);
        self::assertSame([], $unknown->getScopes());
        self::assertFalse($unknown->hasScope(ClientScope::TimerRead));
    }

    /** CL-01: issueToken() grants every scope only for null. */
    public function testIssueTokenScopes(): void
    {
        $stack = new ClientApiStack($this->createStub(ClientAuthenticatorInterface::class), $this->createStub(TaskProviderInterface::class));
        $user  = new TestUser('u1', 'a@example.com');

        self::assertSame(ClientScope::values(ClientScope::all()), $stack->authService->issueToken($user, ClientType::Desktop)['scopes']);
        self::assertSame([], $stack->authService->issueToken($user, ClientType::Desktop, 'c', [])['scopes']);
    }

    /** CL-02: IPv6 limits key on the /64, IPv4 on the address. */
    public function testRateLimitKey(): void
    {
        self::assertSame('2001:db8:1:2::/64', IpPrefix::rateLimitKey('2001:db8:1:2::1'));
        self::assertSame(IpPrefix::rateLimitKey('2001:db8:1:2::1'), IpPrefix::rateLimitKey('2001:db8:1:2:ffff::28'));
        self::assertSame('203.0.113.7', IpPrefix::rateLimitKey('203.0.113.7'));
        self::assertSame('', IpPrefix::rateLimitKey(null));
        self::assertSame('garbage', IpPrefix::rateLimitKey('garbage'));
    }

    /** CL-02: rotating addresses inside one /64 does not reset the login buckets. */
    public function testLoginLimiterKeysIpv6ByPrefix(): void
    {
        $limiter = new ClientLoginRateLimiter(new ArrayAdapter(), 2, 900, true, 100, 100);
        $limiter->registerFailedAttempt('2001:db8:1:2::1', 'alice');
        $limiter->registerFailedAttempt('2001:db8:1:2::2', 'alice');

        self::assertTrue($limiter->isLimited('2001:db8:1:2::3', 'alice'));
        self::assertFalse($limiter->isLimited('2001:db8:9::1', 'alice'));
    }

    /** CL-02: a username is throttled whatever the client address; a successful login clears that bucket. */
    public function testLoginLimiterPerUsername(): void
    {
        $limiter = new ClientLoginRateLimiter(new ArrayAdapter(), 10, 900, true, null, 3);
        foreach (['198.51.100.1', '198.51.100.2', '2001:db8:5::1'] as $ip) {
            $limiter->registerFailedAttempt($ip, 'Alice ');
        }

        self::assertTrue($limiter->isLimited('203.0.113.9', 'alice'));
        self::assertFalse($limiter->isLimited('203.0.113.9', 'bob'));

        $limiter->reset('203.0.113.9', 'alice');
        self::assertFalse($limiter->isLimited('203.0.113.9', 'alice'));
    }

    /** CL-02: device requests are capped per IP prefix (IPv6 /64), for the whole code TTL. */
    public function testDeviceRequestsCappedPerPrefix(): void
    {
        $stack   = new ClientApiStack($this->createStub(ClientAuthenticatorInterface::class), $this->createStub(TaskProviderInterface::class));
        $service = $this->service($stack, 2, 0);
        $service->start(ClientType::Desktop, 'a', null, '2001:db8:1:2::1');
        $service->start(ClientType::Desktop, 'b', null, '2001:db8:1:2::2');
        $service->start(ClientType::Desktop, 'c', null, '2001:db8:7::1');

        try {
            $service->start(ClientType::Desktop, 'd', null, '2001:db8:1:2::3');
            self::fail('The third request of the /64 must be refused.');
        } catch (DeviceFlowCapacityExceededException $exception) {
            self::assertGreaterThan(0, $exception->retryAfter);
        }

        self::assertSame('2001:db8:1:2::/64', $stack->devices->all()[0]->getIpPrefix());
    }

    /** CL-02: the total of unexpired requests is capped; expired rows are purged before refusing. */
    public function testDeviceRequestsCappedInTotal(): void
    {
        $stack   = new ClientApiStack($this->createStub(ClientAuthenticatorInterface::class), $this->createStub(TaskProviderInterface::class));
        $service = $this->service($stack, 0, 2);
        $service->start(ClientType::Desktop, 'a', null, '198.51.100.1');
        $service->start(ClientType::Desktop, 'b', null, '203.0.113.1');

        try {
            $service->start(ClientType::Desktop, 'c', null, '192.0.2.1');
            self::fail('The global cap must refuse the request.');
        } catch (DeviceFlowCapacityExceededException $exception) {
            self::assertSame(DeviceAuthorizationService::CAPACITY_RETRY_AFTER, $exception->retryAfter);
        }

        $stack->clock->modify('+11 minutes');
        $service->start(ClientType::Desktop, 'c', null, '192.0.2.1');
        self::assertCount(1, $stack->devices->all());
    }

    private function service(ClientApiStack $stack, int $perPrefix, int $total): DeviceAuthorizationService
    {
        return new DeviceAuthorizationService($stack->devices, new OpaqueClientCredentialIssuer(), $stack->authService, $stack->auditor, $stack->rateLimiter, 600, 5, 3, 900, $stack->clock, $perPrefix, $total);
    }
}
