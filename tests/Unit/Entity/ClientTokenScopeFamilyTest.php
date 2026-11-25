<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Entity;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Entity\DeviceAuthorization;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\ClientType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Since 2.2.0: a token or device request of a host family carries that family's scope values and no time scope; a
 * `time` one behaves as before.
 */
final class ClientTokenScopeFamilyTest extends TestCase
{
    public function testATimeTokenIsUnchanged(): void
    {
        $token = new ClientToken('h', new DateTimeImmutable('+1 hour'), new InMemoryUser('ana', null), ClientType::Desktop, null, 'c', ['timer:read', 'chat:read']);

        self::assertSame('time', $token->getScopeFamily());
        self::assertTrue($token->isTimeFamily());
        self::assertSame([ClientScope::TimerRead], $token->getScopes());
        self::assertSame(['timer:read'], $token->getScopeValues());
        self::assertTrue($token->hasScopeValue('timer:read'));
        self::assertNull($token->getDeviceKey());
        self::assertNull($token->getClientName());
        self::assertSame(ClientScope::values(ClientScope::defaults()), (new ClientToken('h2', new DateTimeImmutable('+1 hour'), new InMemoryUser('ana', null), ClientType::Desktop))->getScopeValues());
    }

    public function testAFamilyTokenHasNoTimeScope(): void
    {
        $token = new ClientToken('h', new DateTimeImmutable('+1 hour'), new InMemoryUser('ana', null), ClientType::Desktop, null, 'c', ['chat:read', 'chat:read', 'timer:read', 'bad', ClientScope::TimerWrite], 'chat', 'dev-1', 'Laptop');

        self::assertSame('chat', $token->getScopeFamily());
        self::assertFalse($token->isTimeFamily());
        self::assertSame([], $token->getScopes());
        self::assertFalse($token->hasScope(ClientScope::TimerRead));
        self::assertSame(['chat:read', 'timer:read'], $token->getScopeValues());
        self::assertTrue($token->hasScopeValue('chat:read'));
        self::assertSame('dev-1', $token->getDeviceKey());
        self::assertSame('Laptop', $token->getClientName());
        self::assertSame([], (new ClientToken('h3', new DateTimeImmutable('+1 hour'), new InMemoryUser('ana', null), ClientType::Desktop, null, null, null, 'chat'))->getScopeValues());
    }

    public function testADeviceRequestKeepsItsFamilyAndDeviceKey(): void
    {
        $now = new DateTimeImmutable();
        // @phpstan-ignore argument.type (a malformed value is dropped)
        $chat = new DeviceAuthorization('d', 'u', ClientType::Desktop, 'Laptop', ['chat:read', 'chat:read', 7], $now->modify('+10 minutes'), $now, null, 'chat', 'dev-1');
        $time = new DeviceAuthorization('d2', 'u2', ClientType::Desktop, 'Laptop', [ClientScope::TimerRead], $now->modify('+10 minutes'), $now);
        $user = new InMemoryUser('ana', null);

        self::assertSame('chat', $chat->getScopeFamily());
        self::assertSame([], $chat->getScopes());
        self::assertSame(['chat:read'], $chat->getScopeValues());
        self::assertSame('dev-1', $chat->getDeviceKey());
        $chat->approve($user, 'cred');
        self::assertSame('dev-1', $chat->getDeviceKey());

        self::assertSame('time', $time->getScopeFamily());
        self::assertSame(['timer:read'], $time->getScopeValues());
        self::assertNull($time->getDeviceKey());
        $time->approve($user, 'cred', 'dev-2');
        self::assertSame('dev-2', $time->getDeviceKey());
    }
}
