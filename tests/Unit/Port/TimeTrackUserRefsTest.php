<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Port;

use App\Entity\User;
use LogicException;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefs;
use Nowo\TimeTrackBundle\Tests\Fixtures\Proxy\__CG__\App\Entity\User as ProxyUser;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * 1.23.0: conversion between the Symfony users of the services and the framework-free references.
 */
final class TimeTrackUserRefsTest extends TestCase
{
    public function testASymfonyUser(): void
    {
        $user = new TestUser('42', 'ana@example.com', ['ROLE_USER']);
        $ref  = TimeTrackUserRefs::fromUser($user);

        self::assertSame([TestUser::class, '42', 'ana@example.com', ['ROLE_USER']], [$ref->userClass, $ref->id, $ref->identifier, $ref->roles]);
        self::assertSame($user, $ref->user());
        self::assertSame($user, TimeTrackUserRefs::securityUser($ref, 'Port'));
    }

    public function testAPlainObjectAndABlankId(): void
    {
        $ref = TimeTrackUserRefs::fromUser(new stdClass());
        self::assertSame([stdClass::class, null, '', []], [$ref->userClass, $ref->id, $ref->identifier, $ref->roles]);

        self::assertNull(TimeTrackUserRefs::fromUser(new TestUser(' ', 'blank'))->id);
    }

    public function testTheProxyWrapperIsDropped(): void
    {
        self::assertSame(User::class, TimeTrackUserRefs::className(new ProxyUser()));
    }

    public function testAReferenceWithoutASecurityUserIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Port returned a user reference without a Symfony security user object');

        TimeTrackUserRefs::securityUser(TimeTrackUserRef::ofId(TestUser::class, '42'), 'Port');
    }
}
