<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Doctrine;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use SortDirection;

use function array_chunk;
use function count;
use function is_scalar;

/**
 * Since 1.35.0 (audit YI L1): the rows a user owns in a bundle entity, matched byte for byte. The owner column may
 * compare case-insensitively (a host's string user ids in relation mode keep the host's collation), so the SQL match is
 * checked again in PHP and writes go by primary key: `'ABC'` never deletes `'abc'`'s rows. Scalars only (no entity is
 * hydrated), keyset batches; stateless (FrankenPHP worker mode, kernel reset false).
 *
 * @internal
 */
final class OwnedRows
{
    /** Rows read, or deleted, per statement. */
    public const BATCH = 500;

    /**
     * The ids of the rows whose owner is exactly the user (none when the owner column cannot hold the id).
     *
     * @param class-string $class A bundle entity with a `user` association
     * @param bool $forUpdate Lock the rows read (`FOR UPDATE`, in id order) before the caller writes them; needs a transaction
     *
     * @return list<string>
     */
    public static function ids(EntityManagerInterface $entityManager, string $class, string $userId, bool $forUpdate = false): array
    {
        if (!OwnerIdColumn::accepts($entityManager, $class, $userId)) {
            return [];
        }
        $ids   = [];
        $after = '';
        do {
            $query = $entityManager->createQueryBuilder()
                ->select('x.id AS id', 'IDENTITY(x.user) AS ownerId')
                ->from($class, 'x')
                ->where('IDENTITY(x.user) = :owner')
                ->andWhere('x.id > :after')
                ->setParameter('owner', $userId)
                ->setParameter('after', $after)
                ->orderBy('x.id', SortDirection::Ascending)
                ->setMaxResults(self::BATCH)
                ->getQuery();
            $rows = ($forUpdate ? $query->setLockMode(LockMode::PESSIMISTIC_WRITE) : $query)->getArrayResult();
            foreach ($rows as $row) {
                $after = is_scalar($row['id']) ? (string) $row['id'] : $after;
                // Only a case-folding collation (MySQL / MariaDB suites) returns a look-alike row here.
                if (!is_scalar($row['ownerId']) || (string) $row['ownerId'] !== $userId) {
                    continue; // @codeCoverageIgnore
                }
                $ids[] = $after;
            }
        } while (count($rows) === self::BATCH);

        return $ids;
    }

    /**
     * Deletes these rows by primary key in statements of {@see self::BATCH} ids (inside the caller's unit of work) and
     * returns how many were deleted.
     *
     * @param class-string $class
     * @param list<string> $ids
     */
    public static function delete(EntityManagerInterface $entityManager, string $class, array $ids): int
    {
        $deleted = 0;
        foreach (array_chunk($ids, self::BATCH) as $chunk) {
            $deleted += (int) TimeTrackSavepoint::execute($entityManager->createQueryBuilder()
                ->delete($class, 'x')
                ->where('x.id IN (:ids)')
                ->setParameter('ids', $chunk)
                ->getQuery(), $entityManager);
        }

        return $deleted;
    }
}
