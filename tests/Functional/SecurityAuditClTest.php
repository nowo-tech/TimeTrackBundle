<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Symfony\Component\DomCrawler\Crawler;

use function bin2hex;
use function dechex;
use function random_bytes;

/**
 * Kernel regression tests of the 1.9 security audit (Appendix CL): device scopes fail closed (CL-01), per-IP limits
 * key IPv6 by /64 (CL-02), the approval page cannot be framed (CL-03) and shows the request time and network (CL-04).
 */
final class SecurityAuditClTest extends FunctionalTestCase
{
    protected static function timeTrackConfig(): array
    {
        return ['client_api' => ['device_flow' => ['enabled' => true]]];
    }

    /** CL-01: a device asking only for an unknown scope gets 400 invalid_scope, not an every-scope token. */
    public function testDeviceUnknownScopeIsRejected(): void
    {
        foreach ([['entries:write'], [], 'timer:read', ['timer:read', 'timer.write']] as $scopes) {
            $body = $this->api('POST', '/api/time-track/device/code', ['clientType' => 'desktop', 'clientName' => 'x', 'scopes' => $scopes]);
            self::assertResponseStatusCodeSame(400);
            self::assertSame('invalid_scope', $body['error'] ?? null);
        }
    }

    /** CL-01: a known scope list is kept through approval and exchange; no list means every scope. */
    public function testDeviceScopesAreKept(): void
    {
        $grant = $this->api('POST', '/api/time-track/device/code', ['clientType' => 'desktop', 'clientName' => 'x', 'scopes' => ['timer:read']]);
        $this->approve($this->userCode($grant));
        $token = $this->api('POST', '/api/time-track/device/token', ['deviceCode' => $this->deviceCode($grant)]);
        self::assertSame(['timer:read'], $token['scopes'] ?? null);

        $grant = $this->api('POST', '/api/time-track/device/code', ['clientType' => 'desktop', 'clientName' => 'y']);
        $this->approve($this->userCode($grant));
        $token = $this->api('POST', '/api/time-track/device/token', ['deviceCode' => $this->deviceCode($grant)]);
        self::assertSame(['timer:read', 'timer:write', 'tasks:read', 'entries:read'], $token['scopes'] ?? null);
    }

    /** CL-02: the device per-IP limit (30/min) counts a whole IPv6 /64, whatever address of it is used. */
    public function testIpv6RotationWithinOne64IsLimited(): void
    {
        for ($i = 1; $i <= 30; ++$i) {
            $this->api('POST', '/api/time-track/device/token', ['deviceCode' => 'unknown'], null, ['REMOTE_ADDR' => '2001:db8:1:2::' . dechex($i)]);
            self::assertResponseStatusCodeSame(400, 'request ' . $i);
        }

        $this->api('POST', '/api/time-track/device/token', ['deviceCode' => 'unknown'], null, ['REMOTE_ADDR' => '2001:db8:1:2:ffff::1']);
        self::assertResponseStatusCodeSame(429);

        $this->api('POST', '/api/time-track/device/token', ['deviceCode' => 'unknown'], null, ['REMOTE_ADDR' => '2001:db8:1:3::1']);
        self::assertResponseStatusCodeSame(400);
    }

    /** CL-02: device/code stops creating rows after max_pending_per_prefix (20) requests from one /64. */
    public function testDeviceCodesCappedPerPrefix(): void
    {
        for ($i = 1; $i <= 20; ++$i) {
            $this->api('POST', '/api/time-track/device/code', ['clientType' => 'desktop'], null, ['REMOTE_ADDR' => '2001:db8:1:2::' . dechex($i)]);
            self::assertResponseStatusCodeSame(200, 'request ' . $i);
        }

        $body = $this->api('POST', '/api/time-track/device/code', ['clientType' => 'desktop'], null, ['REMOTE_ADDR' => '2001:db8:1:2::ff']);
        self::assertResponseStatusCodeSame(429);
        self::assertSame('rate_limited', $body['error'] ?? null);
        self::assertResponseHasHeader('Retry-After');
    }

    /** CL-02: rotating client addresses does not reset the per-username login budget. */
    public function testLoginThrottledPerUsernameAcrossAddresses(): void
    {
        $wrong = bin2hex(random_bytes(8));
        for ($i = 1; $i <= 10; ++$i) {
            $this->api('POST', '/api/time-track/login', ['username' => 'person@example.com', 'password' => $wrong], null, ['REMOTE_ADDR' => '198.51.100.' . $i]);
            self::assertResponseStatusCodeSame(401, 'attempt ' . $i);
        }

        $this->api('POST', '/api/time-track/login', ['username' => 'person@example.com', 'password' => $wrong], null, ['REMOTE_ADDR' => '203.0.113.50']);
        self::assertResponseStatusCodeSame(429);
    }

    /** CL-03: every answer of the approval page forbids framing and caching. */
    public function testDevicePageCannotBeFramed(): void
    {
        $grant = $this->api('POST', '/api/time-track/device/code', ['clientType' => 'desktop', 'clientName' => 'x']);
        $this->client->loginUser($this->user, 'main');

        $crawler = $this->client->request('GET', '/tools/time-track/device?code=' . $this->userCode($grant));
        $this->assertNotFramable();
        $crawler = $this->client->submit($crawler->filter('form')->form());
        $this->assertNotFramable();
        $this->client->submit($crawler->filter('[data-testid="time-track-device-approve"]')->form());
        $this->assertNotFramable();
    }

    /** CL-04: the confirm page shows when and from which network the request was started. */
    public function testConfirmPageShowsRequestTimeAndNetwork(): void
    {
        $grant = $this->api('POST', '/api/time-track/device/code', ['clientType' => 'desktop', 'clientName' => 'x'], null, ['REMOTE_ADDR' => '2001:db8:aa:bb::5']);
        $this->approve($this->userCode($grant), false);

        self::assertSelectorTextContains('[data-testid="time-track-device-confirm"]', '2001:db8:aa:bb::/64');
        self::assertSelectorTextContains('[data-testid="time-track-device-confirm"]', 'Requested at');
    }

    private function assertNotFramable(): void
    {
        $headers = $this->client->getResponse()->headers;
        self::assertSame('DENY', $headers->get('X-Frame-Options'));
        self::assertSame("frame-ancestors 'none'", $headers->get('Content-Security-Policy'));
        self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
    }

    private function approve(string $userCode, bool $click = true): Crawler
    {
        $this->client->loginUser($this->user, 'main');
        $crawler = $this->client->request('GET', '/tools/time-track/device?code=' . $userCode);
        $crawler = $this->client->submit($crawler->filter('form')->form());
        if ($click) {
            return $this->client->submit($crawler->filter('[data-testid="time-track-device-approve"]')->form());
        }

        return $crawler;
    }

    /**
     * @param array<mixed>|null $grant
     */
    private function userCode(?array $grant): string
    {
        self::assertIsString($grant['userCode'] ?? null);

        return $grant['userCode'];
    }

    /**
     * @param array<mixed>|null $grant
     */
    private function deviceCode(?array $grant): string
    {
        self::assertIsString($grant['deviceCode'] ?? null);

        return $grant['deviceCode'];
    }
}
