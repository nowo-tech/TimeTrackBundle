<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

/**
 * 1.9.1: `GET me` adds `minSupportedVersion` for the calling token's client type only when one is configured.
 */
final class ClientApiMinSupportedVersionTest extends FunctionalTestCase
{
    protected static function timeTrackConfig(): array
    {
        return ['client_distribution' => ['min_supported_version' => ['extension' => '1.4.0', 'desktop' => '2.0.0-beta.1']]];
    }

    public function testMeReturnsTheVersionOfTheTokenClientType(): void
    {
        $desktop = $this->api('GET', '/api/time-track/me', null, $this->login(clientType: 'desktop'));
        self::assertResponseIsSuccessful();
        self::assertSame('2.0.0-beta.1', $desktop['minSupportedVersion'] ?? null);

        $extension = $this->api('GET', '/api/time-track/me', null, $this->login(clientType: 'extension'));
        self::assertSame('1.4.0', $extension['minSupportedVersion'] ?? null);
    }

    public function testMeOmitsTheKeyWhenTheClientTypeHasNoVersion(): void
    {
        $web = $this->api('GET', '/api/time-track/me', null, $this->login(clientType: 'web'));
        self::assertResponseIsSuccessful();
        self::assertIsArray($web);
        self::assertArrayNotHasKey('minSupportedVersion', $web);
    }
}
