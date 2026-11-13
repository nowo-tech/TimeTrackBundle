<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\Doctrine\RecoveringFlusher;
use Nowo\TimeTrackBundle\Doctrine\TimeTrackSavepoint;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryRemoval;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryScope;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\Timesheet\ScopeKeys;
use Nowo\TimeTrackBundle\UserRef\ExactUserIds;
use Psr\Log\LoggerInterface;
use SortDirection;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

use function array_chunk;
use function array_column;
use function array_filter;
use function array_map;
use function array_values;
use function count;
use function in_array;
use function max;

/**
 * Doctrine ORM store of the closed time entries (pages in the database, REQ-PERF-001).
 */
final readonly class DoctrineOrmTimeEntryRepository implements FilteredImportedTimeEntryRepositoryInterface, LatestTimeEntryRepositoryInterface, ScopeBackfillRepositoryInterface, EntryOwnersRepositoryInterface, TaskEffortRepositoryInterface, OverlappingTimeEntryRepositoryInterface, UserSerialisedTimeEntryRepositoryInterface, FormerPersonTimeEntryRepositoryInterface, ImportedTimeEntryOwnerRepositoryInterface, TaskTimeMoveRepositoryInterface, LockingFindableTimeEntryRepositoryInterface
{
    /** Since 1.12: task ids bound in one `IN (...)` of a task-scope removal (below every driver's parameter limit). */
    public const TASK_IDS_PER_QUERY = 1000;

    /** Since 1.15: entries one bulk statement of a release or an anonymisation touches. */
    public const FORMER_PERSON_BATCH = 500;

    /** Since 1.24.1: rows read per round while a listing is checked byte for byte before its page is cut. */
    private const EXACT_BATCH = 500;

    /**
     * @param EntityManagerInterface $entityManager Entity manager of the configured connection
     * @param ManagerRegistry|null $managerRegistry Resets a closed entity manager after a failed flush (worker mode)
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $managerRegistry = null,
        private ?LoggerInterface $logger = null,
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
     * Since 1.21: one entry by id.
     */
    public function findById(string $id): ?TimeEntry
    {
        return $this->entityManager->find(TimeEntry::class, $id);
    }

    /**
     * Since 1.21: the entry re-read with a pessimistic write lock (the caller holds a transaction).
     */
    public function findForUpdate(string $id): ?TimeEntry
    {
        $entry = $this->entityManager->find(TimeEntry::class, $id);
        if (!$entry instanceof TimeEntry) {
            return null;
        }
        $this->entityManager->refresh($entry, LockMode::PESSIMISTIC_WRITE);

        return $entry;
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

        return $this->exactOwners($result, [$userId]);
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

        return $this->exactOwners($result, $userIds);
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

        return $this->countOwned($this->periodQuery($userIds, $from, $to), $userIds);
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

        return $this->exactOwners($result, [$userId]);
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

        return $this->exactOwners($result, $userIds);
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

        return $this->countOwned($this->periodQuery($userIds, $from, $to)
            ->andWhere('e.source <> :import')
            ->setParameter('import', TimeEntrySource::Import->value), $userIds);
    }

    /**
     * Since the GG fixes: one transaction holding the user's row lock (`SELECT … FOR UPDATE`) around the operation.
     */
    public function inUserTransaction(object $user, callable $operation): mixed
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $this->lockUser($user);
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
     * Since 1.23.0: with the id user mapping, only the entries whose owner id equals one of the ids byte for byte
     * ({@see ExactUserIds}); the result as it is with the relation mapping.
     *
     * @param list<TimeEntry> $entries
     * @param list<string> $userIds
     *
     * @return list<TimeEntry>
     */
    private function exactOwners(array $entries, array $userIds): array
    {
        return ExactUserIds::keep($this->entityManager, TimeEntry::class, $entries, $userIds);
    }

    /**
     * The number of entries of the query (one of the users): a SQL `COUNT` with the relation mapping (1.22), and with
     * the id mapping (since 1.23.0, audit OA-L2) the owners counted in PHP, byte for byte.
     *
     * @param list<string> $userIds
     */
    private function countOwned(QueryBuilder $query, array $userIds): int
    {
        if (!ExactUserIds::applies($this->entityManager, TimeEntry::class)) {
            return (int) $query->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();
        }

        $count = 0;
        /** @var list<array{owner: string|null}> $rows */
        $rows = $query->select('IDENTITY(e.user) AS owner')->getQuery()->getArrayResult();
        foreach ($rows as $row) {
            $count += in_array($row['owner'], $userIds, true) ? 1 : 0;
        }

        return $count;
    }

    /**
     * The user's row lock: on the managed entity, else (a user from another manager or provider, delta GG-D-2) by id
     * through the mapped user class; a warning when no row could be locked.
     */
    private function lockUser(object $user): void
    {
        if ($this->entityManager->contains($user)) {
            $this->entityManager->lock($user, LockMode::PESSIMISTIC_WRITE);

            return;
        }
        $class  = $this->entityManager->getClassMetadata(TimeEntry::class)->getAssociationTargetClass('user');
        $locked = $user instanceof UserInterface ? $this->entityManager->find($class, UserIdResolver::getId($user), LockMode::PESSIMISTIC_WRITE) : null;
        if ($locked === null) {
            $this->logger?->warning('TimeTrack could not lock the user row; this write is not serialised.', ['bundle' => 'time_track', 'action' => 'entry_lock']);
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

        return $this->exactOwners($result, [$userId]);
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
                ->select('e.id AS id', 'e.taskId AS taskId', 'e.durationSeconds AS seconds', 'IDENTITY(e.user) AS userId', 'e.personRef AS personRef', 'e.scopeKey AS scopeKey', 'e.startedAt AS startedAt')
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
            /** @var list<array{id: string, taskId: string, seconds: int|string, userId: int|string|null, personRef: string|null, scopeKey: string|null, startedAt: DateTimeImmutable}> $batch */
            $batch = $query->getQuery()->getArrayResult();
            if ($batch === []) {
                break;
            }
            $rows    = [];
            $seconds = [];
            $kept    = 0;
            foreach ($batch as $row) {
                $lastId = $row['id'];
                if ($keep(['id' => $row['id'], 'userId' => $row['userId'] === null ? null : (string) $row['userId'], 'personRef' => $row['personRef'], 'scopeKey' => $row['scopeKey'], 'startedAt' => $row['startedAt']])) {
                    ++$kept;
                    continue;
                }
                $seconds[$row['taskId']] = ($seconds[$row['taskId']] ?? 0) + (int) $row['seconds'];
                $rows[]                  = $row;
            }
            if ($rows === []) {
                $removal = $removal->merge(new ImportedTimeEntryRemoval(kept: $kept));
                continue;
            }
            TimeTrackSavepoint::execute($this->entityManager->createQueryBuilder()
                ->delete(TimeEntry::class, 'e')
                ->where('e.id IN (:ids)')
                ->setParameter('ids', array_column($rows, 'id'))
                ->getQuery(), $this->entityManager);
            $this->detachRemoved(array_column($rows, 'id'));
            // Since 1.15 an ownerless entry has no owner id to report.
            $owners  = array_filter(array_column($rows, 'userId'), static fn (int|string|null $id): bool => $id !== null);
            $people  = array_filter(array_column($rows, 'personRef'), static fn (?string $ref): bool => $ref !== null);
            $removal = $removal->merge(new ImportedTimeEntryRemoval(count($rows), $seconds, array_map(strval(...), array_values($owners)), array_values($people), $kept));
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
        // Since 1.24.1 (REQ-SEC-006): the pairs are told apart byte for byte in PHP (a SQL DISTINCT on a still-folding
        // column merged `acme` and `Acme`, so one of them never had its timesheet locked), reading the entries in keyset
        // batches (audit OM TT-I1): only the distinct pairs stay in memory.
        $query = $this->entityManager->createQueryBuilder()
            ->select('e.id AS id', 'IDENTITY(e.user) AS userId', 'e.scopeKey AS scopeKey')
            ->from(TimeEntry::class, 'e')
            ->where('e.scopeKey IS NOT NULL')
            // Since 1.15: ownerless entries have no timesheet to create.
            ->andWhere('e.user IS NOT NULL')
            ->andWhere('e.startedAt >= :from')
            ->andWhere('e.startedAt <= :to')
            ->andWhere('e.id > :after')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('e.id', SortDirection::Ascending)
            ->setMaxResults(self::EXACT_BATCH);
        $rows  = [];
        $after = '';
        do {
            // @igor-ignore - query builder created for this call, not a shared service.
            /** @var list<array{id: string, userId: int|string, scopeKey: string}> $batch */
            $batch = $query->setParameter('after', $after)->getQuery()->getArrayResult();
            foreach ($batch as $row) {
                $after                                                   = $row['id'];
                $rows[(string) $row['userId'] . "\0" . $row['scopeKey']] = $row;
            }
        } while (count($batch) === self::EXACT_BATCH);
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
     * Since 1.13: entries without a scope after an id (back-fill keyset). Since 1.15 only entries with a user: the scope
     * provider places entries by their owner, and an ownerless entry belongs to no timesheet.
     */
    public function findScopelessAfter(string $afterId, int $limit): array
    {
        /** @var list<TimeEntry> $result */
        $result = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(TimeEntry::class, 'e')
            ->where('e.scopeKey IS NULL')
            ->andWhere('e.user IS NOT NULL')
            ->andWhere('e.id > :after')
            ->setParameter('after', $afterId)
            ->orderBy('e.id', SortDirection::Ascending)
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Since 1.13: sets the scope of still scopeless entries, one DQL update per scope, then detaches them. Since 1.24.1
     * (REQ-SEC-006) a key that is not usable ({@see ScopeKeys::isUsable()}) is refused before anything is written.
     *
     * @throws InvalidArgumentException When a scope key is not usable
     */
    public function assignScopeKeys(array $scopeKeys): int
    {
        $byScope = [];
        foreach ($scopeKeys as $entryId => $scopeKey) {
            $byScope[ScopeKeys::assertUsable((string) $scopeKey)][] = (string) $entryId;
        }
        $changed = 0;
        foreach ($byScope as $scopeKey => $ids) {
            $changed += (int) TimeTrackSavepoint::execute($this->entityManager->createQueryBuilder()
                ->update(TimeEntry::class, 'e')
                ->set('e.scopeKey', ':scope')
                ->where('e.id IN (:ids)')
                ->andWhere('e.scopeKey IS NULL')
                ->setParameter('scope', (string) $scopeKey)
                ->setParameter('ids', $ids)
                ->getQuery(), $this->entityManager);
            $this->detachRemoved($ids);
        }

        return $changed;
    }

    /**
     * Since 1.16: one transaction, nested into the caller's when there is one.
     */
    public function inOwnerMoveTransaction(callable $operation): mixed
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $result = $operation();
            $connection->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Since 1.16: imported entries of exactly this source held by this owner, by keyset, locked until the move's commit: what
     * this returns is what the update changes, so the moved seconds are exact and a concurrent move waits. Since 1.16.1 (audit
     * HM-L7) the ids are read with no lock and the rows are then locked by primary key, which takes record locks only: on MySQL a
     * locking read through the source's index also locked its gaps, so a sync into that source or next to it waited for
     * the whole move or deadlocked. A row is checked again once locked, because it may have moved in between.
     */
    public function findForOwnerMove(string $externalSource, ?string $fromUserId, ?string $fromPersonRef, ?array $externalIds, string $afterId, int $limit): array
    {
        do {
            $query = $this->entityManager->createQueryBuilder()
                ->select('e.id AS id')
                ->from(TimeEntry::class, 'e')
                ->where('e.source = :import')
                ->andWhere('e.externalSource = :source')
                ->andWhere('e.anonymisedAt IS NULL')
                ->andWhere('e.id > :after')
                ->setParameter('import', TimeEntrySource::Import->value)
                ->setParameter('source', $externalSource)
                ->setParameter('after', $afterId)
                ->orderBy('e.id', SortDirection::Ascending)
                ->setMaxResults(max(1, $limit));
            $this->whereOwner($query, $fromUserId, $fromPersonRef);
            if ($externalIds !== null) {
                $query->andWhere('e.externalId IN (:externalIds)')->setParameter('externalIds', $externalIds);
            }
            /** @var list<string> $ids */
            $ids = array_column($query->getQuery()->getArrayResult(), 'id');
            if ($ids === []) {
                return [];
            }
            $afterId = $ids[count($ids) - 1];

            /** @var list<array{id: string, externalSource: string, externalId: string, taskId: string, boardId: string|null, scopeKey: string|null, seconds: int|string, startedAt: DateTimeImmutable, personRef: string|null, userId: int|string|null, anonymisedAt: DateTimeImmutable|null}> $locked */
            $locked = $this->entityManager->createQueryBuilder()
                ->select('e.id AS id', 'e.externalSource AS externalSource', 'e.externalId AS externalId', 'e.taskId AS taskId', 'e.boardIdSnapshot AS boardId', 'e.scopeKey AS scopeKey', 'e.durationSeconds AS seconds', 'e.startedAt AS startedAt', 'e.personRef AS personRef', 'IDENTITY(e.user) AS userId', 'e.anonymisedAt AS anonymisedAt')
                ->from(TimeEntry::class, 'e')
                ->where('e.id IN (:ids)')
                ->setParameter('ids', $ids)
                ->orderBy('e.id', SortDirection::Ascending)
                ->getQuery()
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->getArrayResult();
            $rows = array_values(array_filter(
                $locked,
                static fn (array $row): bool => $row['externalSource'] === $externalSource
                    && $row['anonymisedAt'] === null
                    && ($fromUserId !== null ? (string) $row['userId'] === $fromUserId : ($row['userId'] === null && $row['personRef'] === $fromPersonRef)),
            ));
        } while ($rows === []);

        return $rows;
    }

    /**
     * Since 1.16: gives these entries a new owner. Since 1.16.1 by primary key alone: {@see self::findForOwnerMove()} has locked them
     * and checked their owner, and another index here would lock its gaps.
     */
    public function applyOwnerMove(array $entryIds, ?string $fromUserId, ?string $fromPersonRef, ?object $toUser, ?string $toPersonRef, ?string $toPersonName): int
    {
        $update = $this->entityManager->createQueryBuilder()->update(TimeEntry::class, 'e')->where('e.id IN (:ids)')->setParameter('ids', $entryIds);
        if ($toUser !== null) {
            $update->set('e.user', ':toUser')->set('e.personRef', 'NULL')->set('e.personNameSnapshot', 'NULL')->setParameter('toUser', $toUser);
        } else {
            $update->set('e.user', 'NULL')->set('e.personRef', ':toRef')->set('e.personNameSnapshot', ':toName')->setParameter('toRef', $toPersonRef)->setParameter('toName', $toPersonName);
        }
        $changed = (int) TimeTrackSavepoint::execute($update->getQuery(), $this->entityManager);
        $this->detachRemoved($entryIds);

        return $changed;
    }

    /**
     * Since 1.17: {@see self::inOwnerMoveTransaction()} for a batch of a task move.
     */
    public function inTaskMoveTransaction(callable $operation): mixed
    {
        return $this->inOwnerMoveTransaction($operation);
    }

    /**
     * Since 1.17: entries of these tasks by keyset, locked until the batch's commit, the way {@see self::findForOwnerMove()} does:
     * the ids are read with no lock, then the rows are locked by primary key (record locks only) and their task is checked again.
     */
    public function findForTaskMove(array $taskIds, string $afterId, int $limit): array
    {
        if ($taskIds === []) {
            return [];
        }
        do {
            /** @var list<string> $ids */
            $ids = array_column($this->entityManager->createQueryBuilder()
                ->select('e.id AS id')
                ->from(TimeEntry::class, 'e')
                ->where('e.taskId IN (:taskIds)')
                ->andWhere('e.id > :after')
                ->setParameter('taskIds', $taskIds)
                ->setParameter('after', $afterId)
                ->orderBy('e.id', SortDirection::Ascending)
                ->setMaxResults(max(1, $limit))
                ->getQuery()
                ->getArrayResult(), 'id');
            if ($ids === []) {
                return [];
            }
            $afterId = $ids[count($ids) - 1];

            /** @var list<array{id: string, taskId: string, boardId: string|null, scopeKey: string|null, seconds: int|string, startedAt: DateTimeImmutable, personRef: string|null, userId: int|string|null, anonymisedAt: DateTimeImmutable|null}> $locked */
            $locked = $this->entityManager->createQueryBuilder()
                ->select('e.id AS id', 'e.taskId AS taskId', 'e.boardIdSnapshot AS boardId', 'e.scopeKey AS scopeKey', 'e.durationSeconds AS seconds', 'e.startedAt AS startedAt', 'e.personRef AS personRef', 'IDENTITY(e.user) AS userId', 'e.anonymisedAt AS anonymisedAt')
                ->from(TimeEntry::class, 'e')
                ->where('e.id IN (:ids)')
                ->setParameter('ids', $ids)
                ->orderBy('e.id', SortDirection::Ascending)
                ->getQuery()
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->getArrayResult();
            $rows = array_values(array_filter($locked, static fn (array $row): bool => in_array($row['taskId'], $taskIds, true)));
        } while ($rows === []);

        return $rows;
    }

    /**
     * Since 1.17: sets the board snapshot and scope of these entries by primary key alone ({@see self::findForTaskMove()} has
     * locked them). Nothing else changes.
     */
    public function applyTaskMove(array $entryIds, ?string $boardId, ?string $scopeKey): int
    {
        if ($scopeKey !== null) {
            // Since 1.24.1 (REQ-SEC-006): never a scope key a look-alike could stand for.
            ScopeKeys::assertUsable($scopeKey);
        }
        $changed = (int) TimeTrackSavepoint::execute($this->entityManager->createQueryBuilder()
            ->update(TimeEntry::class, 'e')
            ->set('e.boardIdSnapshot', ':boardId')
            ->set('e.scopeKey', ':scopeKey')
            ->where('e.id IN (:ids)')
            ->setParameter('boardId', $boardId)
            ->setParameter('scopeKey', $scopeKey)
            ->setParameter('ids', $entryIds)
            ->getQuery(), $this->entityManager);
        $this->detachRemoved($entryIds);

        return $changed;
    }

    private function whereOwner(QueryBuilder $query, ?string $userId, ?string $personRef): void
    {
        if ($userId !== null) {
            $query->andWhere('IDENTITY(e.user) = :fromUser')->setParameter('fromUser', $userId);

            return;
        }
        $query->andWhere('e.user IS NULL')->andWhere('e.personRef = :fromRef')->setParameter('fromRef', $personRef);
    }

    /**
     * Since 1.15: ids of the user's entries, by keyset. Reads only.
     */
    public function entryIdsOfOwner(string $userId): array
    {
        $ids    = [];
        $lastId = '';
        do {
            /** @var list<array{id: string}> $rows */
            $rows = $this->entityManager->createQueryBuilder()
                ->select('e.id AS id')
                ->from(TimeEntry::class, 'e')
                ->where('IDENTITY(e.user) = :owner')
                ->andWhere('e.id > :lastId')
                ->setParameter('owner', $userId)
                ->setParameter('lastId', $lastId)
                ->orderBy('e.id', SortDirection::Ascending)
                ->setMaxResults(self::FORMER_PERSON_BATCH)
                ->getQuery()
                ->getArrayResult();
            foreach ($rows as $row) {
                $ids[]  = $row['id'];
                $lastId = $row['id'];
            }
        } while (count($rows) === self::FORMER_PERSON_BATCH);

        return $ids;
    }

    /**
     * Since 1.15: the name and reference on these entries of a deleted user. Clears the user too, for a database that does not
     * enforce the foreign key. Since 1.16 (audit HM-L1) only rows that have not found another owner since they were read: no user and
     * no reference, or still that user. Already anonymised rows are left alone.
     */
    public function settleDeletedOwner(array $entryIds, string $personRef, ?string $name): int
    {
        $changed = 0;
        foreach (array_chunk($entryIds, self::FORMER_PERSON_BATCH) as $ids) {
            $changed += (int) TimeTrackSavepoint::execute($this->entityManager->createQueryBuilder()
                ->update(TimeEntry::class, 'e')
                ->set('e.user', 'NULL')
                ->set('e.personRef', ':ref')
                ->set('e.personNameSnapshot', ':name')
                ->where('e.id IN (:ids)')
                ->andWhere('e.anonymisedAt IS NULL')
                ->andWhere('(e.user IS NULL AND e.personRef IS NULL) OR IDENTITY(e.user) = :deleted')
                ->setParameter('deleted', (string) TimeEntry::formerUserIdOf($personRef))
                ->setParameter('ref', $personRef)
                ->setParameter('name', $name)
                ->setParameter('ids', $ids)
                ->getQuery(), $this->entityManager);
        }

        return $changed;
    }

    /**
     * Since 1.15: anonymises these entries of a deleted user. Since 1.16 (audit HM-L1) only rows that have not found another owner
     * since they were read: no user and no reference. Already anonymised rows are left alone.
     */
    public function anonymiseEntries(array $entryIds, DateTimeImmutable $at): int
    {
        $changed = 0;
        foreach (array_chunk($entryIds, self::FORMER_PERSON_BATCH) as $ids) {
            $changed += $this->anonymiseWhere(static fn (QueryBuilder $query): QueryBuilder => $query->andWhere('e.id IN (:ids)')->andWhere('e.user IS NULL')->andWhere('e.personRef IS NULL')->setParameter('ids', $ids), $at);
        }

        return $changed;
    }

    /**
     * Since 1.15: detaches the user's entries from the user, with the snapshot.
     */
    public function releaseOwner(string $userId, string $personRef, ?string $name): int
    {
        return $this->updateInBatches(
            static fn (QueryBuilder $query): QueryBuilder => $query->andWhere('IDENTITY(e.user) = :owner')->setParameter('owner', $userId),
            static fn (QueryBuilder $update): QueryBuilder => $update->set('e.user', 'NULL')->set('e.personRef', ':ref')->set('e.personNameSnapshot', ':name')->setParameter('ref', $personRef)->setParameter('name', $name),
        );
    }

    /**
     * Since 1.15: anonymises the user's entries.
     */
    public function anonymiseOwner(string $userId, DateTimeImmutable $at): int
    {
        return $this->anonymiseWhere(static fn (QueryBuilder $query): QueryBuilder => $query->andWhere('IDENTITY(e.user) = :owner')->setParameter('owner', $userId), $at);
    }

    /**
     * Since 1.15: anonymises the entries of a person reference. A row is matched again in PHP: a MySQL collation may fold case.
     */
    public function anonymisePerson(string $personRef, DateTimeImmutable $at): int
    {
        return $this->anonymiseWhere(
            static fn (QueryBuilder $query): QueryBuilder => $query->andWhere('e.personRef = :ref')->andWhere('e.user IS NULL')->setParameter('ref', $personRef),
            $at,
            static fn (array $row): bool => $row['personRef'] === $personRef,
        );
    }

    /**
     * Since 1.15: anonymises the ownerless entries with no person reference.
     */
    public function anonymiseUnidentified(DateTimeImmutable $at): int
    {
        return $this->anonymiseWhere(static fn (QueryBuilder $query): QueryBuilder => $query->andWhere('e.user IS NULL')->andWhere('e.personRef IS NULL'), $at);
    }

    /**
     * Since 1.15: anonymises the ownerless entries that ended before the cut-off.
     */
    public function anonymiseOwnerlessEndedBefore(DateTimeImmutable $cutOff, DateTimeImmutable $at): int
    {
        return $this->anonymiseWhere(static fn (QueryBuilder $query): QueryBuilder => $query->andWhere('e.user IS NULL')->andWhere('e.endedAt < :cut')->setParameter('cut', $cutOff), $at);
    }

    /**
     * Since 1.15: entries of a person reference after an id (keyset).
     */
    public function findByPersonRef(string $personRef, string $afterId, int $limit): array
    {
        /** @var list<TimeEntry> $result */
        $result = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(TimeEntry::class, 'e')
            ->where('e.personRef = :ref')
            ->andWhere('e.user IS NULL')
            ->andWhere('e.id > :after')
            ->setParameter('ref', $personRef)
            ->setParameter('after', $afterId)
            ->orderBy('e.id', SortDirection::Ascending)
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Since 1.15: one page of the users' entries and the ownerless entries, newest first.
     */
    public function findPageWithOwnerless(array $userIds, ?array $ownerlessScopeKeys, DateTimeImmutable $from, DateTimeImmutable $to, bool $includeImported, int $offset, int $limit): array
    {
        $query = $this->ownerlessPeriodQuery($userIds, $ownerlessScopeKeys, $from, $to, $includeImported)
            ->select('e')
            ->orderBy('e.startedAt', SortDirection::Descending)
            ->addOrderBy('e.id', SortDirection::Descending);
        $offset = max(0, $offset);
        $limit  = max(1, $limit);
        if (!$this->ownerlessNeedsExactCheck($ownerlessScopeKeys)) {
            /** @var list<TimeEntry> $result */
            // @igor-ignore - query builder created for this call, not a shared service.
            $result = $query->setFirstResult($offset)->setMaxResults($limit)->getQuery()->getResult();

            return $result;
        }

        // Since 1.24.1 (REQ-SEC-006): the owners (id mapping) and the ownerless scopes are checked byte for byte before
        // the page is cut, so a look-alike's entry neither shows nor shifts the pages.
        $kept    = [];
        $skipped = 0;
        $read    = 0;
        do {
            /** @var list<TimeEntry> $rows */
            // @igor-ignore - query builder created for this call, not a shared service.
            $rows = $query->setFirstResult($read)->setMaxResults(self::EXACT_BATCH)->getQuery()->getResult();
            $read += self::EXACT_BATCH;
            foreach ($rows as $entry) {
                if (!$this->isListed($entry->getUserId(), $entry->getScopeKey(), $userIds, $ownerlessScopeKeys)) {
                    continue;
                }
                if ($skipped < $offset) {
                    ++$skipped;
                    continue;
                }
                $kept[] = $entry;
                if (count($kept) === $limit) {
                    return $kept;
                }
            }
        } while (count($rows) === self::EXACT_BATCH);

        return $kept;
    }

    /**
     * Since 1.15: number of the users' entries and the ownerless entries of the period. Since 1.24.1 (REQ-SEC-006) the
     * owners (id mapping) and the ownerless scopes are counted in PHP, byte for byte, when they need it.
     */
    public function countWithOwnerless(array $userIds, ?array $ownerlessScopeKeys, DateTimeImmutable $from, DateTimeImmutable $to, bool $includeImported): int
    {
        $query = $this->ownerlessPeriodQuery($userIds, $ownerlessScopeKeys, $from, $to, $includeImported);
        if (!$this->ownerlessNeedsExactCheck($ownerlessScopeKeys)) {
            return (int) $query->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();
        }

        // Since 1.24.1 (audit OM TT-I1): the rows are read in keyset batches, so only one batch is held in memory. Each
        // batch builds its own query (never a builder kept across the loop, REQ-RUNTIME-002).
        $count = 0;
        $after = '';
        do {
            /** @var list<array{id: string, owner: int|string|null, scopeKey: string|null}> $batch */
            $batch = $this->ownerlessPeriodQuery($userIds, $ownerlessScopeKeys, $from, $to, $includeImported)
                ->select('e.id AS id', 'IDENTITY(e.user) AS owner', 'e.scopeKey AS scopeKey')
                ->andWhere('e.id > :afterEntry')
                ->setParameter('afterEntry', $after)
                ->orderBy('e.id', SortDirection::Ascending)
                ->setMaxResults(self::EXACT_BATCH)
                ->getQuery()
                ->getArrayResult();
            foreach ($batch as $row) {
                $after = $row['id'];
                $count += $this->isListed($row['owner'] === null ? null : (string) $row['owner'], $row['scopeKey'], $userIds, $ownerlessScopeKeys) ? 1 : 0;
            }
        } while (count($batch) === self::EXACT_BATCH);

        return $count;
    }

    /**
     * Since 1.24.1 (REQ-SEC-006): whether the ownerless listing must be checked in PHP: a scope list (scope keys compare
     * byte for byte) or the id mapping (owner ids compare byte for byte).
     *
     * @param list<string>|null $ownerlessScopeKeys
     */
    private function ownerlessNeedsExactCheck(?array $ownerlessScopeKeys): bool
    {
        return ($ownerlessScopeKeys !== null && $ownerlessScopeKeys !== []) || ExactUserIds::applies($this->entityManager, TimeEntry::class);
    }

    /**
     * Since 1.24.1 (REQ-SEC-006): whether a row the ownerless query returned belongs to the listing byte for byte: an
     * owned entry of one of the users (compared exactly with the id mapping; the SQL match with the relation mapping, as
     * before), or an ownerless entry in one of the scopes (any ownerless entry without a scope list).
     *
     * @param list<string> $userIds
     * @param list<string>|null $ownerlessScopeKeys
     */
    private function isListed(?string $owner, ?string $scopeKey, array $userIds, ?array $ownerlessScopeKeys): bool
    {
        if ($owner !== null) {
            return !ExactUserIds::applies($this->entityManager, TimeEntry::class) || in_array($owner, $userIds, true);
        }

        return $ownerlessScopeKeys === null || ScopeKeys::isOneOf($scopeKey, $ownerlessScopeKeys);
    }

    /**
     * @param list<string> $userIds
     * @param list<string>|null $ownerlessScopeKeys
     */
    private function ownerlessPeriodQuery(array $userIds, ?array $ownerlessScopeKeys, DateTimeImmutable $from, DateTimeImmutable $to, bool $includeImported): QueryBuilder
    {
        $query = $this->entityManager->createQueryBuilder()
            ->from(TimeEntry::class, 'e')
            ->where('e.startedAt >= :from')
            ->andWhere('e.startedAt <= :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to);
        // An empty scope list lists no ownerless entry.
        $ownerless = match (true) {
            $ownerlessScopeKeys === null => 'e.user IS NULL',
            $ownerlessScopeKeys === []   => '1 = 0',
            default                      => 'e.user IS NULL AND e.scopeKey IN (:ownerlessScopes)',
        };
        if ($ownerlessScopeKeys !== null && $ownerlessScopeKeys !== []) {
            $query->setParameter('ownerlessScopes', $ownerlessScopeKeys);
        }
        if ($userIds === []) {
            $query->andWhere($ownerless);
        } else {
            $query->andWhere('IDENTITY(e.user) IN (:userIds) OR (' . $ownerless . ')')->setParameter('userIds', $userIds);
        }
        if (!$includeImported) {
            $query->andWhere('e.source <> :import')->setParameter('import', TimeEntrySource::Import->value);
        }

        return $query;
    }

    /**
     * One statement per row, because the external id and the metadata depend on the row; no entity loaded, no flush. Same
     * result as {@see TimeEntry::anonymiseInPlace()}. Already anonymised rows are skipped.
     *
     * @param callable(QueryBuilder): QueryBuilder $filter
     * @param (callable(array{id: string, externalSource: string|null, externalId: string|null, metadata: array<string, mixed>|null, personRef: string|null}): bool)|null $accept
     */
    private function anonymiseWhere(callable $filter, DateTimeImmutable $at, ?callable $accept = null): int
    {
        $changed = 0;
        $lastId  = '';
        do {
            /** @var list<array{id: string, externalSource: string|null, externalId: string|null, metadata: array<string, mixed>|null, personRef: string|null}> $rows */
            $rows = $filter($this->entityManager->createQueryBuilder()
                ->select('e.id AS id', 'e.externalSource AS externalSource', 'e.externalId AS externalId', 'e.metadata AS metadata', 'e.personRef AS personRef')
                ->from(TimeEntry::class, 'e')
                ->where('e.id > :lastId')
                ->andWhere('e.anonymisedAt IS NULL')
                ->setParameter('lastId', $lastId))
                ->orderBy('e.id', SortDirection::Ascending)
                ->setMaxResults(self::FORMER_PERSON_BATCH)
                ->getQuery()
                ->getArrayResult();
            foreach ($rows as $row) {
                $lastId = $row['id'];
                if ($accept !== null && !$accept($row)) {
                    // Needs a case-insensitive collation: testReferencesThatDifferOnlyByCase… on MySQL in CI.
                    // @codeCoverageIgnoreStart
                    continue;
                    // @codeCoverageIgnoreEnd
                }
                $changed += (int) TimeTrackSavepoint::execute($this->entityManager->createQueryBuilder()
                    ->update(TimeEntry::class, 'e')
                    ->set('e.user', 'NULL')
                    ->set('e.personRef', 'NULL')
                    ->set('e.personNameSnapshot', 'NULL')
                    ->set('e.description', 'NULL')
                    ->set('e.taskTitleSnapshot', ':title')
                    ->set('e.externalId', ':externalId')
                    ->set('e.metadata', ':metadata')
                    ->set('e.anonymisedAt', ':at')
                    ->where('e.id = :id')
                    ->setParameter('title', '')
                    ->setParameter('externalId', $row['externalSource'] !== null && $row['externalId'] !== null ? TimeEntry::anonymisedExternalId($row['externalSource'], $row['externalId']) : null)
                    ->setParameter('metadata', TimeEntry::anonymisedMetadata($row['metadata']), Types::JSON)
                    ->setParameter('at', $at, Types::DATETIME_IMMUTABLE)
                    ->setParameter('id', $row['id'])
                    ->getQuery(), $this->entityManager);
            }
            $this->detachRemoved(array_column($rows, 'id'));
        } while (count($rows) === self::FORMER_PERSON_BATCH);

        return $changed;
    }

    /**
     * Bulk statements only (no entity loaded, no flush: safe inside a host's erasure transaction), by keyset on the id;
     * entries this entity manager had loaded are detached.
     *
     * @param callable(QueryBuilder): QueryBuilder $filter
     * @param callable(QueryBuilder): QueryBuilder $apply
     */
    private function updateInBatches(callable $filter, callable $apply): int
    {
        $changed = 0;
        $lastId  = '';
        do {
            /** @var list<array{id: string}> $rows */
            $rows = $filter($this->entityManager->createQueryBuilder()->select('e.id AS id')->from(TimeEntry::class, 'e')->where('e.id > :lastId')->setParameter('lastId', $lastId))
                ->orderBy('e.id', SortDirection::Ascending)
                ->setMaxResults(self::FORMER_PERSON_BATCH)
                ->getQuery()
                ->getArrayResult();
            if ($rows === []) {
                break;
            }
            $ids    = array_column($rows, 'id');
            $lastId = $ids[count($ids) - 1];
            $changed += (int) TimeTrackSavepoint::execute($apply($this->entityManager->createQueryBuilder()->update(TimeEntry::class, 'e'))
                ->where('e.id IN (:ids)')
                ->setParameter('ids', $ids)
                ->getQuery(), $this->entityManager);
            $this->detachRemoved($ids);
        } while (count($rows) === self::FORMER_PERSON_BATCH);

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
