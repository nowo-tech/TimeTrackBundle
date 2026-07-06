<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Bridge;

use Nowo\TimeTrackBundle\Bridge\NullTeamContextProvider;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

final class NullTeamContextProviderTest extends TestCase
{
    public function testReturnsEmptyContext(): void
    {
        $provider = new NullTeamContextProvider();
        $user     = new TestUser('1', 'u@example.com');
        $other    = new TestUser('2', 'other@example.com');

        self::assertSame([], $provider->getTeamIdsForUser($user));
        self::assertFalse($provider->isManagerOf($user, $other));
        self::assertSame([], $provider->getManagedUserIds($user));
    }
}
