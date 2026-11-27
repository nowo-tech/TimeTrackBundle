<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Client;

use Nowo\TimeTrackBundle\Client\ClientCapabilitiesProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientCapabilitiesResolver;
use Nowo\TimeTrackBundle\Client\ClientContextResolver;
use Nowo\TimeTrackBundle\Client\ClientFamilyAuthentication;
use Nowo\TimeTrackBundle\Client\ConfigDeviceScopeFamilyProvider;
use Nowo\TimeTrackBundle\Client\DeviceScopeFamilyRegistry;
use Nowo\TimeTrackBundle\Tests\Stub\StubClientCapabilitiesProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubClientContextPolicy;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Since 2.4.0 (Beacon spec 262 block B): the modules a person may use, from the host provider over the registered
 * families; fail closed, nothing kept between calls.
 */
final class ClientCapabilitiesResolverTest extends TestCase
{
    public function testWithoutAProviderNothingIsEnforced(): void
    {
        $resolver = new ClientCapabilitiesResolver();

        self::assertFalse($resolver->isEnforced());
        self::assertTrue($resolver->canUse(new TestUser('1', 'a'), 'chat', null));
        self::assertTrue($resolver->isRegistered('time'));
        self::assertFalse($resolver->isRegistered('chat'));
        self::assertSame([
            'context'         => null,
            'modules'         => ['time' => true, 'chat' => false, 'tasks' => false],
            'grantableScopes' => ['time' => DeviceScopeFamilyRegistry::timeFamily()->scopeValues()],
        ], $resolver->capabilitiesOf(new TestUser('1', 'a'), 'time', null));
        self::assertTrue($resolver->canGrant(new TestUser('1', 'a'), 'chat'));
        self::assertTrue($resolver->canUseInRequest(new TestUser('1', 'a'), 'chat', null));
    }

    public function testTheProviderDecidesPerFamilyAndContext(): void
    {
        $provider                  = new StubClientCapabilitiesProvider();
        $provider->allowed         = ['chat', 'voice'];
        $provider->deniedInContext = 'org:x';
        $resolver                  = new ClientCapabilitiesResolver($provider, $this->families());

        self::assertTrue($resolver->isEnforced());
        self::assertSame([
            'context'         => 'personal',
            'modules'         => ['time' => false, 'chat' => true, 'tasks' => false, 'voice' => true],
            'grantableScopes' => ['chat' => ['chat:read'], 'voice' => ['voice:call']],
        ], $resolver->capabilitiesOf(new TestUser('1', 'a'), 'chat', 'personal'));
        self::assertFalse($resolver->canUse(new TestUser('1', 'a'), 'chat', 'org:x'));
        self::assertSame(['a', 'chat', 'org:x'], $provider->asked[3]);
    }

    /** Audit ZDB-L1: grant time asks ANY_CONTEXT; a request without a header asks the policy's default context. */
    public function testGrantAndRequestContextsAreDistinct(): void
    {
        $provider = new StubClientCapabilitiesProvider();
        $policy   = new StubClientContextPolicy();
        $resolver = new ClientCapabilitiesResolver($provider, new DeviceScopeFamilyRegistry(), null, new ClientContextResolver($policy));
        $user     = new TestUser('1', 'person@example.com');

        self::assertTrue($resolver->canGrant($user, 'time'));
        self::assertSame(['person@example.com', 'time', ClientCapabilitiesProviderInterface::ANY_CONTEXT], $provider->asked[0]);
        self::assertSame('personal', $resolver->effectiveContext($user, 'time', null));
        self::assertSame('org:acme', $resolver->effectiveContext($user, 'time', 'org:acme'));

        $provider->deniedInContext = 'personal';
        self::assertFalse($resolver->canUseInRequest($user, 'time', null));
        self::assertTrue($resolver->canUseInRequest($user, 'time', 'org:acme'));
        self::assertTrue($resolver->canGrant($user, 'time'));

        // The policy lists nothing: no default context, refused without asking the provider.
        $policy->fail    = true;
        $provider->asked = [];
        self::assertNull($resolver->effectiveContext($user, 'time', null));
        self::assertFalse($resolver->canUseInRequest($user, 'time', null));
        self::assertSame([], $provider->asked);

        // Without a context policy a request asks with null.
        $plain = new ClientCapabilitiesResolver($provider);
        self::assertTrue($plain->canUseInRequest($user, 'time', null));
        self::assertSame([['person@example.com', 'time', null]], $provider->asked);
    }

    public function testAFailingProviderRefusesAndLogs(): void
    {
        $provider       = new StubClientCapabilitiesProvider();
        $provider->fail = true;
        $logger         = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };
        $resolver = new ClientCapabilitiesResolver($provider, new DeviceScopeFamilyRegistry(), $logger);

        self::assertFalse($resolver->canUse(new TestUser('1', 'a'), 'time', null));
        self::assertSame(['TimeTrack client capabilities provider failed.'], $logger->messages);
    }

    public function testOnlyTrueGrants(): void
    {
        $provider = new class implements ClientCapabilitiesProviderInterface {
            public function canUse(TimeTrackUserRef $user, string $scopeFamily, ?string $context): bool
            {
                return $scopeFamily === 'time';
            }
        };
        $resolver = new ClientCapabilitiesResolver($provider);

        self::assertTrue($resolver->canUse(new TestUser('1', 'a'), 'time', null));
        self::assertFalse($resolver->canUse(new TestUser('1', 'a'), 'chat', null));
    }

    public function testTheFamilyAuthenticationBodyNamesTheModule(): void
    {
        self::assertSame(['module' => 'chat'], ClientFamilyAuthentication::refused('module_forbidden', 0, 'chat')->body());
        self::assertSame(403, ClientFamilyAuthentication::refused('module_forbidden', 0, 'chat')->status());
        self::assertSame([], ClientFamilyAuthentication::refused('invalid_token')->body());
    }

    private function families(): DeviceScopeFamilyRegistry
    {
        return new DeviceScopeFamilyRegistry([new ConfigDeviceScopeFamilyProvider([
            'chat'  => ['label' => 'Chat', 'scopes' => ['chat:read' => 'Read']],
            'voice' => ['label' => 'Voice', 'scopes' => ['voice:call' => 'Call']],
        ])]);
    }
}
