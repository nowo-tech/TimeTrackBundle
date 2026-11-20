<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Client;

use DateTimeImmutable;
use LogicException;
use Nowo\TimeTrackBundle\Client\ClientApiAuditor;
use Nowo\TimeTrackBundle\Client\ClientTokenRevoker;
use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Enum\ClientApiAuditAction;
use Nowo\TimeTrackBundle\Enum\ClientSessionRevocation;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Event\ClientApiEvent;
use Nowo\TimeTrackBundle\Event\ClientSessionAccessCheckEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Repository\CredentialClientTokenRepositoryInterface;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryClientTokenRepository;
use Nowo\TimeTrackBundle\Tests\Support\RacingClientSessionStore;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use Nowo\TimeTrackBundle\ValueObject\ClientSessionId;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function strtoupper;

/**
 * 1.34.0: ClientTokenRevoker::revokeById() — owner and admin revoke, others get the answer of an unknown id, idempotent,
 * the access-check event can grant or deny, the audit event names the owner.
 */
final class ClientTokenRevokerByIdTest extends TestCase
{
    private InMemoryClientTokenRepository $tokens;

    private EventDispatcher $dispatcher;

    private TestUser $owner;

    private ClientToken $token;

    /** @var list<ClientApiEvent> */
    private array $audit = [];

    protected function setUp(): void
    {
        $this->tokens     = new InMemoryClientTokenRepository();
        $this->dispatcher = new EventDispatcher();
        $this->owner      = new TestUser('7', 'ana');
        $this->token      = new ClientToken('hash-1', new DateTimeImmutable('+1 hour'), $this->owner, ClientType::Desktop, null, 'app-password-1');
        $this->tokens->save($this->token);
        $this->dispatcher->addListener(TimeTrackEvents::CLIENT_API, function (ClientApiEvent $event): void {
            $this->audit[] = $event;
        });
    }

    public function testTheOwnerRevokesTheSessionOnce(): void
    {
        $logger  = new RecordingLogger();
        $revoker = $this->revoker(logger: $logger);

        self::assertSame(ClientSessionRevocation::Revoked, $revoker->revokeById(ClientSessionId::fromString($this->token->getId()), $this->owner));
        self::assertNull($this->tokens->findValidByTokenHash('hash-1'), 'the token no longer authenticates');
        self::assertCount(1, $this->audit);
        self::assertSame(ClientApiAuditAction::SessionRevoked, $this->audit[0]->action);
        self::assertSame('7', $this->audit[0]->userId);
        self::assertSame('app-password-1', $this->audit[0]->credentialId);
        self::assertSame('desktop', $this->audit[0]->clientType);
        self::assertSame('owner', $this->audit[0]->reason);

        self::assertSame(ClientSessionRevocation::NotFound, $revoker->revokeById($this->token->getId(), $this->owner), 'a second revoke is a no-op');
        self::assertCount(1, $this->audit, 'and records nothing');
        self::assertNotContains('7', $logger->contextValues(), 'REQ-OBS-001: no user id in logs');
    }

    public function testAnotherUserGetsTheAnswerOfAnUnknownIdAndNothingChanges(): void
    {
        $logger  = new RecordingLogger();
        $revoker = $this->revoker(logger: $logger);
        $other   = new TestUser('8', 'bob');

        self::assertSame(ClientSessionRevocation::NotFound, $revoker->revokeById($this->token->getId(), $other));
        self::assertSame(ClientSessionRevocation::NotFound, $revoker->revokeById('6f1c4c62-1e86-4a5e-9a51-0d0e0b6a6f00', $other), 'same answer as an unknown id');
        self::assertNotNull($this->tokens->findValidByTokenHash('hash-1'));
        self::assertSame([], $this->audit);
        self::assertContains('forbidden', $logger->contextValues());
    }

    public function testAnAdminRevokesAnySession(): void
    {
        $admin = new TestUser('9', 'root', ['ROLE_USER', 'ROLE_ADMIN']);

        self::assertTrue($this->revoker()->revokeById($this->token->getId(), $admin)->isRevoked());
        self::assertSame('other', $this->audit[0]->reason);
        self::assertSame('7', $this->audit[0]->userId, 'the audit event names the owner');
    }

