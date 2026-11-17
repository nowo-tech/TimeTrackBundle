<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Client;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Client\ClientAccountChecker;
use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryClientTokenRepository;
use Nowo\TimeTrackBundle\Tests\Support\SwitchableUserChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Since 1.29 (`client_api.user_checker`): checkPreAuth() then checkPostAuth() on every token use; a refused account
 * deletes the token unless `revoke_tokens` is off; no checker = every account passes.
 */
final class ClientAccountCheckerTest extends TestCase
{
    private InMemoryClientTokenRepository $tokens;

    private InMemoryUser $user;

    private ClientToken $token;

    protected function setUp(): void
    {
        $this->tokens = new InMemoryClientTokenRepository();
        $this->user   = new InMemoryUser('ana', null);
        $this->token  = new ClientToken('hash', new DateTimeImmutable('+1 hour'), $this->user, ClientType::Desktop);
        $this->tokens->save($this->token);
    }

    public function testWithoutACheckerEveryAccountPasses(): void
    {
        $checker = new ClientAccountChecker();

        self::assertFalse($checker->isEnabled());
        self::assertTrue($checker->isActive($this->token, $this->user));
    }

    public function testAnActiveAccountRunsBothChecks(): void
    {
        $userChecker          = new SwitchableUserChecker();
        $userChecker->checked = [];
        $checker              = new ClientAccountChecker($userChecker, $this->tokens);

        self::assertTrue($checker->isEnabled());
        self::assertTrue($checker->isActive($this->token, $this->user));
        self::assertSame(['pre:ana', 'post:ana'], $userChecker->checked);
        self::assertNotNull($this->tokens->findValidByTokenHash('hash'));
    }

    public function testARefusedAccountDeletesTheToken(): void
    {
        foreach (['pre', 'post'] as $phase) {
            $this->setUp();
            $userChecker = new SwitchableUserChecker();
            $userChecker->refuse('ana', $phase);

            self::assertFalse((new ClientAccountChecker($userChecker, $this->tokens))->isActive($this->token, $this->user), $phase);
            self::assertNull($this->tokens->findValidByTokenHash('hash'), $phase);
        }
    }

    public function testWithoutRevocationTheTokenIsKept(): void
    {
        $userChecker = new SwitchableUserChecker();
        $userChecker->refuse('ana');
        $checker = new ClientAccountChecker($userChecker, $this->tokens, false);

        self::assertFalse($checker->isActive($this->token, $this->user));
        self::assertNotNull($this->tokens->findValidByTokenHash('hash'));

        $userChecker->allow('ana');
        self::assertTrue($checker->isActive($this->token, $this->user));
    }

    public function testWithoutAStoreNothingIsDeleted(): void
    {
        $userChecker = new SwitchableUserChecker();
        $userChecker->refuse('ana');

        self::assertFalse((new ClientAccountChecker($userChecker))->isActive($this->token, $this->user));
        self::assertNotNull($this->tokens->findValidByTokenHash('hash'));
    }
}
