<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Client\ClientFamilyTokenAuthenticator;
use Nowo\TimeTrackBundle\Client\ClientTokenRevoker;
use Nowo\TimeTrackBundle\Client\CurrentClientContext;
use Nowo\TimeTrackBundle\Client\DeviceScopeFamilyRegistry;
use Nowo\TimeTrackBundle\Enum\ClientApiAuditAction;
use Nowo\TimeTrackBundle\Enum\ClientSessionRevocation;
use Nowo\TimeTrackBundle\Event\ClientApiEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Query\ClientAccessQueryInterface;
use Nowo\TimeTrackBundle\Query\ConnectedClientDevicesQueryInterface;
use Nowo\TimeTrackBundle\Tests\Stub\RecordingFamilyCredentialMinter;
use Nowo\TimeTrackBundle\Tests\Stub\StubClientContextPolicy;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use function array_column;
use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function sort;

/**
 * Since 2.2.0 (Beacon Desktop BD-2, design §4): device-flow scope families end to end — a host family (`chat`) next to
 * `time`, one token per family, deny by default, the family on the approval page and in the audit, the chained flow
 * that joins a device's families (Q-BD-6 / Q-BD-19), the family authenticator other bundles use, and the explicit tenant
 * context header checked on every request (Q-BD-7).
 */
final class ScopeFamilyClientApiTest extends FunctionalTestCase
{
    /** @var list<ClientApiEvent> */
    private array $events = [];

