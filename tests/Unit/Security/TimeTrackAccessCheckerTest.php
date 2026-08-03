<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Security;

use Nowo\TimeTrackBundle\Security\AllowAllTimeTrackAccessChecker;
use Nowo\TimeTrackBundle\Security\ConfigurableTimeTrackAccessChecker;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class TimeTrackAccessCheckerTest extends TestCase
{
    public function testAllowAllAlwaysGrants(): void
    {
        $checker = new AllowAllTimeTrackAccessChecker();

        self::assertTrue($checker->canAccess(null));
        self::assertTrue($checker->canAccess(new stdClass()));
    }

    public function testConfigurableGrantsWhenRolesEmpty(): void
    {
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->expects(self::never())->method('isGranted');

        $checker = new ConfigurableTimeTrackAccessChecker($auth, []);

        self::assertTrue($checker->canAccess(null));
    }

    public function testConfigurableGrantsWhenAnyRoleMatches(): void
    {
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_USER');

        $checker = new ConfigurableTimeTrackAccessChecker($auth, ['ROLE_ADMIN', 'ROLE_USER']);

        self::assertTrue($checker->canAccess(null));
    }

    public function testConfigurableDeniesWhenNoRoleMatches(): void
    {
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturn(false);

        $checker = new ConfigurableTimeTrackAccessChecker($auth, ['ROLE_USER']);

        self::assertFalse($checker->canAccess(null));
    }
}
