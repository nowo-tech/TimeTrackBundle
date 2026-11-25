<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Client;

use InvalidArgumentException;
use Nowo\TimeTrackBundle\Client\ClientContextOption;
use Nowo\TimeTrackBundle\Client\ClientContextResolver;
use Nowo\TimeTrackBundle\Tests\Stub\StubClientContextPolicy;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function str_repeat;

/**
 * Since 2.2.0 (Beacon Desktop R4 / Q-BD-7): the explicit tenant context — off without a policy, fail closed, asked on
 * every call, never cached.
 */
final class ClientContextResolverTest extends TestCase
{
    public function testOffWithoutAPolicy(): void
    {
        $resolver = new ClientContextResolver();

        self::assertFalse($resolver->isEnabled());
        self::assertSame('X-Client-Context', $resolver->headerName());
        self::assertSame([null, null], $resolver->resolve(new InMemoryUser('ana', null), 'org:acme', 'time'));
        self::assertNull($resolver->contextsOf(new InMemoryUser('ana', null), 'time'));
    }

    public function testThePolicyDecidesOnEveryCall(): void
    {
        $policy   = new StubClientContextPolicy();
        $resolver = new ClientContextResolver($policy, 'X-Beacon-Context');
        $member   = new InMemoryUser('person@example.com', null);
        $other    = new InMemoryUser('other@example.com', null);

        self::assertTrue($resolver->isEnabled());
        self::assertSame(['org:acme', null], $resolver->resolve($member, 'org:acme', 'chat'));
        self::assertSame([null, ClientContextResolver::FORBIDDEN], $resolver->resolve($other, 'org:acme', 'chat'));
        self::assertSame([null, null], $resolver->resolve($member, null, 'time'));
        self::assertSame([null, null], $resolver->resolve($member, '', 'time'));
        self::assertSame([null, ClientContextResolver::FORBIDDEN], $resolver->resolve($member, 'org acme', 'time'));
        self::assertSame([null, ClientContextResolver::FORBIDDEN], $resolver->resolve($member, str_repeat('a', 129), 'time'));
        self::assertSame([['person@example.com', 'org:acme', 'chat'], ['other@example.com', 'org:acme', 'chat']], $policy->asked);
        self::assertSame([['key' => 'personal', 'label' => 'Personal']], $resolver->contextsOf($other, 'time'));
    }

    public function testARequiredContextAndAFailingPolicy(): void
    {
        $policy       = new StubClientContextPolicy();
        $logger       = new RecordingLogger();
        $resolver     = new ClientContextResolver($policy, 'X-Beacon-Context', true, $logger);
        $user         = new InMemoryUser('person@example.com', null);
        $policy->fail = true;

        self::assertSame([null, ClientContextResolver::REQUIRED], $resolver->resolve($user, null, 'time'));
        self::assertSame([null, ClientContextResolver::FORBIDDEN], $resolver->resolve($user, 'personal', 'time'));
        self::assertSame([], $resolver->contextsOf($user, 'time'));
        self::assertNotEmpty($logger->records);
    }

    public function testAnOptionIsValidated(): void
    {
        $option = new ClientContextOption('org:acme', 'Acme');
        self::assertSame(['key' => 'org:acme', 'label' => 'Acme'], $option->toArray());

        foreach ([['', 'x'], ['a b', 'x'], ['ok', ''], ['ok', "a\tb"], ['ok', str_repeat('x', 201)]] as [$key, $label]) {
            try {
                new ClientContextOption($key, $label);
                self::fail('refused');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
