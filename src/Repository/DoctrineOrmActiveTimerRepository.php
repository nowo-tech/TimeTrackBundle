<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;

final readonly class DoctrineOrmActiveTimerRepository implements ActiveTimerRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(ActiveTimer $timer): void
    {
        $this->entityManager->persist($timer);
        $this->entityManager->flush();
    }

    public function remove(ActiveTimer $timer): void
    {
        $this->entityManager->remove($timer);
        $this->entityManager->flush();
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
