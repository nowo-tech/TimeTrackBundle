<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Tests\Support\SwitchableUserChecker;

/**
 * Since 1.29 (`client_api.user_checker: false`): the 1.28 behaviour, no account check on token use (the host's
 * `client_api.credential_state` remains the only per-request check).
 */
final class ClientApiUserCheckerOffTest extends FunctionalTestCase
{
    protected static function timeTrackConfig(): array
    {
        return ['client_api' => ['user_checker' => false]];
    }

    public function testTheCheckerNeverRuns(): void
    {
        $token   = $this->login();
        $checker = self::getContainer()->get(SwitchableUserChecker::class);
        self::assertInstanceOf(SwitchableUserChecker::class, $checker);
        $checker->refuse('person@example.com');
        $checker->checked = [];

        $this->api('GET', '/api/time-track/me', null, $token);
        self::assertResponseIsSuccessful();
        $this->api('GET', '/api/time-track/v1/me', null, $token);
        self::assertResponseIsSuccessful();
        self::assertSame([], $checker->checked);
    }
}
