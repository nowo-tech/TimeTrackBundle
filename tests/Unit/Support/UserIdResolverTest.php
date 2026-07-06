<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Support;

use LogicException;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
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
}
