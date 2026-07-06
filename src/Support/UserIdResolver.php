<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Support;

use LogicException;
use Symfony\Component\Security\Core\User\UserInterface;

use function method_exists;

final class UserIdResolver
{
    public static function getId(UserInterface $user): string
    {
        if (!method_exists($user, 'getId')) {
            throw new LogicException('User entity must expose getId().');
        }

        $id = $user->getId();

        return (string) $id;
    }
}
