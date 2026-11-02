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
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * REQ-DI-001 (token expiry / last use from the injected clock) and REQ-OBS-001 (no username, password, token or IP in
 * the log records).
 */
final class ClientAuthServiceClockLoggerTest extends TestCase
{
    public function testLoginUsesTheClockAndLogsWithoutSecrets(): void
    {
        $user          = new TestUser('42', 'person@example.com');
        $authenticator = $this->createMock(ClientAuthenticatorInterface::class);
        $authenticator->method('authenticate')->willReturn(ClientAuthResult::success($user));
        $repository = new InMemoryClientTokenRepository();
        $logger     = new RecordingLogger();
        $service    = new ClientAuthService($authenticator, $repository, 3600, null, new MockClock('2026-05-04 09:00:00+00:00'), $logger);

        $result = $service->login('person@example.com', 'p4ssw0rd', ClientType::Extension);

        self::assertNotNull($result);
        self::assertSame('2026-05-04T10:00:00+00:00', $result['expiresAt']);
        self::assertSame(['TimeTrack client login succeeded.'], $logger->messages());
        self::assertSame(['bundle' => 'time_track', 'action' => 'client_login', 'client' => 'extension'], $logger->records[0]['context']);
        foreach ($logger->contextValues() as $value) {
            self::assertNotContains($value, ['42', 'person@example.com', 'p4ssw0rd', $result['token'], ClientAuthService::hashToken($result['token'])]);
        }
    }

    public function testFailedLoginIsLoggedAsNotice(): void
    {
        $authenticator = $this->createMock(ClientAuthenticatorInterface::class);
        $authenticator->method('authenticate')->willReturn(ClientAuthResult::failure());
        $logger  = new RecordingLogger();
        $service = new ClientAuthService($authenticator, new InMemoryClientTokenRepository(), 3600, null, new MockClock(), $logger);

        self::assertNull($service->login('person@example.com', 'wrong', ClientType::Desktop));
        self::assertSame('notice', $logger->records[0]['level']);
        self::assertSame(['bundle' => 'time_track', 'action' => 'client_login', 'client' => 'desktop'], $logger->records[0]['context']);
    }

    public function testResolveUserTouchesTheTokenWithTheClockTime(): void
    {
        $clock      = new MockClock('2026-05-04 09:00:00');
        $repository = new InMemoryClientTokenRepository();
        $token      = new ClientToken(ClientAuthService::hashToken('plain'), new DateTimeImmutable('+1 day'), new TestUser('1', 'u@example.com'), ClientType::Web, $clock->now());
        $repository->save($token);
        $service = new ClientAuthService($this->createMock(ClientAuthenticatorInterface::class), $repository, 3600, null, $clock);

        $lastUsed = static fn (): string => $token->getLastUsedAt()?->format('Y-m-d H:i:s') ?? '';

        self::assertNotNull($service->resolveUser('plain'));
        self::assertSame('2026-05-04 09:00:00', $lastUsed());
        self::assertSame('2026-05-04 09:00:00', $token->getCreatedAt()->format('Y-m-d H:i:s'));

        $clock->sleep(60);
        $service->resolveUser('plain');
        self::assertSame('2026-05-04 09:00:00', $lastUsed(), 'not touched within 5 minutes');

        $clock->sleep(300);
        $service->resolveUser('plain');
        self::assertSame('2026-05-04 09:06:00', $lastUsed());
    }

    public function testLogoutLogsTheRevocation(): void
    {
        $repository = new InMemoryClientTokenRepository();
        $repository->save(new ClientToken(ClientAuthService::hashToken('plain'), new DateTimeImmutable('+1 day'), new TestUser('1', 'u@example.com'), ClientType::Desktop));
        $logger  = new RecordingLogger();
        $service = new ClientAuthService($this->createMock(ClientAuthenticatorInterface::class), $repository, 3600, null, null, $logger);

        $service->logout('plain');
        $service->logout('unknown');

        self::assertSame(['TimeTrack client token revoked.'], $logger->messages());
        self::assertSame(['bundle' => 'time_track', 'action' => 'client_logout', 'client' => 'desktop'], $logger->records[0]['context']);
    }
}
