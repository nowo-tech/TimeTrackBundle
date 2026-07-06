<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TimeTrackBundle\Entity\TimeEntry;

final readonly class DoctrineOrmTimeEntryRepository implements TimeEntryRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(TimeEntry $entry): void
    {
        $this->entityManager->persist($entry);
        $this->entityManager->flush();
    }

    public function findByUserAndPeriod(string $userId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        /** @var list<TimeEntry> $result */
        $result = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(TimeEntry::class, 'e')
            ->innerJoin('e.user', 'u')
            ->where('u.id = :userId')
            ->andWhere('e.startedAt >= :from')
            ->andWhere('e.startedAt <= :to')
            ->setParameter('userId', $userId)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('e.startedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }
}
