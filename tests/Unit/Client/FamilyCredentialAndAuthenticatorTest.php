<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Client;

use LogicException;
use Nowo\TimeTrackBundle\Client\AlwaysActiveClientCredentialState;
use Nowo\TimeTrackBundle\Client\ClientAccountChecker;
use Nowo\TimeTrackBundle\Client\ClientApiGuard;
use Nowo\TimeTrackBundle\Client\ClientAuthenticatorInterface;
use Nowo\TimeTrackBundle\Client\ClientContextResolver;
use Nowo\TimeTrackBundle\Client\ClientCredentialMinterInterface;
use Nowo\TimeTrackBundle\Client\ClientCredentialStateInterface;
use Nowo\TimeTrackBundle\Client\ClientFamilyTokenAuthenticator;
use Nowo\TimeTrackBundle\Client\ClientIdempotencyStore;
use Nowo\TimeTrackBundle\Client\ClientResponseFactory;
use Nowo\TimeTrackBundle\Client\ConfigDeviceScopeFamilyProvider;
use Nowo\TimeTrackBundle\Client\DeviceCredentialRequest;
use Nowo\TimeTrackBundle\Client\DeviceScopeFamilyRegistry;
use Nowo\TimeTrackBundle\Client\DeviceScopeRequest;
use Nowo\TimeTrackBundle\Client\OpaqueClientCredentialIssuer;
use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Entity\DeviceAuthorization;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Exception\DeviceAuthorizationRefusedException;
use Nowo\TimeTrackBundle\Port\ClientCredentialMinterGate;
use Nowo\TimeTrackBundle\Port\Internal\TaskProviderInterface;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefs;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Service\DeviceAuthorizationService;
use Nowo\TimeTrackBundle\Tests\Stub\RecordingFamilyCredentialMinter;
use Nowo\TimeTrackBundle\Tests\Stub\StubClientContextPolicy;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\ClientApiStack;
use Nowo\TimeTrackBundle\Tests\Support\SwitchableUserChecker;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Since 2.2.0: the family-aware credential issuers, the device key a device proves on `device/code`, the family
 * authenticator's wiring errors and the time logout that leaves other families alone.
 */
final class FamilyCredentialAndAuthenticatorTest extends TestCase
{
    public function testTheGateUsesTheFamilyMinterOrFallsBackForTimeOnly(): void
    {
        $user    = new TestUser('u-1', 'u@example.com');
        $request = static fn (string $family): DeviceCredentialRequest => new DeviceCredentialRequest(TimeTrackUserRefs::fromUser($user), ClientType::Desktop, 'Laptop', $family, $family === 'time' ? ['timer:read'] : ['chat:read'], 'dev-1');

        $family = new RecordingFamilyCredentialMinter();
        self::assertSame('cred-1', (new ClientCredentialMinterGate($family))->issueForFamily($user, $request('chat')));
        self::assertSame('dev-1', $family->requests[0]->deviceKey);

        $plain = new class implements ClientCredentialMinterInterface {
            /** @var list<list<ClientScope>> */
            public array $scopes = [];

            public function mintForDevice(TimeTrackUserRef $user, ClientType $clientType, string $clientName, array $scopes): string
            {
                $this->scopes[] = $scopes;

                return 'plain';
            }
        };
        self::assertSame('plain', (new ClientCredentialMinterGate($plain))->issueForFamily($user, $request('time')));
        self::assertSame([[ClientScope::TimerRead]], $plain->scopes);

        $this->expectException(LogicException::class);
        (new ClientCredentialMinterGate($plain))->issueForFamily($user, $request('chat'));
    }

