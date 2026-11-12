<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support\Ports;

use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use PHPUnit\Framework\Assert;
use Symfony\Component\Security\Core\User\UserInterface;

use function method_exists;

/**
 * Helpers of the twin adapters: the stub they share state with is asked with the security user the reference carries
 * (the bundle's gates always pass it), after checking the reference's scalars describe that user.
 */
final class TwinPorts
{
    public static function user(TimeTrackUserRef $ref): UserInterface
    {
        $user = $ref->user();
        Assert::assertInstanceOf(UserInterface::class, $user);
        Assert::assertSame($user->getUserIdentifier(), $ref->identifier);
        Assert::assertSame(method_exists($user, 'getId') ? (string) $user->getId() : null, $ref->id);

        return $user;
    }
}
