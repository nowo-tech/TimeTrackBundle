<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TimeTrackBundle\Client\ClientAuthenticatorInterface;
use Nowo\TimeTrackBundle\Client\ClientAuthResult;
use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Tests\Stub\MutableRoleUser;
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

    public function testResolveUserReloadsUserSoSecondRequestSeesChangedRoles(): void
    {
        $user  = new MutableRoleUser('1', ['ROLE_ADMIN']);
        $plain = 'worker-token';
        $this->repository->save(new ClientToken(
            ClientAuthService::hashToken($plain),
            new DateTimeImmutable('+1 hour'),
            $user,
            ClientType::Desktop,
        ));

        $databaseRoles = ['ROLE_ADMIN'];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturn(true);
        $entityManager->method('contains')->willReturn(true);
        $entityManager->method('refresh')->willReturnCallback(static function (MutableRoleUser $refreshed) use (&$databaseRoles): void {
            $refreshed->roles = $databaseRoles;
        });

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($entityManager);

        $service = new ClientAuthService(
            $this->createMock(ClientAuthenticatorInterface::class),
            $this->repository,
            3600,
            $registry,
        );

        $firstRequestUser = $service->resolveUser($plain);
        self::assertInstanceOf(MutableRoleUser::class, $firstRequestUser);
        self::assertSame(['ROLE_ADMIN'], $firstRequestUser->getRoles());

        $databaseRoles = ['ROLE_USER'];

        $secondRequestUser = $service->resolveUser($plain);
        self::assertInstanceOf(MutableRoleUser::class, $secondRequestUser);
        self::assertSame(['ROLE_USER'], $secondRequestUser->getRoles());
    }
}
