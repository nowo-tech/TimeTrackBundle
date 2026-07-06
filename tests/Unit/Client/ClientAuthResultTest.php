<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Client;

use Nowo\TimeTrackBundle\Client\ClientAuthResult;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

final class ClientAuthResultTest extends TestCase
{
    public function testSuccessResult(): void
    {
        $user   = new TestUser('1', 'u@example.com');
        $result = ClientAuthResult::success($user);

        self::assertTrue($result->isSuccess());
        self::assertSame($user, $result->getUser());
    }

    public function testFailureResult(): void
    {
        $result = ClientAuthResult::failure();

        self::assertFalse($result->isSuccess());
        self::assertNull($result->getUser());
    }
}
