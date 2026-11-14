<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Tests\Stub\MutableProfileProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubMonitoringEvidenceStore;
use Nowo\TimeTrackBundle\Tests\Stub\StubMonitoringPolicySource;
use Nowo\TimeTrackBundle\Tests\Support\MonitoringFixtures;

/**
 * Since 1.26.0 (audit PM-L2, probe P4): with `client_api.monitoring.upload_base_url` the signed URL never follows the
 * request's Host header or scheme.
 */
class MonitoringUploadBaseUrlTest extends MonitoringTestCase
{
    protected static function timeTrackConfig(): array
    {
        return ['client_api' => [
            'profile_provider' => MutableProfileProvider::class,
            'monitoring'       => [
                'policy_source'   => StubMonitoringPolicySource::class,
                'evidence_store'  => StubMonitoringEvidenceStore::class,
                'upload_base_url' => 'https://beacon.example:8443',
            ],
        ]];
    }

    public function testTheBaseUrlWinsOverTheHostHeader(): void
    {
        $token = $this->token();
        $this->acknowledge($token);
        $timer = $this->startTimer($token);
        $image = MonitoringFixtures::webp(1280, 720);
        $grant = $this->api('POST', self::INTENT, $this->intentBody($timer, $image), $token, ['HTTP_IDEMPOTENCY_KEY' => 'p4', 'HTTP_HOST' => 'evil.example', 'HTTP_X_FORWARDED_PROTO' => 'http']);
        self::assertResponseStatusCodeSame(201);
        self::assertMatchesRegularExpression('#^https://beacon\.example:8443/api/time-track/monitoring/uploads/[0-9a-f-]{36}\?exp=\d+&sig=[0-9a-f]{64}$#', self::url($grant));
        $this->put(self::url($grant), $token, $image);
        self::assertResponseStatusCodeSame(201, 'the path and signature are the same as on the request host');
    }
}
