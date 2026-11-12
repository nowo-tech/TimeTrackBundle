<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\UserRef;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\UserRef\Entity\HostUser;

use function in_array;
use function is_object;
use function method_exists;

/**
 * Since 1.23.0 (`user_mapping: id`, audit LQ rule "identifier columns: exact, binary comparison", audit OA-M1): the
 * rows a query by user id (or owner reference) returned are re-checked byte for byte in PHP, on top of the exact (NO PAD
 * binary) collation of those columns on MySQL / MariaDB. Not used with the relation mapping (unchanged 1.22 behaviour).
 * Stateless.
 *
 * @internal
 */
final class ExactUserIds
{
    /**
     * The rows whose owner id is one of the ids, compared with `===`.
     *
     * @template T of object
     *
     * @param list<T> $rows
     * @param list<string> $userIds
     * @param callable(T): ?string $ownerId
     *
     * @return list<T>
     */
    public static function filter(array $rows, array $userIds, callable $ownerId): array
    {
        $kept = [];
        foreach ($rows as $row) {
            if (in_array($ownerId($row), $userIds, true)) {
                $kept[] = $row;
            }
        }

        return $kept;
    }

    /**
     * Whether the entity class stores its user with the id mapping (its `user` association targets {@see HostUser}).
     *
     * @param class-string $class
     */
    public static function applies(EntityManagerInterface $entityManager, string $class): bool
    {
        return $entityManager->getClassMetadata($class)->getAssociationTargetClass('user') === HostUser::class;
    }

    /**
     * With the id mapping, the rows of `$class` whose owner (`getUserId()`, else the id of `getUser()`, or the value of
     * `$ownerId`) is one of the ids byte for byte; the rows as they are otherwise.
     *
     * @template T of object
     *
     * @param class-string $class
     * @param list<T> $rows
     * @param list<string> $userIds
     * @param (callable(T): ?string)|null $ownerId
     *
     * @return list<T>
     */
    public static function keep(EntityManagerInterface $entityManager, string $class, array $rows, array $userIds, ?callable $ownerId = null): array
    {
        if ($rows === [] || !self::applies($entityManager, $class)) {
            return $rows;
        }

        return self::filter($rows, $userIds, $ownerId ?? self::ownerId(...));
    }

    /**
     * The same for one row (null when it does not match).
     *
     * @template T of object
     *
     * @param class-string $class
     * @param T|null $row
     * @param (callable(T): ?string)|null $ownerId
     *
     * @return T|null
     */
    public static function keepOne(EntityManagerInterface $entityManager, string $class, ?object $row, string $userId, ?callable $ownerId = null): ?object
    {
        return $row === null ? null : (self::keep($entityManager, $class, [$row], [$userId], $ownerId)[0] ?? null);
    }

    /**
     * The owner id of a bundle row: `getUserId()`, else the id of `getUser()`.
     */
    public static function ownerId(object $row): ?string
    {
        if (method_exists($row, 'getUserId')) {
            $id = $row->getUserId();

            return $id === null ? null : (string) $id;
        }
        $user = method_exists($row, 'getUser') ? $row->getUser() : null;

        return is_object($user) ? UserIdResolver::idOf($user) : null;
    }
}
