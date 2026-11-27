<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Client\ClientFamilyTokenAuthenticator;
use Nowo\TimeTrackBundle\Entity\DeviceAuthorization;
use Nowo\TimeTrackBundle\Enum\ClientApiAuditAction;
use Nowo\TimeTrackBundle\Event\ClientApiEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Exception\DeviceAuthorizationRefusedException;
use Nowo\TimeTrackBundle\Service\DeviceAuthorizationService;
use Nowo\TimeTrackBundle\Tests\Stub\RecordingFamilyCredentialMinter;
use Nowo\TimeTrackBundle\Tests\Stub\StubClientCapabilitiesProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubClientContextPolicy;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_filter;
use function array_map;
use function array_values;
use function json_decode;

/**
 * Since 2.4.0 (Beacon spec 262 block B, Beacon Desktop permission parity): the host's capabilities provider decides
 * which modules (scope families) a person may use — `GET me/capabilities` with a token of any family, no grant of a
 * refused family (device flow, approval page, exchange, password login) and `403 module_forbidden` on every request of a
 * family the person lost, with the token kept.
 */
final class ClientCapabilitiesClientApiTest extends FunctionalTestCase
{
    /** @var list<ClientApiEvent> */
    private array $events = [];

    protected static function timeTrackConfig(): array
    {
        return [
            'client_api' => [
                'device_flow' => [
                    'enabled'        => true,
                    'scope_families' => [
                        'chat'  => ['label' => 'Chat', 'scopes' => ['chat:read' => 'Read your conversations', 'chat:write' => 'Send messages']],
                        'tasks' => ['label' => 'Tasks', 'scopes' => ['tasks:board' => 'Read your boards']],
                    ],
                ],
                'context'           => ['policy' => StubClientContextPolicy::class, 'header' => 'X-Beacon-Context'],
                'credential_issuer' => RecordingFamilyCredentialMinter::class,
                'capabilities'      => ['provider' => StubClientCapabilitiesProvider::class],
            ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider()->reset();
        $this->minter()->requests = [];
        $policy                   = self::getContainer()->get(StubClientContextPolicy::class);
        self::assertInstanceOf(StubClientContextPolicy::class, $policy);
        $policy->reset();

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $dispatcher->addListener(TimeTrackEvents::CLIENT_API, function (ClientApiEvent $event): void {
            $this->events[] = $event;
        });
    }

    public function testCapabilitiesListEveryModuleWithAnyFamilyToken(): void
    {
        $time = $this->login();
        $body = $this->api('GET', '/api/time-track/v1/me/capabilities', null, $time);
        self::assertResponseIsSuccessful();
        self::assertSame('no-store, private', $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame([
            'userId'          => (string) $this->user->getId(),
            'scopeFamily'     => 'time',
            'scopes'          => ['timer:read', 'timer:write', 'tasks:read', 'entries:read'],
            'context'         => 'personal',
            'modules'         => ['time' => true, 'chat' => true, 'tasks' => false],
            'grantableScopes' => [
                'time' => ['timer:read', 'timer:write', 'tasks:read', 'entries:read', 'workday:read', 'workday:write', 'balance:read', 'timesheet:read', 'timesheet:write', 'entries:write', 'suggestions:read', 'suggestions:write', 'meetings:read', 'monitoring:write'],
                'chat' => ['chat:read', 'chat:write'],
            ],
        ], $body);

        // The unversioned alias and a chat token (any family) answer the same modules, in the named context.
        $chat = $this->deviceFlow(['clientType' => 'desktop', 'family' => 'chat', 'scopes' => ['chat:read']]);
        $body = $this->api('GET', '/api/time-track/me/capabilities', null, $chat, ['HTTP_X_BEACON_CONTEXT' => 'org:acme']);
        self::assertResponseIsSuccessful();
        self::assertSame('chat', $body['scopeFamily'] ?? null);
        self::assertSame(['chat:read'], $body['scopes'] ?? null);
        self::assertSame('org:acme', $body['context'] ?? null);
        self::assertContains(['person@example.com', 'chat', 'org:acme'], $this->provider()->asked);

        // Nothing usable: every module false and no grantable scope (an object, never a list).
        $this->provider()->allowed = [];
        $this->client->request('GET', '/api/time-track/v1/me/capabilities', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $chat]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('"modules":{"time":false,"chat":false,"tasks":false},"grantableScopes":{}', (string) $this->client->getResponse()->getContent());

        // The usual refusals: no token, a forbidden context, a preflight.
        self::assertSame('invalid_token', $this->api('GET', '/api/time-track/v1/me/capabilities')['error'] ?? null);
        self::assertResponseStatusCodeSame(401);
        self::assertSame('context_forbidden', $this->api('GET', '/api/time-track/v1/me/capabilities', null, $chat, ['HTTP_X_BEACON_CONTEXT' => 'org:nope'])['error'] ?? null);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('OPTIONS', '/api/time-track/v1/me/capabilities');
        self::assertResponseStatusCodeSame(204);
    }

    public function testATokenOfAFamilyNoLongerRegisteredGetsNoCapabilities(): void
    {
        $time = $this->login();
        $this->bundleEntityManager()->getConnection()->executeStatement('UPDATE time_track_client_tokens SET scope_family = ?', ['voice']);
        $this->clearEntityManagers();

        $body = $this->api('GET', '/api/time-track/v1/me/capabilities', null, $time);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('insufficient_scope', $body['error'] ?? null);
    }

    public function testARevokedModuleAnswersModuleForbiddenAndKeepsTheToken(): void
    {
        $time = $this->login();
        $chat = $this->deviceFlow(['clientType' => 'desktop', 'family' => 'chat', 'scopes' => ['chat:read']]);

        $this->provider()->allowed = ['chat'];
        foreach (['/api/time-track/v1/timer', '/api/time-track/me'] as $path) {
            $body = $this->api('GET', $path, null, $time);
            self::assertResponseStatusCodeSame(403, $path);
            self::assertSame(['error' => 'module_forbidden', 'message' => 'You may not use this module now. Refresh the capabilities; the token is still valid.', 'module' => 'time'], $body, $path);
        }
        self::assertNull($this->client->getResponse()->headers->get('WWW-Authenticate'));
        $denied = array_values(array_filter($this->events, static fn (ClientApiEvent $e): bool => $e->action === ClientApiAuditAction::RequestDenied));
        self::assertSame(['module_forbidden', 'time'], [$denied[0]->reason ?? null, $denied[0]->scopeFamily ?? null]);

        // The family authenticator of another bundle's API refuses the same way.
        $this->provider()->allowed = ['time'];
        $answer                    = $this->authenticator()->authenticate('chat', 'Bearer ' . $chat, 'chat:read');
        self::assertSame('module_forbidden', $answer->error);
        self::assertSame(403, $answer->status());
        self::assertSame([], $answer->headers());
        self::assertSame(['module' => 'chat'], $answer->body());

        // One context only: refused there, allowed elsewhere (the provider gets the request's context).
        $this->provider()->allowed         = ['time', 'chat'];
        $this->provider()->deniedInContext = 'org:acme';
        $this->api('GET', '/api/time-track/v1/timer', null, $time, ['HTTP_X_BEACON_CONTEXT' => 'org:acme']);
        self::assertResponseStatusCodeSame(403);
        $this->api('GET', '/api/time-track/v1/timer', null, $time, ['HTTP_X_BEACON_CONTEXT' => 'personal']);
        self::assertResponseIsSuccessful();

        // Permission back: the same tokens work again (never revoked).
        $this->provider()->reset();
        $this->api('GET', '/api/time-track/v1/me', null, $time);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->authenticator()->authenticate('chat', 'Bearer ' . $chat, 'chat:read')->isAuthenticated());

        // A failing provider fails closed.
        $this->provider()->fail = true;
        $this->api('GET', '/api/time-track/v1/me', null, $time);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Audit ZDB-L1: a request without a context header runs in the host's default context (the policy's first), never in
     * "any context"; grant-time calls get ANY_CONTEXT ('').
     */
    public function testARequestWithoutAHeaderIsCheckedInTheDefaultContext(): void
    {
        // Time allowed in org:acme, refused in the remembered default ("personal").
        $this->provider()->deniedInContext = 'personal';
        $time                              = $this->login();
        self::assertContains(['person@example.com', 'time', ''], $this->provider()->asked, 'the login asks with ANY_CONTEXT');

        $body = $this->api('GET', '/api/time-track/v1/timer', null, $time);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('module_forbidden', $body['error'] ?? null);
        self::assertContains(['person@example.com', 'time', 'personal'], $this->provider()->asked);
        $this->api('GET', '/api/time-track/v1/timer', null, $time, ['HTTP_X_BEACON_CONTEXT' => 'org:acme']);
        self::assertResponseIsSuccessful();

        // me/capabilities without a header reports the default context, consistent with the requests.
        $body = $this->api('GET', '/api/time-track/v1/me/capabilities', null, $time);
        self::assertSame('personal', $body['context'] ?? null);
        self::assertSame(['time' => false, 'chat' => false, 'tasks' => false], $body['modules'] ?? null);
        $body = $this->api('GET', '/api/time-track/v1/me/capabilities', null, $time, ['HTTP_X_BEACON_CONTEXT' => 'org:acme']);
        self::assertSame('org:acme', $body['context'] ?? null);
        self::assertSame(['time' => true, 'chat' => true, 'tasks' => false], $body['modules'] ?? null);

        // A policy that lists no context: no default, refused without asking the provider (fail closed).
        $policy = self::getContainer()->get(StubClientContextPolicy::class);
        self::assertInstanceOf(StubClientContextPolicy::class, $policy);
        $policy->fail            = true;
        $this->provider()->asked = [];
        $this->api('GET', '/api/time-track/v1/timer', null, $time);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('module_forbidden', $this->json()['error'] ?? null);
        self::assertSame([], $this->provider()->asked);
    }

    public function testAPasswordLoginGrantsTimeOnlyToAPersonWhoMayUseIt(): void
    {
        $this->provider()->allowed = ['chat'];
        $body                      = $this->api('POST', '/api/time-track/v1/login', ['username' => 'person@example.com', 'password' => 'secret', 'clientType' => 'desktop']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'module_forbidden', 'message' => 'You may not use time tracking. No token was issued.', 'module' => 'time'], $body);
        $denied = array_values(array_filter($this->events, static fn (ClientApiEvent $e): bool => $e->action === ClientApiAuditAction::RequestDenied));
        self::assertSame('module_forbidden', $denied[0]->reason ?? null);
        self::assertNotContains(ClientApiAuditAction::TokenIssued, array_map(static fn (ClientApiEvent $e): ClientApiAuditAction => $e->action, $this->events));
        self::assertContains(['person@example.com', 'time', ''], $this->provider()->asked);

        // A wrong password still answers 401 (the provider is not asked first).
        $this->api('POST', '/api/time-track/v1/login', ['username' => 'person@example.com', 'password' => 'nope']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testARefusedFamilyIsNeitherShownNorGranted(): void
    {
        $grant = $this->api('POST', '/api/time-track/v1/device/code', ['clientType' => 'desktop', 'clientName' => 'Laptop', 'family' => 'tasks', 'scopes' => ['tasks:board']]);
        self::assertResponseIsSuccessful();

        $this->openApproval($this->string($grant, 'userCode'));
        self::assertSelectorExists('[data-testid="time-track-device-result"]');
        self::assertSelectorTextContains('body', 'Your account cannot use this part of the app');
        self::assertSelectorTextNotContains('body', 'Read your boards');
        self::assertSame([], $this->minter()->requests, 'no credential minted');

        // Denied at once: the device stops polling.
        $token = $this->api('POST', '/api/time-track/v1/device/token', ['deviceCode' => $this->string($grant, 'deviceCode')]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('access_denied', $token['error'] ?? null);
        $denied = array_values(array_filter($this->events, static fn (ClientApiEvent $e): bool => $e->action === ClientApiAuditAction::DeviceDenied));
        self::assertSame(['scope_family_not_granted', 'tasks'], [$denied[0]->reason ?? null, $denied[0]->scopeFamily ?? null]);
        self::assertContains(['person@example.com', 'tasks', ''], $this->provider()->asked);
    }

    public function testThePermissionIsCheckedAgainAtApprovalAndAtTheExchange(): void
    {
        // Lost between the page and the submit: the approval is refused, nothing minted.
        $grant   = $this->api('POST', '/api/time-track/v1/device/code', ['clientType' => 'desktop', 'family' => 'chat', 'scopes' => ['chat:read']]);
        $crawler = $this->openApproval($this->string($grant, 'userCode'));
        self::assertSelectorTextContains('[data-testid="time-track-device-confirm"]', 'Read your conversations');
        $this->provider()->allowed = ['time'];
        $this->client->submit($crawler->filter('[data-testid="time-track-device-approve"]')->form());
        self::assertSelectorTextContains('body', 'Your account cannot use this part of the app');
        self::assertSame([], $this->minter()->requests);
        $this->api('POST', '/api/time-track/v1/device/token', ['deviceCode' => $this->string($grant, 'deviceCode')]);
        self::assertSame('access_denied', $this->json()['error'] ?? null);

        // Lost between the approval and the exchange: no token.
        $this->provider()->reset();
        $grant   = $this->api('POST', '/api/time-track/v1/device/code', ['clientType' => 'desktop', 'family' => 'chat', 'scopes' => ['chat:read']]);
        $crawler = $this->openApproval($this->string($grant, 'userCode'));
        $this->client->submit($crawler->filter('[data-testid="time-track-device-approve"]')->form());
        self::assertCount(1, $this->minter()->requests);
        $this->provider()->allowed = ['time'];
        $this->api('POST', '/api/time-track/v1/device/token', ['deviceCode' => $this->string($grant, 'deviceCode')]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('access_denied', $this->json()['error'] ?? null);
        $reasons = array_map(static fn (ClientApiEvent $e): ?string => $e->reason, array_filter($this->events, static fn (ClientApiEvent $e): bool => $e->action === ClientApiAuditAction::DeviceDenied));
        self::assertSame(['scope_family_not_granted', 'scope_family_not_granted'], array_values($reasons));
    }

    public function testTheServiceRefusesAnApprovalAHostPageDidNotCheck(): void
    {
        $service = self::getContainer()->get(DeviceAuthorizationService::class);
        self::assertInstanceOf(DeviceAuthorizationService::class, $service);
        $grant         = $this->api('POST', '/api/time-track/v1/device/code', ['clientType' => 'desktop', 'family' => 'tasks', 'scopes' => ['tasks:board']]);
        $authorization = $service->findPending($this->string($grant, 'userCode'), $this->user);
        self::assertInstanceOf(DeviceAuthorization::class, $authorization);
        self::assertFalse($service->isGrantableTo($authorization, $this->user));

        try {
            $service->approve($authorization, $this->user);
            self::fail('The approval of a refused family must throw.');
        } catch (DeviceAuthorizationRefusedException $refused) {
            self::assertSame(DeviceAuthorizationRefusedException::FAMILY_NOT_GRANTED, $refused->reason);
        }
        self::assertSame([], $this->minter()->requests);
    }

    /**
     * @param array<string, mixed> $request
     */
    private function deviceFlow(array $request): string
    {
        $grant   = $this->api('POST', '/api/time-track/v1/device/code', $request);
        $crawler = $this->openApproval($this->string($grant, 'userCode'));
        $this->client->submit($crawler->filter('[data-testid="time-track-device-approve"]')->form());
        $token = $this->api('POST', '/api/time-track/v1/device/token', ['deviceCode' => $this->string($grant, 'deviceCode')]);
        self::assertResponseIsSuccessful();

        return $this->string($token, 'token');
    }

    private function openApproval(string $userCode): Crawler
    {
        $this->client->loginUser($this->user, 'main');
        $crawler = $this->client->request('GET', '/tools/time-track/device?code=' . $userCode);

        return $this->client->submit($crawler->filter('form')->form());
    }

    /**
     * @return array<mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<mixed>|null $body
     */
    private function string(?array $body, string $key): string
    {
        self::assertIsString($body[$key] ?? null, $key);

        return $body[$key];
    }

    private function provider(): StubClientCapabilitiesProvider
    {
        $provider = self::getContainer()->get(StubClientCapabilitiesProvider::class);
        self::assertInstanceOf(StubClientCapabilitiesProvider::class, $provider);

        return $provider;
    }

    private function minter(): RecordingFamilyCredentialMinter
    {
        $minter = self::getContainer()->get(RecordingFamilyCredentialMinter::class);
        self::assertInstanceOf(RecordingFamilyCredentialMinter::class, $minter);

        return $minter;
    }

    private function authenticator(): ClientFamilyTokenAuthenticator
    {
        $authenticator = self::getContainer()->get(ClientFamilyTokenAuthenticator::class);
        self::assertInstanceOf(ClientFamilyTokenAuthenticator::class, $authenticator);

        return $authenticator;
    }
}
