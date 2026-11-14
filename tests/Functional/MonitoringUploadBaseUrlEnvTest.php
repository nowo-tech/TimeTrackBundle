<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Tests\Stub\MutableProfileProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubMonitoringEvidenceStore;
use Nowo\TimeTrackBundle\Tests\Stub\StubMonitoringPolicySource;
use Nowo\TimeTrackBundle\Tests\Support\MonitoringFixtures;

use function putenv;

/**
 * Since 1.26.0 (audit PM delta D1-L1, probe P9): `upload_base_url` accepts an env placeholder; the resolved value is
 * checked at runtime, and anything that is not an exact https origin fails closed (503, nothing signed).
 */
class MonitoringUploadBaseUrlEnvTest extends MonitoringTestCase
{
    private const ENV = 'TT_MONITORING_TEST_UPLOAD_BASE';

    protected static function timeTrackConfig(): array
    {
        return ['client_api' => [
            'profile_provider' => MutableProfileProvider::class,
            'monitoring'       => [
                'policy_source'   => StubMonitoringPolicySource::class,
                'evidence_store'  => StubMonitoringEvidenceStore::class,
                'upload_base_url' => '%env(' . self::ENV . ')%',
            ],
        ]];
    }

    protected function tearDown(): void
    {
        $this->setEnv(null);
        parent::tearDown();
    }

    /**
     * Test only: the env var the placeholder resolves to (the kernel resolves env vars at runtime).
     */
    private function setEnv(?string $value): void
    {
        if ($value === null) {
            unset($_SERVER[self::ENV], $_ENV[self::ENV]); // @phpstan-ignore frankenphp.worker.noEnvMutation (test only)
            putenv(self::ENV); // @phpstan-ignore frankenphp.classic.noPutenv (test only)

            return;
        }
        $_SERVER[self::ENV] = $value;
        $_ENV[self::ENV]    = $value; // @phpstan-ignore frankenphp.worker.noEnvMutation (test only)
        putenv(self::ENV . '=' . $value); // @phpstan-ignore frankenphp.classic.noPutenv (test only)
    }

    public function testAnEnvPlaceholderIsResolvedAndCheckedAtRuntime(): void
    {
        foreach ([
            'https://beacon.example'      => 201,
            'https://beacon.example/'     => 201, // DEFAULT_URI style: one trailing slash is the origin
            'http://beacon.example'       => 503,
            'https://beacon.example/path' => 503,
            'https://beacon.example//'    => 503,
            'https://beacon.example/?q=1' => 503,
            'https://beacon.example/#f'   => 503,
            'https://user@beacon.example' => 503,
        ] as $value => $status) {
            $this->setEnv($value);
            self::ensureKernelShutdown();
            $this->setUp();
            $token = $this->token();
            $this->acknowledge($token);
            $timer = $this->startTimer($token);
            $grant = $this->intent($token, $this->intentBody($timer, MonitoringFixtures::webp(1280, 720)), 'env');
            self::assertResponseStatusCodeSame($status, $value);
            if ($status === 201) {
                self::assertStringStartsWith('https://beacon.example/api/time-track/monitoring/uploads/', self::url($grant));
            } else {
                self::assertSame('monitoring_unavailable', $grant['error'] ?? null);
                self::assertNotContains($value, $this->logger()->contextValues(), 'the value is never logged');
            }
        }
    }
}
