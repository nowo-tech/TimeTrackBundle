<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

/**
 * Since 1.26.0 (audit PM-L9, Beacon Q5): the device consent page spells out `monitoring:write` with a translated label
 * and what it allows; other requests do not show it.
 */
class MonitoringConsentTest extends FunctionalTestCase
{
    protected static function timeTrackConfig(): array
    {
        return ['client_api' => ['device_flow' => ['enabled' => true]]];
    }

    public function testTheConsentPageExplainsMonitoringWrite(): void
    {
        $this->client->loginUser($this->user, 'main');
        foreach ([[['timer:read', 'monitoring:write'], true], [['timer:read'], false]] as [$scopes, $shown]) {
            $grant = $this->api('POST', '/api/time-track/device/code', ['clientType' => 'desktop', 'clientName' => 'Tray app', 'scopes' => $scopes]);
            self::assertResponseIsSuccessful();
            $crawler = $this->client->request('GET', '/tools/time-track/device?code=' . ($grant['userCode'] ?? ''));
            $this->client->submit($crawler->filter('form')->form());
            self::assertSelectorExists('[data-testid="time-track-device-confirm"]');
            $text = $this->client->getCrawler()->filter('[data-testid="time-track-device-confirm"]')->text();
            self::assertSame($shown, str_contains($text, 'Activity monitoring (monitoring:write)'));
            self::assertSame($shown, str_contains($text, 'only while your own timer runs'));
        }
    }
}