    protected static function timeTrackConfig(): array
    {
        return [
            'client_api' => [
                'device_flow' => [
                    'enabled'        => true,
                    'scope_families' => ['chat' => ['label' => 'Chat', 'scopes' => ['chat:read' => 'Read your conversations', 'chat:write' => 'Send messages']]],
                ],
                'context'           => ['policy' => StubClientContextPolicy::class, 'header' => 'X-Beacon-Context'],
                'credential_issuer' => RecordingFamilyCredentialMinter::class,
            ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy()->reset();
        $this->minter()->requests = [];

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $dispatcher->addListener(TimeTrackEvents::CLIENT_API, function (ClientApiEvent $event): void {
            $this->events[] = $event;
        });
    }

    public function testAChatTokenNeverOpensTheTimeApi(): void
    {
        $grant = $this->api('POST', '/api/time-track/v1/device/code', ['clientType' => 'desktop', 'clientName' => 'Beacon Desktop', 'family' => 'chat', 'scopes' => ['chat:read']]);
        self::assertResponseIsSuccessful();
        self::assertSame('chat', $grant['scopeFamily'] ?? null);

        $crawler = $this->openApproval($this->string($grant, 'userCode'));
        self::assertSelectorTextContains('body', 'asks for access to: Chat. This approval covers Chat only.');
        self::assertSelectorTextContains('[data-testid="time-track-device-confirm"]', 'Read your conversations');
        $this->client->submit($crawler->filter('[data-testid="time-track-device-approve"]')->form());
        self::assertSelectorExists('[data-testid="time-track-device-result"]');

        $token = $this->api('POST', '/api/time-track/v1/device/token', ['deviceCode' => $this->string($grant, 'deviceCode')]);
        self::assertResponseIsSuccessful();
        self::assertSame(['chat:read'], $token['scopes'] ?? null);
        self::assertSame('chat', $token['scopeFamily'] ?? null);
        $chat = $this->string($token, 'token');

        foreach (['/api/time-track/me', '/api/time-track/v1/me', '/api/time-track/v1/timer', '/api/time-track/v1/tasks'] as $path) {
            $body = $this->api('GET', $path, null, $chat);
            self::assertResponseStatusCodeSame(403, $path);
            self::assertSame('insufficient_scope', $body['error'] ?? null, $path);
        }

        // The time API's logout leaves a token of another family alone.
        $this->api('POST', '/api/time-track/v1/logout', null, $chat);
        self::assertResponseStatusCodeSame(204);
        self::assertTrue($this->authenticator()->authenticate('chat', 'Bearer ' . $chat, 'chat:read')->isAuthenticated());

        // The family-aware minter got the family, its scopes and a device key; the audit names the family.
        self::assertCount(1, $this->minter()->requests);
        self::assertSame('chat', $this->minter()->requests[0]->scopeFamily);
        self::assertSame(['chat:read'], $this->minter()->requests[0]->scopes);
        $families = array_map(static fn (ClientApiEvent $e): array => [$e->action, $e->scopeFamily, $e->reason], $this->events);
        self::assertContains([ClientApiAuditAction::DeviceApproved, 'chat', null], $families);
        self::assertContains([ClientApiAuditAction::TokenIssued, 'chat', null], $families);
        self::assertContains([ClientApiAuditAction::RequestDenied, 'chat', 'scope_family'], $families);
    }

    public function testATimeTokenNeverOpensAnotherFamilysApi(): void
    {
        $time   = $this->login();
        $answer = $this->authenticator()->authenticate('chat', 'Bearer ' . $time, 'chat:read', '203.0.113.9');

        self::assertFalse($answer->isAuthenticated());
        self::assertSame('insufficient_scope', $answer->error);
        self::assertSame(403, $answer->status());
        self::assertSame(['WWW-Authenticate' => 'Bearer error="insufficient_scope", scope="chat:read"'], $answer->headers());
        self::assertSame('invalid_token', $this->authenticator()->authenticate('chat', null)->error);
        self::assertSame('invalid_token', $this->authenticator()->authenticate('chat', 'Bearer nope')->error);

        // Still a working time token.
        $this->api('GET', '/api/time-track/v1/me', null, $time);
        self::assertResponseIsSuccessful();
        // The chat API's logout leaves a time token alone.
        self::assertFalse($this->authenticator()->revoke('chat', 'Bearer ' . $time));
        $this->api('GET', '/api/time-track/v1/me', null, $time);
        self::assertResponseIsSuccessful();
    }

    public function testARequestNamesTheScopesOfOneRegisteredFamily(): void
    {
        foreach ([
            ['scopes' => ['timer:read', 'chat:read']],
            ['family' => 'time', 'scopes' => ['chat:read']],
            ['family' => 'chat', 'scopes' => ['timer:read']],
            ['family' => 'chat'],
            ['family' => 'voice', 'scopes' => ['voice:call']],
            ['family' => 'Chat', 'scopes' => ['chat:read']],
            ['family' => ['chat'], 'scopes' => ['chat:read']],
            ['scopes' => ['chat:delete']],
            ['scopes' => [1]],
        ] as $request) {
            $body = $this->api('POST', '/api/time-track/v1/device/code', ['clientType' => 'desktop', 'clientName' => 'x'] + $request);
            self::assertResponseStatusCodeSame(400);
            self::assertSame('invalid_scope', $body['error'] ?? null);
        }

        // The family follows from the scopes; no family and no scopes stays the time default (v0.4 clients).
        $chat = $this->api('POST', '/api/time-track/v1/device/code', ['clientType' => 'desktop', 'scopes' => ['chat:write', 'chat:read', 'chat:read']]);
        self::assertSame('chat', $chat['scopeFamily'] ?? null);
        $time = $this->api('POST', '/api/time-track/device/code', ['clientType' => 'desktop']);
        self::assertSame('time', $time['scopeFamily'] ?? null);
    }

    public function testTheChainedFlowJoinsTheFamiliesOfOneDeviceEachRevocableAlone(): void
    {
        $time = $this->deviceFlow(['clientType' => 'desktop', 'clientName' => 'Laptop'], null);
        $chat = $this->deviceFlow(['clientType' => 'desktop', 'clientName' => 'Laptop', 'family' => 'chat', 'scopes' => ['chat:read', 'chat:write']], $time);

        $requests = $this->minter()->requests;
        self::assertSame(['time', 'chat'], array_map(static fn ($r): string => $r->scopeFamily, $requests));
        self::assertSame($requests[0]->deviceKey, $requests[1]->deviceKey, 'one device key for both families');

        $owner   = (string) $this->user->getId();
        $devices = $this->devices()->devicesOf($owner);
        self::assertCount(1, $devices);
        self::assertSame(['time', 'chat'], $devices[0]->scopeFamilies);
        self::assertSame($requests[0]->deviceKey, $devices[0]->deviceKey);
        self::assertSame('Laptop', $devices[0]->clientName);
        self::assertCount(1, $devices[0]->sessionsOf('chat'));
        self::assertSame(['chat:read', 'chat:write'], $devices[0]->sessionsOf('chat')[0]->scopes);

        $families = array_column($this->sessions()->sessionsOf($owner), 'scopeFamily');
        sort($families);
        self::assertSame(['chat', 'time'], $families);

        // Revoke the chat badge only: the time token keeps working, the device keeps its time badge.
        $revoker = self::getContainer()->get(ClientTokenRevoker::class);
        self::assertInstanceOf(ClientTokenRevoker::class, $revoker);
        self::assertSame(ClientSessionRevocation::Revoked, $revoker->revokeById($devices[0]->sessionsOf('chat')[0]->id, $this->user));
        self::assertSame('invalid_token', $this->authenticator()->authenticate('chat', 'Bearer ' . $chat)->error);
        $this->api('GET', '/api/time-track/v1/me', null, $time);
        self::assertResponseIsSuccessful();
        self::assertSame(['time'], $this->devices()->devicesOf($owner)[0]->scopeFamilies);
        $revoked = array_values(array_filter($this->events, static fn (ClientApiEvent $e): bool => $e->action === ClientApiAuditAction::SessionRevoked));
        self::assertSame('chat', $revoked[0]->scopeFamily ?? null);
    }

    public function testTheLinkTokenMustBeValid(): void
    {
        $body = $this->api('POST', '/api/time-track/v1/device/code', ['clientType' => 'desktop', 'family' => 'chat', 'scopes' => ['chat:read']], 'not-a-token');
        self::assertResponseStatusCodeSame(401);
        self::assertSame('invalid_token', $body['error'] ?? null);
    }

    public function testTheContextHeaderIsCheckedOnEveryRequestAndNeverStored(): void
    {
        $token = $this->login();

        $me = $this->api('GET', '/api/time-track/v1/me', null, $token);
        self::assertIsArray($me);
        self::assertArrayHasKey('context', $me);
        self::assertNull($me['context']);
        self::assertSame([['key' => 'personal', 'label' => 'Personal'], ['key' => 'org:acme', 'label' => 'Acme']], $me['contexts'] ?? null);

        $me = $this->api('GET', '/api/time-track/v1/me', null, $token, ['HTTP_X_BEACON_CONTEXT' => 'org:acme']);
        self::assertSame('org:acme', $me['context'] ?? null);
        self::assertSame('org:acme', $this->client->getRequest()->attributes->get(CurrentClientContext::ATTRIBUTE));

        foreach (['org:other', 'org acme', 'personal' . "\u{e9}"] as $context) {
            $body = $this->api('GET', '/api/time-track/v1/timer', null, $token, ['HTTP_X_BEACON_CONTEXT' => $context]);
            self::assertResponseStatusCodeSame(403, $context);
            self::assertSame('context_forbidden', $body['error'] ?? null);
        }

        // Membership revoked: the very next request is refused (nothing cached).
        $this->policy()->members = [];
        $this->api('GET', '/api/time-track/v1/me', null, $token, ['HTTP_X_BEACON_CONTEXT' => 'org:acme']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['org:acme', 'org:other', 'org:acme'], array_values(array_map(static fn (array $a): string => $a[1], array_filter($this->policy()->asked, static fn (array $a): bool => $a[1] !== 'personal'))));

        // A failing policy refuses (fail closed) and lists no context.
        $this->policy()->fail = true;
        $this->api('GET', '/api/time-track/v1/me', null, $token, ['HTTP_X_BEACON_CONTEXT' => 'personal']);
        self::assertResponseStatusCodeSame(403);
        $me = $this->api('GET', '/api/time-track/v1/me', null, $token);
        self::assertSame([], $me['contexts'] ?? null);

        // The family authenticator checks the same header, per family.
        $this->policy()->reset();
        $chat      = $this->deviceFlow(['clientType' => 'desktop', 'family' => 'chat', 'scopes' => ['chat:read']], null);
        $answer    = $this->authenticator()->authenticate('chat', 'Bearer ' . $chat, null, null, 'org:acme');
        $refusal   = $this->authenticator()->authenticate('chat', 'Bearer ' . $chat, null, null, 'org:nope');
        $principal = $answer->principal;
        self::assertNotNull($principal);
        self::assertSame('org:acme', $principal->contextKey);
        self::assertSame(['chat:read'], $principal->scopes);
        self::assertSame((string) $this->user->getId(), $principal->userId);
        self::assertSame('context_forbidden', $refusal->error);
        self::assertSame(403, $refusal->status());
        self::assertContains(['person@example.com', 'org:acme', 'chat'], $this->policy()->asked);
        self::assertSame('X-Beacon-Context', $this->authenticator()->contextHeaderName());
        self::assertSame([['key' => 'personal', 'label' => 'Personal'], ['key' => 'org:acme', 'label' => 'Acme']], $this->authenticator()->contextsOf($principal));
        self::assertTrue($this->authenticator()->revoke('chat', 'Bearer ' . $chat, '198.51.100.1'));
        self::assertFalse($this->authenticator()->revoke('chat', null));
    }

    public function testBrowsersMaySendTheContextHeader(): void
    {
        $this->client->request('OPTIONS', '/api/time-track/v1/me', [], [], ['HTTP_ORIGIN' => 'chrome-extension://functional']);
        self::assertResponseStatusCodeSame(204);
        self::assertStringContainsString('X-Beacon-Context', (string) $this->client->getResponse()->headers->get('Access-Control-Allow-Headers'));
    }

    public function testThePublicServicesAreWired(): void
    {
        $registry = self::getContainer()->get(DeviceScopeFamilyRegistry::class);
        self::assertInstanceOf(DeviceScopeFamilyRegistry::class, $registry);
        self::assertSame(['time', 'chat'], array_keys($registry->all()));
        self::assertSame('Chat', $registry->labelOf('chat'));

        $context = self::getContainer()->get(CurrentClientContext::class);
        self::assertInstanceOf(CurrentClientContext::class, $context);
        $stack = self::getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $stack);
        self::assertNull($context->key());
        $request = Request::create('/');
        $request->attributes->set(CurrentClientContext::ATTRIBUTE, 'personal');
        $stack->push($request);
        self::assertSame('personal', $context->key());
        $stack->pop();
    }

    /**
     * Runs a device flow (optionally proving an existing token of the device) and returns the token.
     *
     * @param array<string, mixed> $request
     */
    private function deviceFlow(array $request, ?string $linkToken): string
    {
        $grant   = $this->api('POST', '/api/time-track/v1/device/code', $request, $linkToken);
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
     * @param array<mixed>|null $body
     */
    private function string(?array $body, string $key): string
    {
        self::assertIsString($body[$key] ?? null, $key);

        return $body[$key];
    }

    private function policy(): StubClientContextPolicy
    {
        $policy = self::getContainer()->get(StubClientContextPolicy::class);
        self::assertInstanceOf(StubClientContextPolicy::class, $policy);

        return $policy;
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

    private function devices(): ConnectedClientDevicesQueryInterface
    {
        $devices = self::getContainer()->get(ConnectedClientDevicesQueryInterface::class);
        self::assertInstanceOf(ConnectedClientDevicesQueryInterface::class, $devices);

        return $devices;
    }

    private function sessions(): ClientAccessQueryInterface
    {
        $sessions = self::getContainer()->get(ClientAccessQueryInterface::class);
        self::assertInstanceOf(ClientAccessQueryInterface::class, $sessions);

        return $sessions;
    }
}
