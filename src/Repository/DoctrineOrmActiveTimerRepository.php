<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TimeTrackBundle\Doctrine\RecoveringFlusher;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;

final readonly class DoctrineOrmActiveTimerRepository implements ActiveTimerRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $managerRegistry = null,
    ) {
    }

    public function save(ActiveTimer $timer): void
    {
        $this->entityManager->persist($timer);
        RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
    }

    public function remove(ActiveTimer $timer): void
    {
        $this->entityManager->remove($timer);
        RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
    }

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
