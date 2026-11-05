<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TimeTrackBundle\Doctrine\RecoveringFlusher;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryRemoval;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryScope;
use SortDirection;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_column;
use function array_map;
use function count;
use function max;

/**
 * Doctrine ORM store of the closed time entries (pages in the database, REQ-PERF-001).
 */
final readonly class DoctrineOrmTimeEntryRepository implements ImportedTimeEntryRepositoryInterface, LatestTimeEntryRepositoryInterface
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
     * End of the user's latest **live** entry (one row, hydrated so the stored time zone is respected). Since 1.11
     * imported entries are ignored: they must never clamp a backdated live start.
     */
    public function findLatestEndedAt(string $userId): ?DateTimeImmutable
    {
        /** @var list<TimeEntry> $result */
        $result = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(TimeEntry::class, 'e')
            ->innerJoin('e.user', 'u')
            ->where('u.id = :userId')
            ->andWhere('e.source <> :import')
            ->setParameter('userId', $userId)
            ->setParameter('import', TimeEntrySource::Import->value)
            ->orderBy('e.endedAt', SortDirection::Descending)
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        return $result === [] ? null : $result[0]->getEndedAt();
    }

    /**
     * Since 1.11: the imported entry of an external identity, or null.
     */
    public function findByExternal(string $externalSource, string $externalId): ?TimeEntry
    {
        /** @var TimeEntry|null $entry */
        $entry = $this->entityManager->getRepository(TimeEntry::class)->findOneBy(['externalSource' => $externalSource, 'externalId' => $externalId]);

        return $entry;
    }

    /**
     * Since 1.11: every imported entry of a source, keyset-batched by id.
     *
     * @return iterable<TimeEntry>
     */
    public function findByExternalSource(string $externalSource, int $batchSize = 500): iterable
    {
        $batchSize = max(1, $batchSize);
        $lastId    = '';
        do {
            /** @var list<TimeEntry> $batch */
            $batch = $this->entityManager->createQueryBuilder()
                ->select('e')
                ->from(TimeEntry::class, 'e')
                ->where('e.externalSource = :source')
                ->andWhere('e.id > :lastId')
                ->setParameter('source', $externalSource)
                ->setParameter('lastId', $lastId)
                ->orderBy('e.id', SortDirection::Ascending)
                ->setMaxResults($batchSize)
                ->getQuery()
                ->getResult();
            foreach ($batch as $entry) {
                $lastId = $entry->getId();

                yield $entry;
            }
        } while (count($batch) === $batchSize);
    }

    /**
     * Since 1.11: live entries (never imported) of one user started within the period, newest first.
     *
     * @return list<TimeEntry>
     */
    public function findLiveByUserAndPeriod(string $userId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        /** @var list<TimeEntry> $result */
        $result = $this->periodQuery([$userId], $from, $to)
            ->select('e')
            ->andWhere('e.source <> :import')
            ->setParameter('import', TimeEntrySource::Import->value)
            ->orderBy('e.startedAt', SortDirection::Descending)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Since 1.11: one page of the live entries of the users started within the period, newest first.
     *
     * @param list<string> $userIds
     *
     * @return list<TimeEntry>
     */
    public function findLivePageByUsersAndPeriod(array $userIds, DateTimeImmutable $from, DateTimeImmutable $to, int $offset, int $limit): array
    {
        if ($userIds === []) {
            return [];
        }

        /** @var list<TimeEntry> $result */
        $result = $this->periodQuery($userIds, $from, $to)
            ->select('e')
            ->andWhere('e.source <> :import')
            ->setParameter('import', TimeEntrySource::Import->value)
            ->orderBy('e.startedAt', SortDirection::Descending)
            ->addOrderBy('e.id', SortDirection::Descending)
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Since 1.11: number of live entries of the users started within the period.
     *
     * @param list<string> $userIds
     */
    public function countLiveByUsersAndPeriod(array $userIds, DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        if ($userIds === []) {
            return 0;
        }

        return (int) $this->periodQuery($userIds, $from, $to)
            ->select('COUNT(e.id)')
            ->andWhere('e.source <> :import')
            ->setParameter('import', TimeEntrySource::Import->value)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Since 1.11: persists the entries and flushes once.
     *
     * @param list<TimeEntry> $entries
     */
    public function saveAll(array $entries): void
    {
        foreach ($entries as $entry) {
            $this->entityManager->persist($entry);
        }
        RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
    }

    /**
     * Since 1.11: the entity manager is open again (reset through the registry after the failed flush) and no host
     * transaction is active.
     */
    public function canRetryAfterFailedSave(): bool
    {
        return $this->entityManager->isOpen() && !$this->entityManager->getConnection()->isTransactionActive();
    }

    /**
     * Since 1.11: the managed user, or a reference by identifier when a reset detached it.
     */
    public function attachUser(UserInterface $user): UserInterface
    {
        $userClass = $this->entityManager->getClassMetadata(TimeEntry::class)->getAssociationTargetClass('user');
        if (!$user instanceof $userClass || $this->entityManager->contains($user)) {
            return $user;
        }
        $metadata   = $this->entityManager->getClassMetadata($userClass);
        $identifier = $metadata->getIdentifierValues($user);
        $reference  = $identifier === [] ? null : $this->entityManager->getReference($metadata->getName(), $identifier);

        return $reference instanceof UserInterface ? $reference : $user;
    }

    /**
     * Since 1.11: deletes the imported entries of a scope by keyset batches (DQL deletes, no entity loaded).
     */
    public function removeImported(ImportedTimeEntryScope $scope, int $batchSize = 500): ImportedTimeEntryRemoval
    {
        $batchSize = max(1, $batchSize);
        $removal   = new ImportedTimeEntryRemoval();
        if ($scope->kind === ImportedTimeEntryScope::TASKS && $scope->values === []) {
            return $removal;
        }
        $lastId = '';
        do {
            $query = $this->entityManager->createQueryBuilder()
                ->select('e.id AS id', 'e.taskId AS taskId', 'e.durationSeconds AS seconds', 'IDENTITY(e.user) AS userId')
                ->from(TimeEntry::class, 'e')
                ->where('e.source = :import')
                ->andWhere('e.externalSource = :source')
                ->andWhere('e.id > :lastId')
                ->setParameter('import', TimeEntrySource::Import->value)
                ->setParameter('source', $scope->externalSource)
                ->setParameter('lastId', $lastId)
                ->orderBy('e.id', SortDirection::Ascending)
                ->setMaxResults($batchSize);
            match ($scope->kind) {
                ImportedTimeEntryScope::ENTRY => $query->andWhere('e.externalId = :value')->setParameter('value', $scope->values[0]),
                ImportedTimeEntryScope::USER  => $query->andWhere('IDENTITY(e.user) = :value')->setParameter('value', $scope->values[0]),
                ImportedTimeEntryScope::TASKS => $query->andWhere('e.taskId IN (:values)')->setParameter('values', $scope->values),
                ImportedTimeEntryScope::BOARD => $query->andWhere('e.boardIdSnapshot = :value')->setParameter('value', $scope->values[0]),
                default                       => null,
            };
            /** @var list<array{id: string, taskId: string, seconds: int|string, userId: int|string}> $rows */
            $rows = $query->getQuery()->getArrayResult();
            if ($rows === []) {
                break;
            }
            $seconds = [];
            foreach ($rows as $row) {
                $seconds[$row['taskId']] = ($seconds[$row['taskId']] ?? 0) + (int) $row['seconds'];
                $lastId                  = $row['id'];
            }
            $this->entityManager->createQueryBuilder()
                ->delete(TimeEntry::class, 'e')
                ->where('e.id IN (:ids)')
                ->setParameter('ids', array_column($rows, 'id'))
                ->getQuery()
                ->execute();
            $removal = $removal->merge(new ImportedTimeEntryRemoval(count($rows), $seconds, array_map(static fn (array $row): string => (string) $row['userId'], $rows)));
        } while (count($rows) === $batchSize);

        return $removal;
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
