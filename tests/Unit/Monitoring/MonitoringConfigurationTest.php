<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Monitoring;

use App\Entity\User;
use Nowo\TimeTrackBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

/**
 * Since 1.26.0 (audit PM-L2 / delta D1-L1): `client_api.monitoring.upload_base_url` takes an exact https origin (one
 * trailing "/" allowed) or an env placeholder (checked at runtime), and `policy_source` needs `evidence_store`.
 */
final class MonitoringConfigurationTest extends TestCase
{
    public function testUploadBaseUrl(): void
    {
        foreach (['https://beacon.example', 'https://beacon.example:8443/', '%env(DEFAULT_URI)%', '', null] as $valid) {
            self::assertSame($valid, $this->process(['upload_base_url' => $valid])['client_api']['monitoring']['upload_base_url']);
        }
        foreach (['http://beacon.example', 'https://beacon.example/path', 'https://beacon.example//', 5] as $invalid) {
            try {
                $this->process(['upload_base_url' => $invalid]);
                self::fail('refused expected');
            } catch (InvalidConfigurationException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidConfigurationException::class);
        $this->process(['policy_source' => 'App\\Source']);
    }

    /**
     * @param array<string, mixed> $monitoring
     *
     * @return array<string, mixed>
     */
    private function process(array $monitoring): array
    {
        /* @var array<string, mixed> */
        return (new Processor())->processConfiguration(new Configuration(), [['user_class' => User::class, 'client_api' => ['monitoring' => $monitoring]]]);
    }
}
