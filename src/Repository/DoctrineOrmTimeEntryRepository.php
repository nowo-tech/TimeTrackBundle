<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
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
use Throwable;

use function array_chunk;
use function array_column;
use function array_map;
use function count;
use function max;

/**
 * Doctrine ORM store of the closed time entries (pages in the database, REQ-PERF-001).
 */
final readonly class DoctrineOrmTimeEntryRepository implements FilteredImportedTimeEntryRepositoryInterface, LatestTimeEntryRepositoryInterface, ScopeBackfillRepositoryInterface, EntryOwnersRepositoryInterface, TaskEffortRepositoryInterface, OverlappingTimeEntryRepositoryInterface, UserSerialisedTimeEntryRepositoryInterface
{
    /** Since 1.12: task ids bound in one `IN (...)` of a task-scope removal (below every driver's parameter limit). */
    public const TASK_IDS_PER_QUERY = 1000;

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
     * Since the GG fixes: one transaction holding the user's row lock (`SELECT … FOR UPDATE`) around the operation.
     */
    public function inUserTransaction(object $user, callable $operation): mixed
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            if ($this->entityManager->contains($user)) {
                $this->entityManager->lock($user, LockMode::PESSIMISTIC_WRITE);
            }
            $result = $operation();
            $connection->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            RecoveringFlusher::resetIfClosed($this->entityManager, $this->managerRegistry);

            throw $exception;
        }
    }

    /**
     * Since 1.14: live entries of the user overlapping `[from, to)` (manual entries and accepted suggestions refuse
     * overlaps).
     */
    public function findLiveOverlapping(string $userId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        /** @var list<TimeEntry> $result */
        $result = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(TimeEntry::class, 'e')
            ->where('IDENTITY(e.user) = :userId')
            ->andWhere('e.startedAt < :to')
            ->andWhere('e.endedAt > :from')
            ->andWhere('e.source <> :import')
            ->setParameter('userId', $userId)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('import', TimeEntrySource::Import->value)
            ->orderBy('e.startedAt', SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $result;
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
     * Since 1.11: deletes the imported entries of a scope by keyset batches (DQL deletes, no entity loaded). Since 1.12
     * a task list is bound {@see self::TASK_IDS_PER_QUERY} ids at a time (driver parameter limits), and entries of a
     * deleted row that this entity manager had loaded are detached (no stale entity is flushed or returned later).
     */
    public function removeImported(ImportedTimeEntryScope $scope, int $batchSize = 500): ImportedTimeEntryRemoval
    {
        return $this->removeImportedWhere($scope, static fn (): bool => false, $batchSize);
    }

    /**
     * Since 1.13: {@see self::removeImported()} keeping the rows `$keep` protects (the period lock).
     */
    public function removeImportedWhere(ImportedTimeEntryScope $scope, callable $keep, int $batchSize = 500): ImportedTimeEntryRemoval
    {
        $batchSize = max(1, $batchSize);
        $removal   = new ImportedTimeEntryRemoval();
        if ($scope->kind === ImportedTimeEntryScope::TASKS && $scope->values === []) {
            return $removal;
        }
        if ($scope->kind === ImportedTimeEntryScope::TASKS && count($scope->values) > self::TASK_IDS_PER_QUERY) {
            foreach (array_chunk($scope->values, self::TASK_IDS_PER_QUERY) as $taskIds) {
                $removal = $removal->merge($this->removeImportedWhere(ImportedTimeEntryScope::tasks($scope->externalSource, $taskIds), $keep, $batchSize));
            }

            return $removal;
        }
        $lastId = '';
        do {
            $query = $this->entityManager->createQueryBuilder()
                ->select('e.id AS id', 'e.taskId AS taskId', 'e.durationSeconds AS seconds', 'IDENTITY(e.user) AS userId', 'e.scopeKey AS scopeKey', 'e.startedAt AS startedAt')
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
            /** @var list<array{id: string, taskId: string, seconds: int|string, userId: int|string, scopeKey: string|null, startedAt: DateTimeImmutable}> $batch */
            $batch = $query->getQuery()->getArrayResult();
            if ($batch === []) {
                break;
            }
            $rows    = [];
            $seconds = [];
            foreach ($batch as $row) {
                $lastId = $row['id'];
                if ($keep(['id' => $row['id'], 'userId' => (string) $row['userId'], 'scopeKey' => $row['scopeKey'], 'startedAt' => $row['startedAt']])) {
                    continue;
                }
                $seconds[$row['taskId']] = ($seconds[$row['taskId']] ?? 0) + (int) $row['seconds'];
                $rows[]                  = $row;
            }
            if ($rows === []) {
                continue;
            }
            $this->entityManager->createQueryBuilder()
                ->delete(TimeEntry::class, 'e')
                ->where('e.id IN (:ids)')
                ->setParameter('ids', array_column($rows, 'id'))
                ->getQuery()
                ->execute();
            $this->detachRemoved(array_column($rows, 'id'));
            $removal = $removal->merge(new ImportedTimeEntryRemoval(count($rows), $seconds, array_map(static fn (array $row): string => (string) $row['userId'], $rows)));
        } while (count($batch) === $batchSize);

        return $removal;
    }

    /**
     * Since 1.13: total closed seconds per task (of one user, or every user), the ids bound
     * {@see self::TASK_IDS_PER_QUERY} at a time.
     */
    public function loggedSecondsByTask(array $taskIds, ?string $userId = null): array
    {
        $totals = [];
        foreach (array_chunk($taskIds, self::TASK_IDS_PER_QUERY) as $chunk) {
            $query = $this->entityManager->createQueryBuilder()
                ->select('e.taskId AS taskId', 'SUM(e.durationSeconds) AS seconds')
                ->from(TimeEntry::class, 'e')
                ->where('e.taskId IN (:ids)')
                ->setParameter('ids', $chunk)
                ->groupBy('e.taskId');
            if ($userId !== null) {
                // @igor-ignore - query builder created for this call, not a shared service.
                $query->andWhere('IDENTITY(e.user) = :user')->setParameter('user', $userId);
            }
            /** @var list<array{taskId: string, seconds: int|string}> $rows */
            $rows = $query->getQuery()->getArrayResult();
            foreach ($rows as $row) {
                $totals[$row['taskId']] = (int) $row['seconds'];
            }
        }

        return $totals;
    }

    /**
     * Since 1.13: distinct (owner, scope) pairs with scoped entries started in the window.
     */
    public function ownersWithEntries(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        /** @var list<array{userId: int|string, scopeKey: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(e.user) AS userId', 'e.scopeKey AS scopeKey')
            ->distinct()
            ->from(TimeEntry::class, 'e')
            ->where('e.scopeKey IS NOT NULL')
            ->andWhere('e.startedAt >= :from')
            ->andWhere('e.startedAt <= :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getArrayResult();
        $userClass = $this->entityManager->getClassMetadata(TimeEntry::class)->getAssociationTargetClass('user');
        $owners    = [];
        foreach ($rows as $row) {
            $user = $this->entityManager->find($userClass, $row['userId']);
            if ($user instanceof UserInterface) {
                $owners[] = ['user' => $user, 'scopeKey' => $row['scopeKey']];
            }
        }

        return $owners;
    }

    /**
     * Since 1.13: entries without a scope after an id (back-fill keyset).
     */
    public function findScopelessAfter(string $afterId, int $limit): array
    {
        /** @var list<TimeEntry> $result */
        $result = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(TimeEntry::class, 'e')
            ->where('e.scopeKey IS NULL')
            ->andWhere('e.id > :after')
            ->setParameter('after', $afterId)
            ->orderBy('e.id', SortDirection::Ascending)
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Since 1.13: sets the scope of still scopeless entries, one DQL update per scope, then detaches them.
     */
    public function assignScopeKeys(array $scopeKeys): int
    {
        $byScope = [];
        foreach ($scopeKeys as $entryId => $scopeKey) {
            $byScope[$scopeKey][] = (string) $entryId;
        }
        $changed = 0;
        foreach ($byScope as $scopeKey => $ids) {
            $changed += (int) $this->entityManager->createQueryBuilder()
                ->update(TimeEntry::class, 'e')
                ->set('e.scopeKey', ':scope')
                ->where('e.id IN (:ids)')
                ->andWhere('e.scopeKey IS NULL')
                ->setParameter('scope', (string) $scopeKey)
                ->setParameter('ids', $ids)
                ->getQuery()
                ->execute();
            $this->detachRemoved($ids);
        }

        return $changed;
    }

    /**
     * Detaches the loaded entities of rows a DQL delete removed (the identity map would keep them otherwise).
     *
     * @param list<string> $ids
     */
    private function detachRemoved(array $ids): void
    {
        $unitOfWork = $this->entityManager->getUnitOfWork();
        foreach ($ids as $id) {
            $loaded = $unitOfWork->tryGetById($id, TimeEntry::class);
            if ($loaded instanceof TimeEntry) {
                $this->entityManager->detach($loaded);
            }
        }
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
