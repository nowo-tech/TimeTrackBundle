<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Client;

use DateTimeImmutable;
use LogicException;
use Nowo\TimeTrackBundle\Client\ClientApiAuditor;
use Nowo\TimeTrackBundle\Client\ClientTokenRevoker;
use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Enum\ClientApiAuditAction;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Event\ClientApiEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Repository\CredentialClientTokenRepositoryInterface;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryClientTokenRepository;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * 1.35.0: ClientTokenRevoker::revokeAllForUser() — every session of one user ends, the others stay, one audit event.
 */
final class ClientTokenRevokerAllForUserTest extends TestCase
{
    public function testEverySessionOfTheUserEndsAndOnlyThose(): void
    {
        $tokens     = new InMemoryClientTokenRepository();
        $dispatcher = new EventDispatcher();
        $audit      = [];
        $dispatcher->addListener(TimeTrackEvents::CLIENT_API, static function (ClientApiEvent $event) use (&$audit): void {
            $audit[] = $event;
        });
        $ana = new TestUser('7', 'ana');
        $tokens->save(new ClientToken('hash-1', new DateTimeImmutable('+1 hour'), $ana, ClientType::Desktop));
        $tokens->save(new ClientToken('hash-2', new DateTimeImmutable('+1 hour'), $ana, ClientType::Extension));
        $tokens->save(new ClientToken('hash-3', new DateTimeImmutable('+1 hour'), new TestUser('8', 'bob'), ClientType::Desktop));
        $logger  = new RecordingLogger();
        $revoker = new ClientTokenRevoker($tokens, new ClientApiAuditor($dispatcher), logger: $logger);

        self::assertSame(2, $revoker->revokeAllForUser('7'));
        self::assertCount(1, $tokens->all());
        self::assertNotNull($tokens->findValidByTokenHash('hash-3'));
        self::assertCount(1, $audit);
        self::assertSame(ClientApiAuditAction::SessionRevoked, $audit[0]->action);
        self::assertSame(['7', 'all'], [$audit[0]->userId, $audit[0]->reason]);

        self::assertSame(0, $revoker->revokeAllForUser('7'), 'idempotent');
        self::assertCount(1, $audit, 'nothing revoked, nothing audited');
        self::assertNotContains('7', $logger->contextValues(), 'REQ-OBS-001: no user id in logs');
    }

    public function testAStoreWithoutTheCapabilityThrows(): void
    {
        $store = new class implements CredentialClientTokenRepositoryInterface {
            public function removeByCredentialId(string $credentialId): int
            {
                return 0;
            }
        };

        $this->expectException(LogicException::class);
        (new ClientTokenRevoker($store))->revokeAllForUser('7');
    }
}