    public function testTheOpaqueIssuerMintsTimeCredentialsOnly(): void
    {
        $user = new TestUser('u-1', 'u@example.com');

        self::assertStringStartsWith('device:', (new OpaqueClientCredentialIssuer())->issueForFamily($user, new DeviceCredentialRequest(TimeTrackUserRefs::fromUser($user), ClientType::Desktop, 'L', 'time', ['timer:read'], 'k')));
        $this->expectException(LogicException::class);
        (new OpaqueClientCredentialIssuer())->issueForFamily($user, new DeviceCredentialRequest(TimeTrackUserRefs::fromUser($user), ClientType::Desktop, 'L', 'chat', ['chat:read'], 'k'));
    }

    public function testTheDeviceKeyOfALinkTokenNeedsAnActiveAccountAndCredential(): void
    {
        $user  = new TestUser('u-1', 'u@example.com');
        $state = new class implements ClientCredentialStateInterface {
            public bool $active = true;

            public function isActive(ClientToken $token): bool
            {
                return $this->active;
            }
        };
        $stack = new ClientApiStack($this->createStub(ClientAuthenticatorInterface::class), $this->createStub(TaskProviderInterface::class), credentialState: $state);
        $old   = $stack->authService->issueToken($user, ClientType::Desktop, 'c1');
        $new   = $stack->authService->issueFamilyToken($user, ClientType::Desktop, 'c2', 'chat', ['chat:read'], 'dev-9', 'Laptop');

        self::assertNull($stack->guard->deviceLinkOf('unknown', null));
        $tokenId = $stack->tokens->findValidByTokenHash(ClientAuthService::hashToken($old['token']))?->getId();
        self::assertSame(['deviceKey' => $tokenId, 'ownerId' => 'u-1'], $stack->guard->deviceLinkOf($old['token'], null), 'a token issued without a device key is its own device');
        self::assertSame(['deviceKey' => 'dev-9', 'ownerId' => 'u-1'], $stack->guard->deviceLinkOf($new['token'], null));

        $state->active = false;
        self::assertNull($stack->guard->deviceLinkOf($new['token'], null));

        $checker = new SwitchableUserChecker();
        $checker->refuse('u@example.com');
        $guard = new ClientApiGuard($stack->authService, $stack->responses, $stack->rateLimiter, $stack->settings, $state, $stack->auditor, new ClientAccountChecker($checker, $stack->tokens, false));
        self::assertNull($guard->deviceLinkOf($new['token'], null));
    }

