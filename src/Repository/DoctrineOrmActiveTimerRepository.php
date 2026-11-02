<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TimeTrackBundle\Doctrine\RecoveringFlusher;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Throwable;

/**
 * Doctrine ORM store of the running timers.
 */
final readonly class DoctrineOrmActiveTimerRepository implements ConditionalActiveTimerRepositoryInterface
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
}
