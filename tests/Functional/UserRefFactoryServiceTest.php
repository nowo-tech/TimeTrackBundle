<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Port\TimeTrackUserRefFactory;

/**
 * 1.24.0: a host can fetch / autowire the public factory by its class.
 */
final class UserRefFactoryServiceTest extends FunctionalTestCase
{
    public function testTheFactoryIsAPublicService(): void
    {
        $factory = self::getContainer()->get(TimeTrackUserRefFactory::class);

        self::assertInstanceOf(TimeTrackUserRefFactory::class, $factory);
        $ref = $factory->fromUser($this->user);
        self::assertSame($this->user::class, $ref->userClass);
        self::assertSame($this->user->getUserIdentifier(), $ref->identifier);
        self::assertSame($this->user, $ref->user());
    }
}
