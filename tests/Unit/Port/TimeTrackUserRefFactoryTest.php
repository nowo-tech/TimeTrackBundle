<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Port;

use Nowo\TimeTrackBundle\Port\TimeTrackUserRefFactory;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefs;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

/**
 * 1.24.0: the public factory and the internal conversion give equal refs.
 */
final class TimeTrackUserRefFactoryTest extends TestCase
{
    public function testFromUserCarriesClassIdIdentifierRolesAndObject(): void
    {
        $user = new TestUser('42', 'ana@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $ref  = (new TimeTrackUserRefFactory())->fromUser($user);

        self::assertSame([TestUser::class, '42', 'ana@example.com', ['ROLE_USER', 'ROLE_ADMIN']], [$ref->userClass, $ref->id, $ref->identifier, $ref->roles]);
        self::assertSame($user, $ref->user());
    }

    public function testABlankIdIsNone(): void
    {
        self::assertNull((new TimeTrackUserRefFactory())->fromUser(new TestUser(' ', 'blank'))->id);
    }

    public function testPublicAndInternalPathsGiveEqualRefs(): void
    {
        $user    = new TestUser('7', 'bo@example.com', ['ROLE_X']);
        $service = (new TimeTrackUserRefFactory())->fromUser($user);
        $static  = TimeTrackUserRefFactory::build($user);
        $intern  = TimeTrackUserRefs::fromUser($user);

        foreach ([$static, $intern] as $other) {
            self::assertSame([$service->userClass, $service->id, $service->identifier, $service->roles], [$other->userClass, $other->id, $other->identifier, $other->roles]);
            self::assertSame($user, $other->user());
        }
    }
}
