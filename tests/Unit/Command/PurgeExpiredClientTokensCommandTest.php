<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Command;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Command\PurgeExpiredClientTokensCommand;
use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryClientTokenRepository;
use PHPUnit\Framework\TestCase;
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
}
