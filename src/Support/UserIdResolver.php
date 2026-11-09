<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Support;

use LogicException;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_object;
use function is_scalar;
use function method_exists;

/**
 * Reads the id of a host user (`getId()`) as a string.
 */
final class UserIdResolver
{
    /**
     * Id of the user as a string.
     */
    public static function getId(UserInterface $user): string
    {
        if (!method_exists($user, 'getId')) {
            throw new LogicException('User entity must expose getId().');
        }

        $id = $user->getId();

        return (string) $id;
    }

    /**
     * Since 1.18: id of a stored owner (`getId()`, read on a Doctrine proxy without loading it), or null for no owner,
     * an owner without `getId()` or a null id.
     */
    public static function idOf(?object $user): ?string
    {
        if ($user === null || !method_exists($user, 'getId')) {
            return null;
        }

        $id = $user->getId();

        return is_scalar($id) || (is_object($id) && method_exists($id, '__toString')) ? (string) $id : null;
    }
}
