<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\UserRef;

use function in_array;

/**
 * Since 1.23.0 (`user_mapping: id`, audit LQ rule "identifier columns: exact, binary comparison"): the rows a query by
 * user id returned are re-checked byte for byte in PHP, on top of the binary collation of the id columns on MySQL /
 * MariaDB. Not used with the relation mapping (unchanged 1.22 behaviour). Stateless.
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
}
