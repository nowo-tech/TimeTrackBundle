<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

/**
 * Since 1.29 (REQ-API-001): the unversioned and the v1 paths share the rate limit buckets (per token and per IP are
 * keyed by token and client IP, never by path), so switching path never resets a limit.
 */
final class ClientApiV1RateLimitTest extends FunctionalTestCase
{
    protected static function timeTrackConfig(): array
    {
        return ['client_api' => ['rate_limit' => ['per_token' => 3]]];
    }

    public function testThePerTokenBucketIsSharedByBothPaths(): void
    {
        $token = $this->login();

        $this->api('GET', '/api/time-track/me', null, $token);
        self::assertResponseIsSuccessful();
        $this->api('GET', '/api/time-track/v1/me', null, $token);
        self::assertResponseIsSuccessful();
        $this->api('GET', '/api/time-track/timer', null, $token);
        self::assertResponseIsSuccessful();

        $body = $this->api('GET', '/api/time-track/v1/timer', null, $token);
        self::assertResponseStatusCodeSame(429);
        self::assertSame('rate_limited', $body['error'] ?? null);
        $this->api('GET', '/api/time-track/me', null, $token);
        self::assertResponseStatusCodeSame(429);
    }
}
