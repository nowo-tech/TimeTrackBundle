<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TimeTrackBundle\Doctrine\RecoveringFlusher;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Throwable;

use function array_column;

/**
 * Doctrine ORM store of the running timers.
 */
final readonly class DoctrineOrmActiveTimerRepository implements ConditionalActiveTimerRepositoryInterface, TaskTimerMoveRepositoryInterface
{
    /**
     * Receives the entity manager and the registry (worker-mode recovery).
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $managerRegistry = null,
    ) {
    }

    /**
     * Persists and flushes a timer.
     */
    public function save(ActiveTimer $timer): void
    {
        $this->entityManager->persist($timer);
        RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
    }

    /**
     * Removes and flushes a timer.
     */
    public function remove(ActiveTimer $timer): void
    {
        $this->entityManager->remove($timer);
        RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
    }

    /**
     * Conditional `DELETE … WHERE id = :id` inside a DBAL transaction: only the call whose delete affects one row runs
     * `$onRemoved`; a concurrent call blocks on the row (MySQL / PostgreSQL) or the database lock (SQLite) and then
     * deletes nothing. The timer is detached afterwards so a later flush does not touch the deleted row.
     */
    public function removeIfPresent(ActiveTimer $timer, callable $onRemoved): bool
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $deleted = $this->entityManager->createQueryBuilder()
                ->delete(ActiveTimer::class, 't')
                ->where('t.id = :id')
                ->setParameter('id', $timer->getId())
                ->getQuery()
                ->execute();

            if ($deleted !== 1) {
                $connection->rollBack();

                return false;
            }

            $onRemoved();
            $connection->commit();
        } catch (Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $exception;
        }

        if ($this->entityManager->contains($timer)) {
            $this->entityManager->detach($timer);
        }

        return true;
    }

    /**
     * Running timer of a user.
     */
    public function findByUserId(string $userId): ?ActiveTimer
    {
        /** @var ActiveTimer|null $timer */
        $timer = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(ActiveTimer::class, 't')
            ->innerJoin('t.user', 'u')
            ->where('u.id = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getOneOrNullResult();

        return $timer;
    }

    /**
     * Since 1.17: sets the board snapshot of the running timers of these tasks that do not have it yet, by id; nothing else
     * changes. A loaded timer is detached, so a later flush does not write its old board back.
     */
    public function moveTimersToBoard(array $taskIds, ?string $boardId): int
    {
        if ($taskIds === []) {
            return 0;
        }
        $query = $this->entityManager->createQueryBuilder()
            ->select('t.id AS id')
            ->from(ActiveTimer::class, 't')
            ->where('t.taskId IN (:taskIds)')
            ->setParameter('taskIds', $taskIds);
        if ($boardId === null) {
            $query->andWhere('t.boardIdSnapshot IS NOT NULL');
        } else {
            $query->andWhere('(t.boardIdSnapshot IS NULL OR t.boardIdSnapshot <> :boardId)')->setParameter('boardId', $boardId);
        }
        /** @var list<string> $ids */
        $ids = array_column($query->getQuery()->getArrayResult(), 'id');
        if ($ids === []) {
            return 0;
        }
        $changed = (int) $this->entityManager->createQueryBuilder()
            ->update(ActiveTimer::class, 't')
            ->set('t.boardIdSnapshot', ':boardId')
            ->where('t.id IN (:ids)')
            ->setParameter('boardId', $boardId)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
        $unitOfWork = $this->entityManager->getUnitOfWork();
        foreach ($ids as $id) {
            $loaded = $unitOfWork->tryGetById($id, ActiveTimer::class);
            if ($loaded instanceof ActiveTimer) {
                $this->entityManager->detach($loaded);
            }
        }

        return $changed;
    }
}
