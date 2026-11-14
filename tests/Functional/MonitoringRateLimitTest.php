<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Tests\Stub\MutableProfileProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubMonitoringEvidenceStore;
use Nowo\TimeTrackBundle\Tests\Stub\StubMonitoringPolicySource;
use Nowo\TimeTrackBundle\Tests\Support\MonitoringFixtures;

use function gmdate;
use function time;

/**
 * Since 1.26.0 (contract §24.3): per-token limits of the monitoring routes in 10-minute windows (defaults 6 intents,
 * 6 uploads, 30 activity batches; here lowered), 429 with an integer `Retry-After` and `retryAfter` in the body.
 */
class MonitoringRateLimitTest extends MonitoringTestCase
{
    protected static function timeTrackConfig(): array
    {
        return ['client_api' => [
            'profile_provider' => MutableProfileProvider::class,
            'monitoring'       => [
                'policy_source'  => StubMonitoringPolicySource::class,
                'evidence_store' => StubMonitoringEvidenceStore::class,
                'rate_limit'     => ['intents_per_10_min' => 2, 'uploads_per_10_min' => 1, 'activity_per_10_min' => 1],
            ],
        ]];
    }

    public function testEveryBucketAnswers429WithRetryAfter(): void
    {
        $token = $this->token();
        $this->acknowledge($token);
        $timer = $this->startTimer($token);
        $image = MonitoringFixtures::webp(1280, 720);

        $grant = $this->intent($token, $this->intentBody($timer, $image));
        self::assertResponseStatusCodeSame(201);
        $this->intent($token, $this->intentBody($timer, $image));
        self::assertResponseStatusCodeSame(200);
        $this->assertLimited($this->intent($token, $this->intentBody($timer, $image)));

        $this->put(self::url($grant), $token, MonitoringFixtures::webp(1, 1));
        self::assertResponseStatusCodeSame(422);
        $this->assertLimited($this->put(self::url($grant), $token, $image));

        $slot = gmdate('Y-m-d\TH:i:s\Z', time() - time() % 600);
        $item = ['items' => [['entryId' => $timer, 'slot' => $slot, 'kind' => 'level', 'seconds' => 600, 'level' => 50]]];
        $this->api('POST', self::ACTIVITY, $item, $token, ['HTTP_IDEMPOTENCY_KEY' => 'a']);
        self::assertResponseStatusCodeSame(202);
        $this->assertLimited($this->api('POST', self::ACTIVITY, $item, $token, ['HTTP_IDEMPOTENCY_KEY' => 'a']));

        $discard = ['entryId' => $timer, 'slot' => $slot, 'reason' => 'worker'];
        $this->api('POST', self::DISCARDED, $discard, $token);
        $this->api('POST', self::DISCARDED, $discard, $token);
        $this->assertLimited($this->api('POST', self::DISCARDED, $discard, $token));
        $this->api('DELETE', '/api/time-track/monitoring/screenshots/x', null, $token);
        $this->api('DELETE', '/api/time-track/monitoring/screenshots/x', null, $token);
        $this->assertLimited($this->api('DELETE', '/api/time-track/monitoring/screenshots/x', null, $token));

        $this->intent($this->token(), $this->intentBody($timer, $image), 'other-token');
        self::assertNotSame(429, $this->client->getResponse()->getStatusCode(), 'per token');
    }

    /**
     * @param array<mixed>|null $body
     */
    private function assertLimited(?array $body): void
    {
        self::assertResponseStatusCodeSame(429);
        $retry = $this->client->getResponse()->headers->get('Retry-After');
        self::assertMatchesRegularExpression('/^[1-9]\d*$/', (string) $retry);
        self::assertSame(['rate_limited', (int) $retry], [$body['error'] ?? null, $body['retryAfter'] ?? null]);
        self::assertLessThanOrEqual(600, (int) $retry);
    }
}
