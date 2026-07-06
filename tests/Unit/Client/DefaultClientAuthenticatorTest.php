<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Client;

use Nowo\TimeTrackBundle\Client\DefaultClientAuthenticator;
use Nowo\TimeTrackBundle\Tests\Stub\TestPasswordUser;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserProviderInterface;

final class DefaultClientAuthenticatorTest extends TestCase
{
    public function testAuthenticatesValidCredentials(): void
    {
        $user           = new TestPasswordUser('1', 'demo@example.com');
        $userProvider   = $this->createMock(UserProviderInterface::class);
        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);

        $userProvider->method('loadUserByIdentifier')->willReturn($user);
        $passwordHasher->method('isPasswordValid')->willReturn(true);

        $authenticator = new DefaultClientAuthenticator($userProvider, $passwordHasher);
        $result        = $authenticator->authenticate('demo@example.com', 'secret');

        self::assertNotNull($result);
        self::assertTrue($result->isSuccess());
    }

    public function testReturnsNullWhenUserNotFound(): void
    {
        $userProvider = $this->createMock(UserProviderInterface::class);
        $userProvider->method('loadUserByIdentifier')->willThrowException(new UserNotFoundException());

        $authenticator = new DefaultClientAuthenticator(
            $userProvider,
            $this->createMock(UserPasswordHasherInterface::class),
        );

        self::assertNull($authenticator->authenticate('missing', 'secret'));
    }

    public function testReturnsNullWhenPasswordInvalid(): void
    {
        $user           = new TestPasswordUser('1', 'demo@example.com');
        $userProvider   = $this->createMock(UserProviderInterface::class);
        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);

        $userProvider->method('loadUserByIdentifier')->willReturn($user);
        $passwordHasher->method('isPasswordValid')->willReturn(false);

        $authenticator = new DefaultClientAuthenticator($userProvider, $passwordHasher);

        self::assertNull($authenticator->authenticate('demo@example.com', 'wrong'));
    }

    public function testReturnsNullWhenUserHasNoPasswordInterface(): void
    {
        $user         = new TestUser('1', 'demo@example.com');
        $userProvider = $this->createMock(UserProviderInterface::class);
        $userProvider->method('loadUserByIdentifier')->willReturn($user);

        $authenticator = new DefaultClientAuthenticator(
            $userProvider,
            $this->createMock(UserPasswordHasherInterface::class),
        );

        self::assertNull($authenticator->authenticate('demo@example.com', 'secret'));
    }
}
