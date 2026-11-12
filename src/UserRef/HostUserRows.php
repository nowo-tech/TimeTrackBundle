<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\UserRef;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TimeTrackBundle\Doctrine\DoctrineUserReferenceResolver;
use Nowo\TimeTrackBundle\Doctrine\TimeTrackSavepoint;
use Nowo\TimeTrackBundle\UserRef\Entity\HostUser;

/**
 * Since 1.23.0 (`user_mapping: id`): deletes the bundle's row of a deleted user, after its entries were settled. The
 * foreign keys to that row then act as the ones to your users table do with the relation mapping: timers, client tokens,
 * device codes and calendar rows are deleted (`CASCADE`), entries, timesheets and ledger lines lose their `user_id`
 * (`SET NULL`). Stateless.
 */
final readonly class HostUserRows
{
    /**
     * @param EntityManagerInterface $entityManager the bundle's entity manager (private or `database.entity_manager`)
     */
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * Deletes the row of the user id (byte-exact id); returns how many rows were deleted (0 or 1).
     */
    public function remove(string $userId): int
    {
        // Audit OA-M1: an id that does not compare exactly is never used to delete (a look-alike's row could go).
        if (!DoctrineUserReferenceResolver::isExactId($userId)) {
            return 0;
        }
        $deleted = (int) TimeTrackSavepoint::execute($this->entityManager->createQueryBuilder()
            ->delete(HostUser::class, 'h')
            ->where('h.id = :id')
            ->setParameter('id', $userId)
            ->getQuery(), $this->entityManager);
        $loaded = $this->entityManager->getUnitOfWork()->tryGetById($userId, HostUser::class);
        if ($loaded instanceof HostUser) {
            $this->entityManager->detach($loaded);
        }

        return $deleted;
    }
}
