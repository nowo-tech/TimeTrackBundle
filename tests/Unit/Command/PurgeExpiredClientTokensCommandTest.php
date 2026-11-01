<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Command;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Command\PurgeExpiredClientTokensCommand;
use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Repository\ClientTokenRepositoryInterface;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryClientTokenRepository;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

final class PurgeExpiredClientTokensCommandTest extends TestCase
{
    public function testPurgesExpiredTokens(): void
    {
        $repository = new InMemoryClientTokenRepository();
        $user       = new TestUser('1', 'u@example.com');
        $repository->save(new ClientToken(
            ClientAuthService::hashToken('expired'),
            new DateTimeImmutable('-1 hour'),
            $user,
            ClientType::Web,
        ));
        $repository->save(new ClientToken(
            ClientAuthService::hashToken('valid'),
            new DateTimeImmutable('+1 hour'),
            $user,
            ClientType::Web,
        ));

        $command = new PurgeExpiredClientTokensCommand($repository);
        $tester  = new CommandTester($command);

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Purged 1', $tester->getDisplay());
    }

    public function testLogsStartAndFinishWithTheCount(): void
    {
        $repository = $this->createMock(ClientTokenRepositoryInterface::class);
        $repository->method('purgeExpired')->willReturn(3);
        $logger = new RecordingLogger();

        self::assertSame(0, (new CommandTester(new PurgeExpiredClientTokensCommand($repository, $logger)))->execute([]));
        self::assertSame(['TimeTrack expired client token purge started.', 'TimeTrack expired client token purge finished.'], $logger->messages());
        self::assertSame(['bundle' => 'time_track', 'action' => 'client_tokens_purge', 'purged' => 3], $logger->records[1]['context']);
    }

    public function testLogsAndRethrowsAFailure(): void
    {
        $repository = $this->createMock(ClientTokenRepositoryInterface::class);
        $repository->method('purgeExpired')->willThrowException(new RuntimeException('database down'));
        $logger = new RecordingLogger();

        try {
            (new CommandTester(new PurgeExpiredClientTokensCommand($repository, $logger)))->execute([]);
            self::fail('The failure must be rethrown.');
        } catch (RuntimeException $e) {
            self::assertSame('database down', $e->getMessage());
        }

        self::assertSame('error', $logger->records[1]['level']);
        self::assertSame(RuntimeException::class, $logger->records[1]['context']['exception_class'] ?? null);
    }
}