    public function testMalformedOrLookAlikeIdsAreUnknown(): void
    {
        $revoker = $this->revoker();

        self::assertSame(ClientSessionRevocation::NotFound, $revoker->revokeById('not-a-uuid', $this->owner));
        self::assertSame(ClientSessionRevocation::NotFound, $revoker->revokeById('', $this->owner));
        self::assertNotNull($this->tokens->findValidByTokenHash('hash-1'));
        self::assertTrue($revoker->revokeById(strtoupper($this->token->getId()), $this->owner)->isRevoked(), 'the canonical form is lower-case');
    }

    public function testTheAccessCheckEventCanDenyTheOwnerAndGrantAnotherUser(): void
    {
        $seen = [];
        $this->dispatcher->addListener(TimeTrackEvents::CLIENT_SESSION_ACCESS_CHECK, static function (ClientSessionAccessCheckEvent $event) use (&$seen): void {
            $seen[] = $event;
            if ($event->actorRef()->id === '7') {
                $event->deny();
            } elseif ($event->actorRef()->hasRole('ROLE_SUPPORT')) {
                $event->grant();
            }
        });
        $revoker = $this->revoker();

        self::assertSame(ClientSessionRevocation::NotFound, $revoker->revokeById($this->token->getId(), $this->owner));
        self::assertTrue($revoker->revokeById($this->token->getId(), new TestUser('10', 'support', ['ROLE_SUPPORT']))->isRevoked());

        self::assertCount(2, $seen);
        self::assertFalse($seen[0]->isGranted());
        self::assertTrue($seen[1]->isGranted());
        self::assertSame($this->token->getId(), $seen[0]->getSessionId());
        self::assertSame('7', $seen[0]->getOwnerId());
        self::assertSame('app-password-1', $seen[0]->getCredentialId());
        self::assertSame(ClientType::Desktop, $seen[0]->getClientType());
        self::assertSame(ClientSessionAccessCheckEvent::ACTION_REVOKE, $seen[0]->getAction());
    }

    public function testWithoutADispatcherTheBundleDecides(): void
    {
        $revoker = new ClientTokenRevoker($this->tokens, adminRoles: ['ROLE_ADMIN']);

        self::assertSame(ClientSessionRevocation::NotFound, $revoker->revokeById($this->token->getId(), new TestUser('8', 'bob')));
        self::assertTrue($revoker->revokeById($this->token->getId(), $this->owner)->isRevoked());
    }

    public function testAConcurrentRevokeThatWonFirstIsNotFound(): void
    {
        $racing  = new RacingClientSessionStore($this->tokens);
        $revoker = new ClientTokenRevoker($this->tokens, sessions: $racing);

        self::assertSame(ClientSessionRevocation::NotFound, $revoker->revokeById($this->token->getId(), $this->owner));
        self::assertNull($this->tokens->findValidByTokenHash('hash-1'));
    }

    public function testAFailingAuditListenerDoesNotUndoTheRevocation(): void
    {
        $this->dispatcher->addListener(TimeTrackEvents::CLIENT_API, static function (): never {
            throw new RuntimeException('audit store down');
        });
        $logger = new RecordingLogger();

        self::assertTrue($this->revoker(logger: $logger)->revokeById($this->token->getId(), $this->owner)->isRevoked());
        self::assertNull($this->tokens->findValidByTokenHash('hash-1'));
        self::assertContains(RuntimeException::class, $logger->contextValues());
    }

    public function testAStoreThatCannotLookUpSessionsFails(): void
    {
        $store = new class implements CredentialClientTokenRepositoryInterface {
            public function removeByCredentialId(string $credentialId): int
            {
                return 0;
            }
        };

        $this->expectException(LogicException::class);
        (new ClientTokenRevoker($store))->revokeById($this->token->getId(), $this->owner);
    }

    private function revoker(?RecordingLogger $logger = null): ClientTokenRevoker
    {
        return new ClientTokenRevoker($this->tokens, new ClientApiAuditor($this->dispatcher), null, $this->dispatcher, ['ROLE_ADMIN'], $logger);
    }
}
