<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TimeTrackBundle\Doctrine\RecoveringFlusher;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use SortDirection;

use function max;

/**
 * Doctrine ORM store of the closed time entries (pages in the database, REQ-PERF-001).
 */
final readonly class DoctrineOrmTimeEntryRepository implements PaginatedTimeEntryRepositoryInterface
{
    /**
     * @param EntityManagerInterface $entityManager Entity manager of the configured connection
     * @param ManagerRegistry|null $managerRegistry Resets a closed entity manager after a failed flush (worker mode)
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $managerRegistry = null,
    ) {
    }

    /**
     * Persists and flushes a time entry.
     */
    public function save(TimeEntry $entry): void
    {
        $this->entityManager->persist($entry);
        RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
    }

    /**
     * Entries of one user started within the period, newest first.
     *
     * @return list<TimeEntry>
     */
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
            ->orderBy('e.startedAt', SortDirection::Descending)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Entries of the users started within the period, newest first.
     *
     * @param list<string> $userIds User ids
     *
     * @return list<TimeEntry>
     */
    public function findPageByUsersAndPeriod(array $userIds, DateTimeImmutable $from, DateTimeImmutable $to, int $offset, int $limit): array
    {
        if ($userIds === []) {
            return [];
        }

        /** @var list<TimeEntry> $result */
        $result = $this->periodQuery($userIds, $from, $to)
            ->select('e')
            ->orderBy('e.startedAt', SortDirection::Descending)
            ->addOrderBy('e.id', SortDirection::Descending)
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Number of entries of the users started within the period.
     *
     * @param list<string> $userIds User ids
     */
    public function countByUsersAndPeriod(array $userIds, DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        if ($userIds === []) {
            return 0;
        }

        return (int) $this->periodQuery($userIds, $from, $to)
            ->select('COUNT(e.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param list<string> $userIds
     */
    private function periodQuery(array $userIds, DateTimeImmutable $from, DateTimeImmutable $to): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->from(TimeEntry::class, 'e')
            ->innerJoin('e.user', 'u')
            ->where('u.id IN (:userIds)')
            ->andWhere('e.startedAt >= :from')
            ->andWhere('e.startedAt <= :to')
            ->setParameter('userIds', $userIds)
            ->setParameter('from', $from)
            ->setParameter('to', $to);
    }
}
