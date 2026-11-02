<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Symfony\Component\HttpFoundation\Request;

/**
 * Per-token and per-IP rate limits answer 429 rate_limited with Retry-After and stay stateless.
 */
final class ClientApiRateLimitTest extends FunctionalTestCase
{
    protected static function timeTrackConfig(): array
    {
        return ['client_api' => ['rate_limit' => ['per_token' => 2, 'per_ip' => 6, 'device_per_ip' => 1], 'device_flow' => ['enabled' => true, 'default_page' => false, 'verification_uri' => 'https://host.example/account/devices']]];
    }

    public function testPerTokenThenPerIpLimits(): void
    {
        $token = $this->login();

        $this->api('GET', '/api/time-track/timer', null, $token);
        $this->api('GET', '/api/time-track/timer', null, $token);
        $body = $this->api('GET', '/api/time-track/timer', null, $token);
        self::assertResponseStatusCodeSame(429);
        self::assertSame('rate_limited', $body['error'] ?? null);
        self::assertGreaterThan(0, (int) $this->client->getResponse()->headers->get('Retry-After'));
        $this->assertStateless();

        $this->api('GET', '/api/time-track/me', null, 'unknown');
        $this->api('POST', '/api/time-track/logout');
        $this->api('POST', '/api/time-track/login', ['username' => 'person@example.com', 'password' => 'x']);
        self::assertResponseStatusCodeSame(429);
        $this->api('POST', '/api/time-track/logout');
        self::assertResponseStatusCodeSame(429);
        $this->assertStateless();
    }

    public function testDeviceEndpointsHaveTheirOwnIpBudgetAndTheHostVerificationUri(): void
    {
        $grant = $this->api('POST', '/api/time-track/device/code', ['clientName' => 'Laptop']);
        self::assertResponseIsSuccessful();
        self::assertSame('https://host.example/account/devices', $grant['verificationUri'] ?? null);

        $this->api('POST', '/api/time-track/device/token', ['deviceCode' => 'x']);
        self::assertResponseStatusCodeSame(429);

        $this->client->request('GET', '/tools/time-track/device');
        self::assertResponseStatusCodeSame(404, 'default page disabled');
    }

    private function assertStateless(): void
    {
        self::assertResponseNotHasHeader('Set-Cookie');
        $request = $this->client->getRequest();
        self::assertInstanceOf(Request::class, $request);
        self::assertFalse($request->hasSession() && $request->getSession()->isStarted());
    }
}
