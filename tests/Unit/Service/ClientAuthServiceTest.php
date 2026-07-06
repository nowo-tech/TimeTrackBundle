<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Client\ClientAuthenticatorInterface;
use Nowo\TimeTrackBundle\Client\ClientAuthResult;
use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryClientTokenRepository;
use PHPUnit\Framework\TestCase;

final class ClientAuthServiceTest extends TestCase
{
    private InMemoryClientTokenRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new InMemoryClientTokenRepository();
    }

    public function testLoginIssuesToken(): void
    {
        $user          = new TestUser('1', 'u@example.com');
        $authenticator = $this->createMock(ClientAuthenticatorInterface::class);
        $authenticator->method('authenticate')->willReturn(ClientAuthResult::success($user));

        $service = new ClientAuthService($authenticator, $this->repository, 3600);
        $result  = $service->login('u@example.com', 'secret', ClientType::Extension);

        self::assertIsArray($result);
        self::assertNotEmpty($result['token']);
        self::assertNotEmpty($result['expiresAt']);
    }

    public function testLoginReturnsNullOnFailure(): void
    {
        $authenticator = $this->createMock(ClientAuthenticatorInterface::class);
        $authenticator->method('authenticate')->willReturn(ClientAuthResult::failure());

        $service = new ClientAuthService($authenticator, $this->repository, 3600);

        self::assertNull($service->login('u@example.com', 'bad', ClientType::Web));
    }

    public function testResolveUserFromValidToken(): void
    {
        $user  = new TestUser('1', 'u@example.com');
        $plain = 'test-token-value';
        $this->repository->save(new ClientToken(
            ClientAuthService::hashToken($plain),
            new DateTimeImmutable('+1 hour'),
            $user,
            ClientType::Desktop,
        ));

        $service = new ClientAuthService(
            $this->createMock(ClientAuthenticatorInterface::class),
            $this->repository,
            3600,
        );

        self::assertSame($user, $service->resolveUser($plain));
    }

    public function testLogoutRemovesToken(): void
    {
        $user  = new TestUser('1', 'u@example.com');
        $plain = 'logout-token';
        $this->repository->save(new ClientToken(
            ClientAuthService::hashToken($plain),
            new DateTimeImmutable('+1 hour'),
            $user,
            ClientType::Web,
        ));

        $service = new ClientAuthService(
            $this->createMock(ClientAuthenticatorInterface::class),
            $this->repository,
            3600,
        );

        $service->logout($plain);

        self::assertNull($service->resolveUser($plain));
    }

    public function testHashTokenIsDeterministic(): void
    {
        self::assertSame(
            ClientAuthService::hashToken('abc'),
            ClientAuthService::hashToken('abc'),
        );
    }

    public function testResolveUserSkipsTouchWhenRecentlyUsed(): void
    {
        $user  = new TestUser('1', 'u@example.com');
        $plain = 'recent-token';
        $token = new ClientToken(
            ClientAuthService::hashToken($plain),
            new DateTimeImmutable('+1 hour'),
            $user,
            ClientType::Web,
        );
        $token->touch();
        $this->repository->save($token);

        $service = new ClientAuthService(
            $this->createMock(ClientAuthenticatorInterface::class),
            $this->repository,
            3600,
        );

        self::assertSame($user, $service->resolveUser($plain));
    }

    public function testResolveUserReturnsNullForNonUserInterfaceOwner(): void
    {
        $plain = 'bad-user-token';
        $this->repository->save(new ClientToken(
            ClientAuthService::hashToken($plain),
            new DateTimeImmutable('+1 hour'),
            new class {
                public function getId(): string
                {
                    return 'x';
                }
            },
            ClientType::Web,
        ));

        $service = new ClientAuthService(
            $this->createMock(ClientAuthenticatorInterface::class),
            $this->repository,
            3600,
        );

        self::assertNull($service->resolveUser($plain));
    }

    public function testLoginUsesMinimumTokenTtl(): void
    {
        $user          = new TestUser('1', 'u@example.com');
        $authenticator = $this->createMock(ClientAuthenticatorInterface::class);
        $authenticator->method('authenticate')->willReturn(ClientAuthResult::success($user));

        $service = new ClientAuthService($authenticator, $this->repository, 0);
        $result  = $service->login('u@example.com', 'secret', ClientType::Web);

        self::assertIsArray($result);
    }
}
