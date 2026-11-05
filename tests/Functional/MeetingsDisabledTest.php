<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Service\ClientAuthService;

/**
 * Since 1.14 (AC-22): with `meetings.enabled: false` (the default) the route is 404 for every token, with the privacy
 * headers, and `GET me` says `features.meetings: false`, `meetings: null`, `links.calendars: null`.
 */
final class MeetingsDisabledTest extends FunctionalTestCase
{
    public function testOff(): void
    {
        $auth = self::getContainer()->get(ClientAuthService::class);
        self::assertInstanceOf(ClientAuthService::class, $auth);
        $token = (string) $auth->issueToken($this->user, ClientType::Desktop, null, [ClientScope::MeetingsRead])['token'];

        $body = $this->api('GET', '/api/time-track/me/meetings', null, $token);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('not_found', $body['error'] ?? null);
        self::assertSame('no-store, private', $this->client->getResponse()->headers->get('Cache-Control'));
        $this->api('GET', '/api/time-track/me/meetings');
        self::assertResponseStatusCodeSame(404, 'before authentication: nothing to probe');

        $me = $this->api('GET', '/api/time-track/me', null, $token);
        self::assertFalse($me['features']['meetings'] ?? null);
        self::assertIsArray($me);
        self::assertArrayHasKey('meetings', $me);
        self::assertNull($me['meetings']);
        self::assertSame(['calendars' => null], $me['links'] ?? null);
    }
}
