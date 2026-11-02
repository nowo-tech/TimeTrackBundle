<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\ClientApi;

use Nowo\TimeTrackBundle\Client\ClientAuthenticatorInterface;
use Nowo\TimeTrackBundle\Client\ClientCredentialIssuerInterface;
use Nowo\TimeTrackBundle\Dto\DevicePollResult;
use Nowo\TimeTrackBundle\Entity\DeviceAuthorization;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Exception\DeviceCodeAttemptsExceededException;
use Nowo\TimeTrackBundle\Integration\TaskProviderInterface;
use Nowo\TimeTrackBundle\Service\DeviceAuthorizationService;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\ClientApiStack;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\UserInterface;

final class DeviceAuthorizationServiceTest extends TestCase
{
    public function testExpiredRequestsAreRemovedAndApprovedOnesExchangeOnce(): void
    {
        $stack   = new ClientApiStack($this->createStub(ClientAuthenticatorInterface::class), $this->createStub(TaskProviderInterface::class));
        $service = $stack->deviceService;
        $user    = new TestUser('u-1', 'u@example.com');

        $expired = $service->start(ClientType::Desktop, str_repeat('n', 300));
        $stack->clock->sleep(601);
        self::assertSame(DevicePollResult::EXPIRED, $service->poll($expired->deviceCode)->error);
        self::assertSame([], $stack->devices->all());
        self::assertSame(DevicePollResult::EXPIRED, $service->poll('')->error);

        $grant         = $service->start(ClientType::Extension, 'Browser');
        $authorization = $service->findPending(strtolower(str_replace('-', ' ', $grant->userCode)), $user);
        self::assertInstanceOf(DeviceAuthorization::class, $authorization);
        self::assertSame($authorization, $service->findPendingById($authorization->getId()));
        self::assertNull($service->findPendingById(''));
        self::assertSame('nowo_time_track_device/approve/' . $authorization->getId(), $service->csrfTokenId('approve', $authorization));
        $service->approve($authorization, $user);

        self::assertTrue($stack->devices->markConsumed($authorization), 'a concurrent exchange won');
        self::assertSame(DevicePollResult::EXPIRED, $service->poll($grant->deviceCode)->error);
    }

    public function testUserCodeHelpersAndLookupBudget(): void
    {
        self::assertSame('BCDFGHJK', DeviceAuthorizationService::normalizeUserCode(' bcdf-ghjk '));
        self::assertSame('BCDF-GHJK', DeviceAuthorizationService::formatUserCode('BCDFGHJK'));

        $stack = new ClientApiStack($this->createStub(ClientAuthenticatorInterface::class), $this->createStub(TaskProviderInterface::class));
        $user  = new TestUser('u-1', 'u@example.com');
        for ($i = 0; $i < 3; ++$i) {
            self::assertNull($stack->deviceService->findPending('BCDF-GHJK', $user));
        }

        $this->expectException(DeviceCodeAttemptsExceededException::class);
        $stack->deviceService->findPending('BCDF-GHJK', $user);
    }

    public function testApproveHandsTheDeclaredClientTypeToTheHostIssuer(): void
    {
        $issuer = new class implements ClientCredentialIssuerInterface {
            /** @var list<array{ClientType, string}> */
            public array $calls = [];

            public function issueForDevice(UserInterface $user, ClientType $clientType, string $clientName, array $scopes): string
            {
                $this->calls[] = [$clientType, $clientName];

                return 'app-password:7';
            }
        };
        $stack   = new ClientApiStack($this->createStub(ClientAuthenticatorInterface::class), $this->createStub(TaskProviderInterface::class), issuer: $issuer);
        $user    = new TestUser('u-1', 'u@example.com');
        $grant   = $stack->deviceService->start(ClientType::Desktop, 'Work laptop');
        $pending = $stack->deviceService->findPending($grant->userCode, $user);
        self::assertInstanceOf(DeviceAuthorization::class, $pending);

        $stack->deviceService->approve($pending, $user);

        self::assertSame([[ClientType::Desktop, 'Work laptop']], $issuer->calls);
        self::assertSame('app-password:7', $pending->getCredentialId());
    }
}
