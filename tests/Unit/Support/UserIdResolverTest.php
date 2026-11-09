<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Support;

use LogicException;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\Tests\Fixtures\DddHost\SecurityUser;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use stdClass;
use Stringable;
use Symfony\Component\Security\Core\User\UserInterface;

final class UserIdResolverTest extends TestCase
{
    public function testResolvesUserId(): void
    {
        self::assertSame('42', UserIdResolver::getId(new TestUser('42', 'u@example.com')));
    }

    public function testThrowsWhenGetIdMissing(): void
    {
        $user = new class implements UserInterface {
            public function getRoles(): array
            {
                return [];
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return 'anon';
            }
        };

        $this->expectException(LogicException::class);
        UserIdResolver::getId($user);
    }

    public function testIdOfReadsAStoredOwner(): void
    {
        self::assertSame('42', UserIdResolver::idOf(new TestUser('42', 'u@example.com')));
        self::assertSame('7', UserIdResolver::idOf(new SecurityUser(7)));
        self::assertSame('0190-id', UserIdResolver::idOf(new SecurityUser(new class implements Stringable {
            public function __toString(): string
            {
                return '0190-id';
            }
        })));
    }

    public function testIdOfIsNullWithoutAUsableId(): void
    {
        self::assertNull(UserIdResolver::idOf(null));
        self::assertNull(UserIdResolver::idOf(new stdClass()));
        self::assertNull(UserIdResolver::idOf(new SecurityUser(null)));
        self::assertNull(UserIdResolver::idOf(new SecurityUser(new stdClass())));
    }
}
