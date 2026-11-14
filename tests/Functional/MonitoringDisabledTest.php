<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Monitoring\MonitoringSpanDiscardListener;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;

/**
 * Since 1.26.0 (contract §24.6): off by default — without a policy source `GET me` keeps the 1.25 body
 * (`features.monitoring: false`, no `monitoring` key) and every monitoring route answers 404, also to a token with
 * `monitoring:write`.
 */
class MonitoringDisabledTest extends FunctionalTestCase
{
    public function testEverythingIsOffByDefault(): void
    {
        $me = $this->api('GET', '/api/time-track/me', null, $this->login());
        self::assertIsArray($me);
        self::assertFalse($me['features']['monitoring'] ?? null);
        self::assertArrayNotHasKey('monitoring', $me);
        self::assertFalse(self::getContainer()->getParameter('nowo_time_track.client_api.monitoring.enabled'));

        $auth = self::getContainer()->get(ClientAuthService::class);
        self::assertInstanceOf(ClientAuthService::class, $auth);
        $user = $this->entityManager()->find(AppUser::class, $this->user->getId());
        self::assertInstanceOf(AppUser::class, $user);
        $token = (string) $auth->issueToken($user, ClientType::Desktop, null, [ClientScope::MonitoringWrite])['token'];
        self::assertNotContains('monitoring:write', $this->api('GET', '/api/time-track/me', null, $this->login())['scopes'] ?? [], 'never a default scope');

        self::assertFalse(self::getContainer()->has(MonitoringSpanDiscardListener::class), 'no span event while off (Beacon Q3)');
        foreach ([
            ['POST', '/api/time-track/monitoring/acknowledge'],
            ['POST', '/api/time-track/monitoring/screenshots/intent'],
            ['PUT', '/api/time-track/monitoring/uploads/00000000-0000-4000-8000-000000000000?exp=1&sig=x'],
            ['POST', '/api/time-track/monitoring/screenshots/discarded'],
            ['DELETE', '/api/time-track/monitoring/screenshots/shot-1'],
            ['POST', '/api/time-track/monitoring/activity'],
        ] as [$method, $uri]) {
            $body = $this->api($method, $uri, ['policyVersion' => 1], $token, ['HTTP_IDEMPOTENCY_KEY' => 'k']);
            self::assertResponseStatusCodeSame(404, $uri);
            self::assertSame('not_found', $body['error'] ?? null);
            self::assertSame('no-store, private', $this->client->getResponse()->headers->get('Cache-Control'));
        }
    }
}
