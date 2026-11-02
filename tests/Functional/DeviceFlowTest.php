<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Event\ClientApiEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Repository\DeviceAuthorizationRepositoryInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Twig\Environment;

use function array_map;
use function preg_match;

/**
 * Device flow (contract v0.2 §2) end to end: device/code, the default verification page (session, CSRF), approval
 * through the default credential issuer, single-use token exchange, denial, lookup throttle; and the downloads partial.
 */
final class DeviceFlowTest extends FunctionalTestCase
{
    /** @var list<ClientApiEvent> */
    private array $events = [];

    protected static function timeTrackConfig(): array
    {
        return [
            'client_api'          => ['device_flow' => ['enabled' => true, 'lookup_limit' => 3]],
            'client_distribution' => [
                'extension' => ['chrome' => 'https://chromewebstore.google.com/detail/time-track', 'firefox' => 'javascript:alert(1)'],
                'desktop'   => ['linux' => 'https://github.com/nowo-tech/time-track-desktop/releases/latest', 'latest_version' => '0.1.0'],
            ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $dispatcher->addListener(TimeTrackEvents::CLIENT_API, function (ClientApiEvent $event): void {
            $this->events[] = $event;
        });
    }

    public function testApproveExchangesTheDeviceCodeOnce(): void
    {
        $grant = $this->deviceCode();
        self::assertMatchesRegularExpression('/^[BCDFGHJKLMNPQRSTVWXZ]{4}-[BCDFGHJKLMNPQRSTVWXZ]{4}$/', $grant['userCode']);
        self::assertSame('http://localhost/tools/time-track/device', $grant['verificationUri']);
        self::assertSame('http://localhost/tools/time-track/device?code=' . $grant['userCode'], $grant['verificationUriComplete']);
        self::assertSame(600, $grant['expiresIn']);
        self::assertSame(5, $grant['interval']);

        $pending = $this->api('POST', '/api/time-track/device/token', ['deviceCode' => $grant['deviceCode']]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('authorization_pending', $pending['error'] ?? null);
        $slow = $this->api('POST', '/api/time-track/device/token', ['deviceCode' => $grant['deviceCode']]);
        self::assertSame('slow_down', $slow['error'] ?? null);

        $this->client->loginUser($this->user, 'main');
        $crawler = $this->client->request('GET', '/tools/time-track/device?code=' . $grant['userCode']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="time-track-device"]');
        self::assertSame($grant['userCode'], $crawler->filter('input[name="device_code[user_code]"]')->attr('value'));

        $crawler = $this->client->submit($crawler->filter('form')->form());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="time-track-device-confirm"]');
        self::assertSelectorTextContains('[data-testid="time-track-device-confirm"]', 'Tray app');
        self::assertSelectorTextContains('[data-testid="time-track-device-confirm"]', 'timer:read, timer:write');

        $this->client->submit($crawler->filter('[data-testid="time-track-device-approve"]')->form());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="time-track-device-result"]');
        self::assertSelectorTextContains('body', 'The device is connected');

        $token = $this->api('POST', '/api/time-track/device/token', ['deviceCode' => $grant['deviceCode']]);
        self::assertResponseIsSuccessful();
        self::assertSame(['timer:read', 'timer:write'], $token['scopes'] ?? null);

        $again = $this->api('POST', '/api/time-track/device/token', ['deviceCode' => $grant['deviceCode']]);
        self::assertSame('expired_token', $again['error'] ?? null, 'single use');

        $timer = $this->api('GET', '/api/time-track/timer', null, (string) $token['token']);
        self::assertResponseStatusCodeSame(204);
        $this->api('GET', '/api/time-track/tasks', null, (string) $token['token']);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($timer);

        $actions = array_map(static fn (ClientApiEvent $e): string => $e->action->value, $this->events);
        self::assertSame(['device_approved', 'token_issued', 'request_denied'], $actions);
        self::assertStringStartsWith('device:', (string) $this->events[0]->credentialId);
        self::assertSame($this->events[0]->credentialId, $this->events[1]->credentialId);
    }

    public function testDenyAndCsrfAndExpiredRequests(): void
    {
        $grant = $this->deviceCode();
        $this->client->loginUser($this->user, 'main');
        $crawler = $this->client->request('GET', '/tools/time-track/device');
        $form    = $crawler->filter('form')->form(['device_code[user_code]' => mb_strtolower($grant['userCode'])]);
        $crawler = $this->client->submit($form);
        self::assertSelectorExists('[data-testid="time-track-device-confirm"]');

        $deny = $crawler->filter('[data-testid="time-track-device-deny"]')->form();
        $this->client->request('POST', '/tools/time-track/device', ['decision' => 'deny', 'request' => $deny->getValues()['request'], '_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);

        $this->client->submit($deny);
        self::assertSelectorTextContains('body', 'denied');

        $this->client->submit($deny);
        self::assertSelectorTextContains('body', 'expired');

        $denied = $this->api('POST', '/api/time-track/device/token', ['deviceCode' => $grant['deviceCode']]);
        self::assertSame('access_denied', $denied['error'] ?? null);
        $this->api('POST', '/api/time-track/device/token', ['deviceCode' => $grant['deviceCode']]);
        self::assertSame('expired_token', $this->json()['error'] ?? null);
    }

    public function testWrongCodesAreRejectedAndThrottled(): void
    {
        $this->client->loginUser($this->user, 'main');
        $crawler = $this->client->request('GET', '/tools/time-track/device');
        foreach (['BCDF-GHJK', 'short', 'BCDF-GHJK', 'BCDF-GHJK'] as $i => $code) {
            $crawler = $this->client->submit($crawler->filter('form')->form(['device_code[user_code]' => $code]));
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('[data-testid="time-track-device"]', $i < 3 ? 'Invalid or expired code.' : 'Too many attempts.');
        }
    }

    public function testDeviceEndpointsValidateInputAndThePageNeedsASignedInUser(): void
    {
        $this->api('POST', '/api/time-track/device/code', ['clientType' => 'web']);
        self::assertResponseStatusCodeSame(400);

        $this->api('OPTIONS', '/api/time-track/device/code', null, null, ['HTTP_ORIGIN' => 'chrome-extension://functional']);
        self::assertResponseStatusCodeSame(204);
        $this->api('OPTIONS', '/api/time-track/device/token');
        self::assertResponseStatusCodeSame(204);

        $missing = $this->api('POST', '/api/time-track/device/token', ['deviceCode' => 'nope']);
        self::assertSame('expired_token', $missing['error'] ?? null);

        $grant = $this->api('POST', '/api/time-track/device/code', ['clientType' => 'extension', 'clientName' => "\x07", 'scopes' => ['entries:read']]);
        self::assertResponseIsSuccessful();
        self::assertIsArray($grant);

        $this->client->request('GET', '/tools/time-track/device');
        self::assertGreaterThanOrEqual(400, $this->client->getResponse()->getStatusCode());
    }

    public function testExpiredDeviceCodesArePurged(): void
    {
        $this->deviceCode();
        $this->entityManager()->getConnection()->executeStatement("UPDATE time_track_device_codes SET expires_at = '2000-01-01 00:00:00'");

        /** @var DeviceAuthorizationRepositoryInterface $repository */
        $repository = self::getContainer()->get(DeviceAuthorizationRepositoryInterface::class);
        self::assertSame(1, $repository->purgeExpired());
    }

    public function testDownloadsPartialRendersSafeLinksOnly(): void
    {
        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('@NowoTimeTrackBundle/client_distribution/_downloads.html.twig');

        self::assertStringContainsString('data-testid="time-track-downloads"', $html);
        self::assertStringContainsString('href="https://chromewebstore.google.com/detail/time-track"', $html);
        self::assertStringContainsString('href="https://github.com/nowo-tech/time-track-desktop/releases/latest"', $html);
        self::assertStringContainsString('0.1.0', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('style=', $html);
        self::assertSame(1, preg_match('/Time tracking apps/', $html));
    }

    /**
     * @return array{deviceCode: string, userCode: string, verificationUri: string, verificationUriComplete: string, expiresIn: int, interval: int}
     */
    private function deviceCode(): array
    {
        $grant = $this->api('POST', '/api/time-track/device/code', ['clientType' => 'desktop', 'clientName' => 'Tray app', 'scopes' => ['timer:read', 'timer:write']]);
        self::assertResponseIsSuccessful();
        self::assertIsArray($grant);

        [$deviceCode, $userCode, $uri, $complete, $expiresIn, $interval] = [$grant['deviceCode'] ?? null, $grant['userCode'] ?? null, $grant['verificationUri'] ?? null, $grant['verificationUriComplete'] ?? null, $grant['expiresIn'] ?? null, $grant['interval'] ?? null];
        self::assertIsString($deviceCode);
        self::assertIsString($userCode);
        self::assertIsString($uri);
        self::assertIsString($complete);
        self::assertIsInt($expiresIn);
        self::assertIsInt($interval);

        return ['deviceCode' => $deviceCode, 'userCode' => $userCode, 'verificationUri' => $uri, 'verificationUriComplete' => $complete, 'expiresIn' => $expiresIn, 'interval' => $interval];
    }

    /**
     * @return array<mixed>
     */
    private function json(): array
    {
        /** @var array<mixed> $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        return $body;
    }
}