    public function testTheTimeLogoutLeavesAnotherFamilysTokenAlone(): void
    {
        $user  = new TestUser('u-1', 'u@example.com');
        $stack = new ClientApiStack($this->createStub(ClientAuthenticatorInterface::class), $this->createStub(TaskProviderInterface::class));
        $chat  = $stack->authService->issueFamilyToken($user, ClientType::Desktop, 'c2', 'chat', ['chat:read']);

        self::assertNull($stack->authService->logout($chat['token'], 'time'));
        self::assertInstanceOf(ClientToken::class, $stack->authService->logout($chat['token'], 'chat'));

        $time = $stack->authService->issueToken($user, ClientType::Desktop);
        self::assertSame('time', $time['scopeFamily']);
        $chat     = $stack->authService->issueFamilyToken($user, ClientType::Desktop, 'c3', 'chat', ['chat:read']);
        $response = $stack->guard->authenticate(Request::create('/api/time-track/me', 'GET', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $chat['token']]));
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(403, $response->getStatusCode());
    }

    public function testARequiredContextIsAskedForOnTheTimeApi(): void
    {
        $user     = new TestUser('u-1', 'u@example.com');
        $stack    = new ClientApiStack($this->createStub(ClientAuthenticatorInterface::class), $this->createStub(TaskProviderInterface::class));
        $contexts = new ClientContextResolver(new StubClientContextPolicy(), 'X-Beacon-Context', true);
        $guard    = new ClientApiGuard($stack->authService, $stack->responses, $stack->rateLimiter, $stack->settings, new AlwaysActiveClientCredentialState(), $stack->auditor, null, $contexts);
        $time     = $stack->authService->issueToken($user, ClientType::Desktop);

        $response = $guard->authenticate(Request::create('/api/time-track/me', 'GET', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $time['token']]));
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('context_required', (string) $response->getContent());
        self::assertStringContainsString('X-Beacon-Context', (string) $response->getContent());
    }

    public function testTheAuthenticatorRefusesAnUnregisteredFamilyOrScope(): void
    {
        $stack    = new ClientApiStack($this->createStub(ClientAuthenticatorInterface::class), $this->createStub(TaskProviderInterface::class));
        $families = new DeviceScopeFamilyRegistry([new ConfigDeviceScopeFamilyProvider(['chat' => ['label' => 'Chat', 'scopes' => ['chat:read' => 'Read']]])]);
        $auth     = new ClientFamilyTokenAuthenticator($stack->guard, $families, $stack->authService, $stack->auditor);

        self::assertSame('X-Client-Context', $auth->contextHeaderName());
        foreach ([['time', null], ['voice', null], ['chat', 'chat:write'], ['chat', 'timer:read']] as [$family, $scope]) {
            try {
                $auth->authenticate($family, 'Bearer x', $scope);
                self::fail('refused');
            } catch (LogicException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(LogicException::class);
        $auth->revoke('time', 'Bearer x');
    }

    public function testTheCorsHeadersNameTheContextHeader(): void
    {
        $request = Request::create('/api/time-track/me', 'OPTIONS', server: ['HTTP_ORIGIN' => 'chrome-extension://unit']);

        self::assertSame('Authorization, Content-Type, Idempotency-Key, X-Beacon-Context', (new ClientResponseFactory(['chrome-extension://unit'], contextHeader: 'X-Beacon-Context'))->empty(204, $request)->headers->get('Access-Control-Allow-Headers'));
        self::assertSame('Authorization, Content-Type, Idempotency-Key', (new ClientResponseFactory(['chrome-extension://unit']))->empty(204, $request)->headers->get('Access-Control-Allow-Headers'));
        // Audit ZBV-I4: the answer varies by the context header (with or without CORS).
        self::assertSame(['X-Beacon-Context', 'Origin'], (new ClientResponseFactory(['chrome-extension://unit'], contextHeader: 'X-Beacon-Context'))->json([], 200, $request)->getVary());
        self::assertSame(['X-Beacon-Context'], (new ClientResponseFactory([], contextHeader: 'X-Beacon-Context'))->json([], 200, Request::create('/'))->getVary());
        self::assertSame([], (new ClientResponseFactory([]))->json([], 200, Request::create('/'))->getVary());
    }

    public function testTheIdempotencyHashIsBoundToTheContext(): void
    {
        self::assertSame('h', ClientIdempotencyStore::contextHash('h', null));
        self::assertNotSame(ClientIdempotencyStore::contextHash('h', 'org:a'), ClientIdempotencyStore::contextHash('h', 'org:b'));
        self::assertNotSame('h', ClientIdempotencyStore::contextHash('h', 'personal'));
    }

    public function testAFamilyNoLongerRegisteredIsNeitherApprovedNorExchanged(): void
    {
        $user    = new TestUser('u-1', 'u@example.com');
        $minter  = new RecordingFamilyCredentialMinter();
        $stack   = new ClientApiStack($this->createStub(ClientAuthenticatorInterface::class), $this->createStub(TaskProviderInterface::class));
        $chat    = new DeviceScopeFamilyRegistry([new ConfigDeviceScopeFamilyProvider(['chat' => ['label' => 'Chat', 'scopes' => ['chat:read' => 'Read']]])]);
        $with    = new DeviceAuthorizationService($stack->devices, new ClientCredentialMinterGate($minter), $stack->authService, $stack->auditor, $stack->rateLimiter, clock: $stack->clock, families: $chat);
        $without = new DeviceAuthorizationService($stack->devices, new ClientCredentialMinterGate($minter), $stack->authService, $stack->auditor, $stack->rateLimiter, clock: $stack->clock);

        // Unregistered at approval: refused and denied, nothing minted.
        $grant   = $with->startFamily(ClientType::Desktop, 'L', new DeviceScopeRequest('chat', ['chat:read']));
        $pending = $with->findPending($grant->userCode, $user);
        self::assertInstanceOf(DeviceAuthorization::class, $pending);
        self::assertTrue($with->isFamilyAvailable($pending));
        self::assertFalse($without->isFamilyAvailable($pending));
        try {
            $without->approve($pending, $user);
            self::fail('refused');
        } catch (DeviceAuthorizationRefusedException $e) {
            self::assertSame(DeviceAuthorizationRefusedException::FAMILY_UNAVAILABLE, $e->reason);
        }
        self::assertSame([], $minter->requests);
        self::assertSame('access_denied', $with->poll($grant->deviceCode)->error);

        // A scope removed from the family counts as unavailable too.
        $narrow = new DeviceScopeFamilyRegistry([new ConfigDeviceScopeFamilyProvider(['chat' => ['label' => 'Chat', 'scopes' => ['chat:write' => 'Send']]])]);
        self::assertFalse((new DeviceAuthorizationService($stack->devices, new ClientCredentialMinterGate($minter), $stack->authService, $stack->auditor, $stack->rateLimiter, families: $narrow))->isFamilyAvailable($pending));

        // Unregistered after the approval: no token.
        $grant   = $with->startFamily(ClientType::Desktop, 'L', new DeviceScopeRequest('chat', ['chat:read']));
        $pending = $with->findPending($grant->userCode, $user);
        self::assertInstanceOf(DeviceAuthorization::class, $pending);
        $with->approve($pending, $user);
        self::assertSame('access_denied', $without->poll($grant->deviceCode)->error);
    }

    public function testOnlyTheOwnerOfTheLinkedTokenMayApprove(): void
    {
        $owner   = new TestUser('u-1', 'a@example.com');
        $other   = new TestUser('u-2', 'b@example.com');
        $minter  = new RecordingFamilyCredentialMinter();
        $stack   = new ClientApiStack($this->createStub(ClientAuthenticatorInterface::class), $this->createStub(TaskProviderInterface::class));
        $chat    = new DeviceScopeFamilyRegistry([new ConfigDeviceScopeFamilyProvider(['chat' => ['label' => 'Chat', 'scopes' => ['chat:read' => 'Read']]])]);
        $devices = new DeviceAuthorizationService($stack->devices, new ClientCredentialMinterGate($minter), $stack->authService, $stack->auditor, $stack->rateLimiter, clock: $stack->clock, families: $chat);

        $grant   = $devices->startFamily(ClientType::Desktop, 'L', new DeviceScopeRequest('chat', ['chat:read']), null, 'dev-a', 'u-1');
        $pending = $devices->findPending($grant->userCode, $other);
        self::assertInstanceOf(DeviceAuthorization::class, $pending);
        self::assertSame('u-1', $pending->getLinkOwnerId());
        try {
            $devices->approve($pending, $other);
            self::fail('refused');
        } catch (DeviceAuthorizationRefusedException $e) {
            self::assertSame(DeviceAuthorizationRefusedException::LINK_MISMATCH, $e->reason);
        }
        self::assertSame([], $minter->requests);
        self::assertSame('access_denied', $devices->poll($grant->deviceCode)->error);

        // A link owner without a device key is ignored (no link).
        $grant = $devices->startFamily(ClientType::Desktop, 'L', new DeviceScopeRequest('chat', ['chat:read']), null, null, 'u-1');
        $mine  = $devices->findPending($grant->userCode, $owner);
        self::assertInstanceOf(DeviceAuthorization::class, $mine);
        self::assertNull($mine->getLinkOwnerId());
        $devices->approve($mine, $other);
        self::assertCount(1, $minter->requests);
    }
}
