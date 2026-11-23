<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Client;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Client\ClientApiAuditor;
use Nowo\TimeTrackBundle\Client\ClientSessionRevokerInterface;
use Nowo\TimeTrackBundle\Client\ClientTokenRevoker;
use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Enum\ClientApiAuditAction;
use Nowo\TimeTrackBundle\Enum\ClientSessionRevocation;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Event\ClientApiEvent;
use Nowo\TimeTrackBundle\Event\ClientSessionAccessCheckEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryClientTokenRepository;
use Nowo\TimeTrackBundle\ValueObject\ClientSessionId;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * 1.40.0 (Beacon, REQ-ARCH-001 rule 3): {@see ClientSessionRevokerInterface::revokeSession()} — one client session by
 * its id, the actor given by id or {@see TimeTrackUserRef}: the owner rule by id, the admin rule by the reference's
 * roles, the same `NotFound` for everything else, idempotent, the audit event and the access-check event as for
 * `revokeById()`.
 */
final class ClientSessionRevokerTest extends TestCase
{
    private InMemoryClientTokenRepository $tokens;

    private EventDispatcher $dispatcher;

    private ClientToken $token;

    /** @var list<ClientApiEvent> */
    private array $audit = [];

    /** @var list<ClientSessionAccessCheckEvent> */
    private array $checks = [];

    protected function setUp(): void
    {
        $this->tokens     = new InMemoryClientTokenRepository();
        $this->dispatcher = new EventDispatcher();
        $this->token      = new ClientToken('hash-1', new DateTimeImmutable('+1 hour'), new TestUser('7', 'ana'), ClientType::Extension, null, 'app-password-1');
        $this->tokens->save($this->token);
        $this->dispatcher->addListener(TimeTrackEvents::CLIENT_API, function (ClientApiEvent $event): void {
            $this->audit[] = $event;
        });
        $this->dispatcher->addListener(TimeTrackEvents::CLIENT_SESSION_ACCESS_CHECK, function (ClientSessionAccessCheckEvent $event): void {
            $this->checks[] = $event;
        });
    }

    public function testTheOwnerRevokesByBareIdOnce(): void
    {
        $revoker = $this->revoker();

        self::assertSame(ClientSessionRevocation::Revoked, $revoker->revokeSession(ClientSessionId::fromString($this->token->getId()), '7'));
        self::assertNull($this->tokens->findValidByTokenHash('hash-1'));
        self::assertSame([ClientApiAuditAction::SessionRevoked, '7', 'owner'], [$this->audit[0]->action, $this->audit[0]->userId, $this->audit[0]->reason]);
        self::assertSame(['App\\User', '7', []], [$this->checks[0]->actorRef()->userClass, $this->checks[0]->actorRef()->id, $this->checks[0]->actorRef()->roles]);

        self::assertSame(ClientSessionRevocation::NotFound, $revoker->revokeSession($this->token->getId(), '7'), 'idempotent');
        self::assertCount(1, $this->audit);
    }

    public function testAnIntegerIdIsTheSameOwner(): void
    {
        self::assertTrue($this->revoker()->revokeSession($this->token->getId(), 7)->isRevoked());
    }

    public function testEveryOtherActorGetsNotFoundAndNothingChanges(): void
    {
        $revoker = $this->revoker();
        foreach (['8', '07', '7 ', '', 70, TimeTrackUserRef::of(new TestUser('8', 'bob'), TestUser::class, '7', 'ana'), TimeTrackUserRef::ofId('App\\User', '9', '', ['ROLE_USER'])] as $actor) {
            self::assertSame(ClientSessionRevocation::NotFound, $revoker->revokeSession($this->token->getId(), $actor));
        }
        self::assertSame(ClientSessionRevocation::NotFound, $revoker->revokeSession('not-a-uuid', '7'));
        self::assertSame(ClientSessionRevocation::NotFound, $revoker->revokeSession($this->token->getId(), new TimeTrackUserRef('App\\User', null)), 'a reference without an id');
        self::assertNotNull($this->tokens->findValidByTokenHash('hash-1'));
        self::assertSame([], $this->audit);
    }

    public function testAdminRolesComeFromTheReferenceOrItsUserObject(): void
    {
        $revoker = $this->revoker();

        self::assertTrue($revoker->revokeSession($this->token->getId(), TimeTrackUserRef::ofId('App\\User', '9', '', ['ROLE_ADMIN']))->isRevoked());
        self::assertSame(['other', '7'], [$this->audit[0]->reason, $this->audit[0]->userId]);

        $again = new ClientToken('hash-2', new DateTimeImmutable('+1 hour'), new TestUser('7', 'ana'), ClientType::Desktop);
        $this->tokens->save($again);
        $root = new TestUser('10', 'root', ['ROLE_ADMIN']);
        // The user object's roles count, not the (empty) role list given to the reference.
        self::assertTrue($revoker->revokeSession($again->getId(), TimeTrackUserRef::of($root, TestUser::class, '10', 'root'))->isRevoked());
    }

    public function testTheAccessCheckEventCanDenyTheOwner(): void
    {
        $this->dispatcher->addListener(TimeTrackEvents::CLIENT_SESSION_ACCESS_CHECK, static function (ClientSessionAccessCheckEvent $event): void {
            $event->deny();
        });

        self::assertSame(ClientSessionRevocation::NotFound, $this->revoker()->revokeSession($this->token->getId(), '7'));
        self::assertNotNull($this->tokens->findValidByTokenHash('hash-1'));
    }

    private function revoker(): ClientSessionRevokerInterface
    {
        return new ClientTokenRevoker($this->tokens, new ClientApiAuditor($this->dispatcher), null, $this->dispatcher, ['ROLE_ADMIN'], userClass: 'App\\User');
    }
}
