<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TimeTrackBundle\Doctrine\RecoveringFlusher;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;

/**
 * Doctrine ORM store of the running timers.
 */
final readonly class DoctrineOrmActiveTimerRepository implements ActiveTimerRepositoryInterface
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
