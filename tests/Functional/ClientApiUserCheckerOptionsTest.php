<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Tests\Support\SwitchableUserChecker;

/**
 * Since 1.29 (`client_api.user_checker.revoke_tokens: false`): a refused account gets `401 invalid_token`, but its
 * token is kept, so the same token works again once the account is re-enabled.
 */
final class ClientApiUserCheckerOptionsTest extends FunctionalTestCase
{
    protected static function timeTrackConfig(): array
    {
        return ['client_api' => ['user_checker' => ['revoke_tokens' => false]]];
    }

    public function testADisabledAccountGets401AndTheTokenWorksAgainOnceReEnabled(): void
    {
        $token   = $this->login();
        $checker = self::getContainer()->get(SwitchableUserChecker::class);
        self::assertInstanceOf(SwitchableUserChecker::class, $checker);

        $checker->refuse('person@example.com');
        $body = $this->api('GET', '/api/time-track/me', null, $token);
        self::assertResponseStatusCodeSame(401);
        self::assertSame('invalid_token', $body['error'] ?? null);
        $this->api('GET', '/api/time-track/v1/me', null, $token);
        self::assertResponseStatusCodeSame(401);
        self::assertSame(1, $this->entityManager()->getRepository(ClientToken::class)->count([]), 'the token is kept');

        $checker->allow('person@example.com');
        $this->api('GET', '/api/time-track/me', null, $token);
        self::assertResponseIsSuccessful();
        $this->api('GET', '/api/time-track/v1/me', null, $token);
        self::assertResponseIsSuccessful();
    }
}
