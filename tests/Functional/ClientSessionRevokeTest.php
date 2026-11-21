<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Client\ClientTokenRevoker;
use Nowo\TimeTrackBundle\Enum\ClientApiAuditAction;
use Nowo\TimeTrackBundle\Enum\ClientSessionRevocation;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Event\ClientApiEvent;
use Nowo\TimeTrackBundle\Event\ClientSessionAccessCheckEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\ValueObject\ClientSessionId;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function strtoupper;

/**
 * 1.34.0: `ClientTokenRevoker::revokeById()` through the container on the configured store (SQLite here; the CI
 * functional-databases job runs it on MySQL 8.0 / 8.4, MariaDB 10.6 / 11.4 and PostgreSQL 16, with the shared and the
 * private entity manager). The kernel is never rebooted and the application's manager never cleared between calls
 * (FrankenPHP worker, kernel reset off).
 */
final class ClientSessionRevokeTest extends FunctionalTestCase
{
    /** @var list<ClientApiEvent> */
    private array $audit = [];

    protected function setUp(): void
    {
        parent::setUp();

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $dispatcher->addListener(TimeTrackEvents::CLIENT_API, function (ClientApiEvent $event): void {
            if ($event->action === ClientApiAuditAction::SessionRevoked) {
                $this->audit[] = $event;
            }
        });
    }

    public function testTheOwnerRevokesOneSessionAndItsTokenStopsAuthenticating(): void
    {
        [$token, $id] = $this->issue($this->user, 'app-password-1');
        [$kept]       = $this->issue($this->user, 'app-password-1');
        $this->assertStatus($token, 200);

        self::assertSame(ClientSessionRevocation::Revoked, $this->revoker()->revokeById(ClientSessionId::fromString($id), $this->user));

        $this->assertStatus($token, 401);
        $this->assertStatus($kept, 200, 'the other session of the same credential is untouched');
        self::assertCount(1, $this->audit);
        self::assertSame((string) $this->user->getId(), $this->audit[0]->userId);
        self::assertSame('app-password-1', $this->audit[0]->credentialId);
        self::assertSame('owner', $this->audit[0]->reason);

        self::assertSame(ClientSessionRevocation::NotFound, $this->revoker()->revokeById($id, $this->user), 'a second revoke is a no-op');
        self::assertCount(1, $this->audit);
    }

    public function testAnotherUserGetsTheAnswerOfAnUnknownId(): void
    {
        [$token, $id] = $this->issue($this->user, 'app-password-2');
        $other        = $this->createUser('other@example.com');

        self::assertSame(ClientSessionRevocation::NotFound, $this->revoker()->revokeById($id, $other));
        self::assertSame(ClientSessionRevocation::NotFound, $this->revoker()->revokeById('0b9a1a4e-2f0c-4d55-8a39-1c2d3e4f5a6b', $other));
        self::assertSame(ClientSessionRevocation::NotFound, $this->revoker()->revokeById(strtoupper($id) . 'x', $other));

        $this->assertStatus($token, 200);
        self::assertSame([], $this->audit);
    }

    public function testAnAdminRevokesAnotherUsersSession(): void
    {
        [$token, $id] = $this->issue($this->user, null);
        $admin        = $this->createUser('admin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);

        self::assertTrue($this->revoker()->revokeById(strtoupper($id), $admin)->isRevoked());

        $this->assertStatus($token, 401);
        self::assertSame('other', $this->audit[0]->reason);
        self::assertNull($this->audit[0]->credentialId);
    }

    public function testAHostListenerDeniesAnAdminOfAnotherTenant(): void
    {
        [$token, $id] = $this->issue($this->user, 'app-password-3');
        $admin        = $this->createUser('admin@other.example', ['ROLE_USER', 'ROLE_ADMIN']);
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $listener   = static function (ClientSessionAccessCheckEvent $event): void {
            if ($event->actorRef()->identifier === 'admin@other.example') {
                $event->deny();
            }
        };
        $dispatcher->addListener(TimeTrackEvents::CLIENT_SESSION_ACCESS_CHECK, $listener);
        try {
            self::assertSame(ClientSessionRevocation::NotFound, $this->revoker()->revokeById($id, $admin));
        } finally {
            $dispatcher->removeListener(TimeTrackEvents::CLIENT_SESSION_ACCESS_CHECK, $listener);
        }

        $this->assertStatus($token, 200);
    }

    public function testItNeitherFlushesNorDetachesTheApplicationsPendingWork(): void
    {
        [, $id]  = $this->issue($this->user, 'app-password-4');
        $host    = $this->entityManager();
        $pending = new AppUser('pending@example.com', 'secret');
        $host->persist($pending);

        self::assertTrue($this->revoker()->revokeById($id, $this->user)->isRevoked());

        self::assertTrue($host->isOpen());
        self::assertTrue($host->contains($pending), 'the application\'s pending entity is still managed');
        self::assertTrue($host->contains($this->user), 'nothing was detached or cleared');
        self::assertSame(0, (int) $host->getConnection()->fetchOne("SELECT COUNT(*) FROM app_user WHERE email = 'pending@example.com'"), 'nothing was flushed');
        $host->flush();
        self::assertSame(1, (int) $host->getConnection()->fetchOne("SELECT COUNT(*) FROM app_user WHERE email = 'pending@example.com'"));
    }

    /**
     * @return array{0: string, 1: string} the plain token and its session id
     */
    private function issue(AppUser $user, ?string $credentialId): array
    {
        /** @var ClientAuthService $auth */
        $auth  = self::getContainer()->get(ClientAuthService::class);
        $token = $auth->issueToken($user, ClientType::Desktop, $credentialId)['token'];
        $id    = $this->entityManager()->getConnection()->fetchOne('SELECT id FROM time_track_client_tokens WHERE token_hash = ?', [ClientAuthService::hashToken($token)]);
        self::assertIsString($id);

        return [$token, $id];
    }

    private function revoker(): ClientTokenRevoker
    {
        /** @var ClientTokenRevoker $revoker */
        $revoker = self::getContainer()->get(ClientTokenRevoker::class);

        return $revoker;
    }

    private function assertStatus(string $token, int $status, string $message = ''): void
    {
        $this->api('GET', '/api/time-track/me', null, $token);
        self::assertResponseStatusCodeSame($status, $message);
    }
}
