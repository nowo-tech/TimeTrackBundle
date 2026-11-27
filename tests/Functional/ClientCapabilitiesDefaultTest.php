<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use function array_keys;

/**
 * Since 2.4.0: without `client_api.capabilities.provider` nothing changes (the 2.3 grants) and `GET me/capabilities`
 * answers every registered family as usable: `time` true, the other modules false while not registered.
 */
final class ClientCapabilitiesDefaultTest extends FunctionalTestCase
{
    public function testWithoutAProviderEveryRegisteredFamilyIsUsable(): void
    {
        $token = $this->login();
        $body  = $this->api('GET', '/api/time-track/v1/me/capabilities', null, $token);

        self::assertResponseIsSuccessful();
        self::assertSame(['time' => true, 'chat' => false, 'tasks' => false], $body['modules'] ?? null);
        self::assertIsArray($body['grantableScopes'] ?? null);
        self::assertSame(['time'], array_keys($body['grantableScopes']));
        self::assertIsArray($body);
        self::assertArrayHasKey('context', $body);
        self::assertNull($body['context']);

        $this->api('GET', '/api/time-track/v1/timer', null, $token);
        self::assertResponseIsSuccessful();
    }
}
