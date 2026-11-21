<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Doctrine;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

use function in_array;
use function preg_match;

use const PHP_INT_MAX;

/**
 * Since 1.35.0: whether a user id can be stored in the owner (`user`) column of a bundle entity, so that a query by
 * owner never sends a value the column type rejects (PostgreSQL fails on `'nobody'` for an integer column, MySQL casts
 * it). An id that cannot be stored owns no row: the caller matches nothing. Reads only the mapping metadata (cached by
 * the manager's metadata factory); stateless (FrankenPHP worker mode, kernel reset false).
 *
 * @internal
 */
final class OwnerIdColumn
{
    /** @var array<string, int> Largest value of each integer column type */
    private const INTEGER_MAX = [Types::SMALLINT => 32767, Types::INTEGER => 2147483647, Types::BIGINT => PHP_INT_MAX];

    /**
     * @param class-string $class A bundle entity with a `user` association
     */
    public static function accepts(EntityManagerInterface $entityManager, string $class, string $userId): bool
    {
        $metadata = $entityManager->getClassMetadata($class);
        $target   = $entityManager->getClassMetadata($metadata->getAssociationTargetClass('user'));
        $type     = $target->getTypeOfField($target->getSingleIdentifierFieldName());
        if (!in_array($type, [Types::SMALLINT, Types::INTEGER, Types::BIGINT], true)) {
            return true;
        }
        if (preg_match('/^(0|-?[1-9]\d{0,18})$/D', $userId) !== 1) {
            return false;
        }
        $value = (int) $userId;

        // A 19-digit value beyond PHP_INT_MAX saturates the cast: the round trip tells it apart.
        $max = self::INTEGER_MAX[$type] ?? PHP_INT_MAX;

        return (string) $value === $userId && $value <= $max && $value >= -$max - 1;
    }
}
