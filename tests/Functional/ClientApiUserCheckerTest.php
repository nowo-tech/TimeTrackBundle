<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Event\ClientApiEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Tests\Support\SwitchableUserChecker;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_filter;
use function array_map;
use function array_values;

/**
 * Since 1.29 (`client_api.user_checker`, Beacon C5, default config): every Bearer token use runs the firewall user
 * checker (`security.user_checker.main` → {@see SwitchableUserChecker}) with checkPreAuth() and checkPostAuth(). A
 * disabled, locked or expired account gets the usual `401 invalid_token` on the unversioned and the v1 paths, and its
 * token is deleted (`revoke_tokens: true`), so re-enabling the account does not bring the token back. Several users
 * on one kernel (worker mode, no reset): one refused account never affects another.
 */
final class ClientApiUserCheckerTest extends FunctionalTestCase
{
    /** @var list<ClientApiEvent> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TimeTrackEvents::CLIENT_API, function (ClientApiEvent $event): void {
            $this->events[] = $event;
        });
    }

    public function testEveryTokenUseRunsBothChecks(): void
    {
        $token                    = $this->login();
        $this->checker()->checked = [];

        $this->api('GET', '/api/time-track/me', null, $token);
        self::assertResponseIsSuccessful();
        $this->api('GET', '/api/time-track/v1/me', null, $token);
        self::assertResponseIsSuccessful();

        self::assertSame(['pre:person@example.com', 'post:person@example.com', 'pre:person@example.com', 'post:person@example.com'], $this->checker()->checked);
    }

    public function testADisabledAccountGets401AndItsTokenIsDropped(): void
    {
        $token = $this->login();
        $this->checker()->refuse('person@example.com');

        $this->assertInvalidToken($this->api('GET', '/api/time-track/me', null, $token));
        self::assertSame(0, $this->tokenCount(), 'the refused token is deleted');
        self::assertSame(['account_inactive'], $this->denialReasons());

        // Re-enabled: the token is gone, the client signs in again.
        $this->checker()->allow('person@example.com');
        $this->assertInvalidToken($this->api('GET', '/api/time-track/v1/me', null, $token));
        $fresh = $this->login();
        $this->api('GET', '/api/time-track/v1/me', null, $fresh);
        self::assertResponseIsSuccessful();
    }

    public function testTheV1PathRefusesTheSameWay(): void
    {
        $token = $this->login();
        $this->checker()->refuse('person@example.com');

        $this->assertInvalidToken($this->api('POST', '/api/time-track/v1/timer/start', ['taskId' => 'task-1'], $token));
        self::assertSame(0, $this->tokenCount());
    }

    public function testAFailedPostAuthCheckRefusesToo(): void
    {
        $token = $this->login();
        $this->checker()->refuse('person@example.com', 'post');

        $this->assertInvalidToken($this->api('GET', '/api/time-track/timer', null, $token));
        self::assertSame(0, $this->tokenCount());
    }

    public function testOneRefusedAccountNeverAffectsAnotherOnTheSameKernel(): void
    {
        $this->createUser('other@example.com');
        $mine   = $this->login();
        $theirs = $this->login('other@example.com');
        $this->checker()->refuse('person@example.com');

        for ($i = 0; $i < 2; ++$i) {
            $this->api('GET', '/api/time-track/me', null, $theirs);
            self::assertResponseIsSuccessful();
            $this->assertInvalidToken($this->api('GET', '/api/time-track/me', null, $mine));
        }
        self::assertSame(1, $this->tokenCount());
    }

    /**
     * @param array<mixed>|null $body
     */
    private function assertInvalidToken(?array $body): void
    {
        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer error="invalid_token"');
        self::assertSame(['error' => 'invalid_token', 'message' => 'Missing, invalid, expired or revoked token.'], $body);
    }

    private function checker(): SwitchableUserChecker
    {
        $checker = self::getContainer()->get(SwitchableUserChecker::class);
        self::assertInstanceOf(SwitchableUserChecker::class, $checker);

        return $checker;
    }

    private function tokenCount(): int
    {
        return $this->entityManager()->getRepository(ClientToken::class)->count([]);
    }

    /**
     * @return list<string|null>
     */
    private function denialReasons(): array
    {
        return array_values(array_map(
            static fn (ClientApiEvent $e): ?string => $e->reason,
            array_filter($this->events, static fn (ClientApiEvent $e): bool => $e->action->value === 'request_denied'),
        ));
    }
}
