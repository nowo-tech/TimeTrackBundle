<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Service\ClientAuthService;

/**
 * Since 1.14 (AC-01, §8 rule): with `calendar` and `manual_entries` off (the default) every new route answers 404 and
 * `GET me` says so, even for a token with every 1.14 scope.
 */
final class CalendarApiDisabledTest extends FunctionalTestCase
{
    public function testTheRoutesAreNotFound(): void
    {
        $auth = self::getContainer()->get(ClientAuthService::class);
        self::assertInstanceOf(ClientAuthService::class, $auth);
        $token = (string) $auth->issueToken($this->user, ClientType::Desktop, null, ClientScope::all())['token'];

        foreach ([
            ['GET', '/api/time-track/calendar/connections', []],
            ['DELETE', '/api/time-track/calendar/connections/abc', []],
            ['GET', '/api/time-track/suggestions', []],
            ['POST', '/api/time-track/suggestions/accept', ['ids' => ['a']]],
            ['POST', '/api/time-track/suggestions/dismiss', ['ids' => ['a']]],
            ['POST', '/api/time-track/suggestions/resolve-keys', ['keys' => ['#task-1']]],
            ['POST', '/api/time-track/entries', ['taskId' => 'task-1']],
            // Since 1.21 (audit MV-T-L2): the billable change follows manual_entries too.
            ['PATCH', '/api/time-track/entries/abc', ['billable' => true]],
        ] as [$method, $uri, $payload]) {
            $body = $this->api($method, $uri, $payload, $token, ['HTTP_IDEMPOTENCY_KEY' => 'k']);
            self::assertResponseStatusCodeSame(404, $method . ' ' . $uri);
            self::assertSame('not_found', $body['error'] ?? null);
        }
        $me = $this->api('GET', '/api/time-track/me', null, $token);
        self::assertFalse($me['features']['calendar_suggestions'] ?? null);
        self::assertFalse($me['features']['manual_entries'] ?? null);
    }
}
