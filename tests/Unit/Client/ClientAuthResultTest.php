<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Client;

use LogicException;
use Nowo\TimeTrackBundle\Client\ClientAuthResult;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use PHPUnit\Framework\TestCase;

final class ClientAuthResultTest extends TestCase
{
    public function testSuccessResult(): void
    {
        $user   = new TestUser('1', 'u@example.com');
        $result = ClientAuthResult::success($user);

        self::assertTrue($result->isSuccess());
        self::assertSame($user, $result->getUser());
        self::assertSame($user, $result->requireUser());
    }

    public function testSuccessForAReference(): void
    {
        // 1.23.0 (REQ-ARCH-001 rule 3): the framework-free path gives the same result.
        $user   = new TestUser('1', 'u@example.com', ['ROLE_USER']);
        $result = ClientAuthResult::successFor(TimeTrackUserRef::of($user, TestUser::class, '1', 'u@example.com'), 'cred-1', ['timer:read']);

        self::assertTrue($result->isSuccess());
        self::assertSame($user, $result->requireUser());
        self::assertSame('cred-1', $result->getCredentialId());
        self::assertSame([ClientScope::TimerRead], $result->getScopes());
        self::assertSame(['1', 'u@example.com', $user], [$result->userRef()?->id, $result->userRef()?->identifier, $result->userRef()?->user()]);
        self::assertNull(ClientAuthResult::failure()->userRef());
    }

    public function testSuccessForAReferenceWithoutTheSecurityUserIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ClientAuthenticatorInterface::authenticate() returned a user reference without a Symfony security user object');

        ClientAuthResult::successFor(TimeTrackUserRef::ofId(TestUser::class, '1'));
    }

    public function testFailureResult(): void
    {
        $result = ClientAuthResult::failure();

        self::assertFalse($result->isSuccess());
        self::assertNull($result->getUser());
    }

    public function testRequireUserThrowsOnFailure(): void
    {
        $this->expectException(LogicException::class);
        ClientAuthResult::failure()->requireUser();
    }
}
