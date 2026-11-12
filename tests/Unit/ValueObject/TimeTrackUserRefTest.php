<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\ValueObject;

use InvalidArgumentException;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use PHPUnit\Framework\TestCase;

use function json_encode;
use function print_r;
use function serialize;
use function unserialize;
use function var_export;

/**
 * 1.23.0 (REQ-ARCH-001 rule 3): the framework-free user reference keeps the user object out of every dump and copy.
 */
final class TimeTrackUserRefTest extends TestCase
{
    public function testAReferenceWithTheUserObject(): void
    {
        $user = new TestUser('42', 'ana@example.com', ['ROLE_ADMIN']);
        $ref  = TimeTrackUserRef::of($user, TestUser::class, '42', 'ana@example.com', ['ROLE_ADMIN', 7, null]);

        self::assertSame([TestUser::class, '42', 'ana@example.com', ['ROLE_ADMIN']], [$ref->userClass, $ref->id, $ref->identifier, $ref->roles]);
        self::assertSame($user, $ref->user());
        self::assertTrue($ref->hasId());
        self::assertTrue($ref->hasRole('ROLE_ADMIN'));
        self::assertFalse($ref->hasRole('ROLE_USER'));
    }

    public function testTheUserObjectNeverShowsInADumpOrACopy(): void
    {
        $ref = TimeTrackUserRef::of(new TestUser('42', 'secret-holder@example.com'), TestUser::class, '42', 'ana');

        foreach ([print_r($ref, true), var_export($ref, true), (string) json_encode($ref), serialize($ref)] as $dump) {
            self::assertStringNotContainsString('secret-holder', $dump);
        }
        self::assertNull((clone $ref)->user());
        $copy = unserialize(serialize($ref));
        self::assertInstanceOf(TimeTrackUserRef::class, $copy);
        self::assertNull($copy->user());
        self::assertSame('42', $copy->id);
    }

    public function testAReferenceById(): void
    {
        $ref = TimeTrackUserRef::ofId(TestUser::class, 'u-1', 'ana', ['ROLE_USER']);

        self::assertNull($ref->user());
        self::assertSame(['u-1', 'ana', ['ROLE_USER']], [$ref->id, $ref->identifier, $ref->roles]);
        self::assertFalse((new TimeTrackUserRef(TestUser::class, null))->hasId());
    }

    public function testSameUserComparesClassAndIdByteForByte(): void
    {
        $a = TimeTrackUserRef::ofId('App\User', 'UserA');

        self::assertTrue($a->isSameUserAs(TimeTrackUserRef::ofId('App\User', 'UserA', 'other')));
        self::assertFalse($a->isSameUserAs(TimeTrackUserRef::ofId('App\User', 'usera')));
        self::assertFalse($a->isSameUserAs(TimeTrackUserRef::ofId('App\Admin', 'UserA')));
        self::assertFalse((new TimeTrackUserRef('App\User', null))->isSameUserAs(new TimeTrackUserRef('App\User', null)));
    }

    public function testABlankIdIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TimeTrackUserRef::ofId('App\User', '  ');
    }
}
