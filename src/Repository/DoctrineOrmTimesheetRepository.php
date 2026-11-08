<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use DateTimeImmutable;
use Doctrine\Common\Collections\Order as SortDirection;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TimeTrackBundle\Doctrine\RecoveringFlusher;
use Nowo\TimeTrackBundle\Entity\BalanceLedgerLine;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Entity\TimesheetEvent;
use Nowo\TimeTrackBundle\Enum\TimesheetPeriodKind;
use Nowo\TimeTrackBundle\Enum\TimesheetStatus;
use Nowo\TimeTrackBundle\Timesheet\Period;
use Throwable;

use function array_chunk;
use function array_column;
use function array_map;
use function count;
use function max;

/**
 * Since 1.13: Doctrine ORM timesheet store (timesheets, plus the retention purge and the verify listing).
 */
final readonly class DoctrineOrmTimesheetRepository implements TimesheetRepositoryInterface, TimesheetRetentionRepositoryInterface, FormerOwnerTimesheetRepositoryInterface, LockingGuardTimesheetRepositoryInterface
{
    /** Ids bound per `IN (...)` of the purge. */
    public const IDS_PER_QUERY = 1000;

    /**
     * Receives the entity manager (and the registry that resets it after a failed flush in worker mode).
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $managerRegistry = null,
    ) {
    }

    /**
     * Persists and flushes a timesheet.
     */
    public function save(Timesheet $timesheet): void
    {
        $this->entityManager->persist($timesheet);
        RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
    }

    /**
     * Runs the operation in one transaction (flushed at the end; rolled back and the manager reset on failure).
     */
    public function transactional(callable $operation): mixed
    {
        try {
            return $this->entityManager->wrapInTransaction(static fn (): mixed => $operation());
        } catch (Throwable $exception) {
            RecoveringFlusher::resetIfClosed($this->entityManager, $this->managerRegistry);

            throw $exception;
        }
    }

    /**
     * The timesheet with this id.
     */
    public function find(string $id): ?Timesheet
    {
        return $this->entityManager->find(Timesheet::class, $id);
    }

    /**
     * The timesheet of an owner, scope and period.
     */
    public function findForPeriod(string $ownerId, string $scopeKey, TimesheetPeriodKind $kind, string $periodStart): ?Timesheet
    {
        /** @var Timesheet|null $timesheet */
        $timesheet = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(Timesheet::class, 't')
            ->where('IDENTITY(t.user) = :owner')
            ->andWhere('t.scopeKey = :scope')
            ->andWhere('t.periodKind = :kind')
            ->andWhere('t.periodStart = :start')
            ->setParameter('owner', $ownerId)
            ->setParameter('scope', $scopeKey)
            ->setParameter('kind', $kind->value)
            ->setParameter('start', Period::date($periodStart), Types::DATE_IMMUTABLE)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $timesheet;
    }

    /**
     * Locked or submitted timesheets of the owner overlapping the dates (one scope or all).
     */
    public function findGuarding(string $ownerId, ?string $scopeKey, string $fromDate, string $toDate): array
    {
        return $this->guarding('IDENTITY(t.user) = :owner', $ownerId, $scopeKey, $fromDate, $toDate);
    }

    /**
     * Since 1.15: the same by `owner_ref`, which outlives the account.
     */
    public function findGuardingByOwnerRef(string $ownerRef, ?string $scopeKey, string $fromDate, string $toDate): array
    {
        return $this->guarding('t.ownerRef = :owner', $ownerRef, $scopeKey, $fromDate, $toDate);
    }

    /**
     * Since 1.16: the same by `owner_ref` under a shared lock, inside the caller's transaction. Since 1.16.1 a managed timesheet is not
     * refreshed, so a pending change on it survives: the guard needs only that the database returned the row, and its period, which
     * never changes.
     */
    public function findGuardingForMove(string $ownerRef, ?string $scopeKey, string $fromDate, string $toDate): array
    {
        return $this->guarding('t.ownerRef = :owner', $ownerRef, $scopeKey, $fromDate, $toDate, LockMode::PESSIMISTIC_READ);
    }

    /**
     * @return list<Timesheet>
     */
    private function guarding(string $ownerCondition, string $owner, ?string $scopeKey, string $fromDate, string $toDate, ?LockMode $lock = null): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(Timesheet::class, 't')
            ->where($ownerCondition)
            ->andWhere('t.periodEnd >= :from')
            ->andWhere('t.periodStart <= :to')
            ->andWhere('t.lockedAt IS NOT NULL OR t.status = :submitted')
            ->setParameter('owner', $owner)
            ->setParameter('from', Period::date($fromDate), Types::DATE_IMMUTABLE)
            ->setParameter('to', Period::date($toDate), Types::DATE_IMMUTABLE)
            ->setParameter('submitted', TimesheetStatus::Submitted->value)
            ->orderBy('t.periodStart', SortDirection::Ascending->value);
        if ($scopeKey !== null) {
            $query->andWhere('t.scopeKey = :scope')->setParameter('scope', $scopeKey);
        }

        /** @var list<Timesheet> $result */
        $result = ($lock instanceof LockMode ? $query->getQuery()->setLockMode($lock) : $query->getQuery())->getResult();

        return $result;
    }

    /**
     * Timesheets of the owner in a scope overlapping the dates, oldest first.
     */
    public function findByOwner(string $ownerId, string $scopeKey, string $fromDate, string $toDate): array
    {
        /** @var list<Timesheet> $result */
        $result = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(Timesheet::class, 't')
            ->where('IDENTITY(t.user) = :owner')
            ->andWhere('t.scopeKey = :scope')
            ->andWhere('t.periodEnd >= :from')
            ->andWhere('t.periodStart <= :to')
            ->setParameter('owner', $ownerId)
            ->setParameter('scope', $scopeKey)
            ->setParameter('from', Period::date($fromDate), Types::DATE_IMMUTABLE)
            ->setParameter('to', Period::date($toDate), Types::DATE_IMMUTABLE)
            ->orderBy('t.periodStart', SortDirection::Ascending->value)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Approval queue of the owners in the scope.
     */
    public function findQueue(string $scopeKey, array $ownerIds, ?TimesheetStatus $status, int $limit): array
    {
        if ($ownerIds === []) {
            return [];
        }
        $query = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(Timesheet::class, 't')
            ->where('t.scopeKey = :scope')
            ->andWhere('IDENTITY(t.user) IN (:owners)')
            ->setParameter('scope', $scopeKey)
            ->setParameter('owners', $ownerIds)
            ->orderBy('t.periodStart', SortDirection::Ascending->value)
            ->addOrderBy('t.id', SortDirection::Ascending->value)
            ->setMaxResults(max(1, $limit));
        if ($status instanceof TimesheetStatus) {
            $query->andWhere('t.status = :status')->setParameter('status', $status->value);
        }

        /** @var list<Timesheet> $result */
        $result = $query->getQuery()->getResult();

        return $result;
    }

    /**
     * The latest locked timesheet of the owner and scope ending before the date.
     */
    public function findLatestLockedBefore(string $ownerId, string $scopeKey, string $beforeDate): ?Timesheet
    {
        /** @var Timesheet|null $timesheet */
        $timesheet = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(Timesheet::class, 't')
            ->where('IDENTITY(t.user) = :owner')
            ->andWhere('t.scopeKey = :scope')
            ->andWhere('t.lockedAt IS NOT NULL')
            ->andWhere('t.periodEnd < :before')
            ->setParameter('owner', $ownerId)
            ->setParameter('scope', $scopeKey)
            ->setParameter('before', Period::date($beforeDate), Types::DATE_IMMUTABLE)
            ->orderBy('t.periodEnd', SortDirection::Descending->value)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $timesheet;
    }

    /**
     * Retention purge (since the GB fixes): first, per ledger chain, the longest **prefix** (chain order) of lines
     * effective before the date; the first line kept is marked `purged_before` so `verify` knows the chain restarts there
     * (a line in the middle of a chain is never deleted, so the chain stays verifiable). Then up to `$batchSize`
     * timesheets ended before the date with their audit rows, except those a kept ledger line still points to (they go
     * with that line: the timesheet id is part of the line's hash).
     */
    public function purgeBefore(string $beforeDate, int $batchSize = 500): array
    {
        $before = Period::date($beforeDate);

        // @igor-ignore - the closure runs once inside this call (transaction body), it is not stored.
        return $this->entityManager->wrapInTransaction(function () use ($before, $batchSize): array {
            $ledger = $this->purgeLedgerPrefixes($before);

            /** @var list<array{id: string}> $rows */
            $rows = $this->entityManager->createQueryBuilder()
                ->select('t.id AS id')
                ->from(Timesheet::class, 't')
                ->where('t.periodEnd < :before')
                ->andWhere('NOT EXISTS (SELECT k.id FROM ' . BalanceLedgerLine::class . ' k WHERE k.timesheet = t)')
                ->setParameter('before', $before, Types::DATE_IMMUTABLE)
                ->orderBy('t.id', SortDirection::Ascending->value)
                ->setMaxResults(max(1, $batchSize))
                ->getQuery()
                ->getArrayResult();
            $ids    = array_map(static fn (array $row): string => $row['id'], $rows);
            $events = 0;
            if ($ids !== []) {
                $events = (int) $this->entityManager->createQueryBuilder()
                    ->delete(TimesheetEvent::class, 'e')
                    ->where('IDENTITY(e.timesheet) IN (:ids)')
                    ->setParameter('ids', $ids)
                    ->getQuery()
                    ->execute();
                $this->entityManager->createQueryBuilder()
                    ->delete(Timesheet::class, 't')
                    ->where('t.id IN (:ids)')
                    ->setParameter('ids', $ids)
                    ->getQuery()
                    ->execute();
            }
            $this->entityManager->clear();

            return ['timesheets' => count($ids), 'events' => $events, 'ledger' => $ledger];
        });
    }

    /**
     * Deletes, per chain with lines effective before the date, the longest chain-order prefix of such lines and marks
     * the first line kept; returns the lines deleted. Chain order follows the links ({@see self::chainOrder()}).
     */
    private function purgeLedgerPrefixes(DateTimeImmutable $before): int
    {
        /** @var list<array{ownerRef: string, scopeKey: string}> $chains */
        $chains = $this->entityManager->createQueryBuilder()
            ->select('l.ownerRef AS ownerRef', 'l.scopeKey AS scopeKey')
            ->distinct()
            ->from(BalanceLedgerLine::class, 'l')
            ->where('l.effectiveDate < :before')
            ->setParameter('before', $before, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getArrayResult();
        $deleted = 0;
        foreach ($chains as $chain) {
            /** @var list<array{id: string, effectiveDate: DateTimeImmutable, hash: string, prevHash: ?string, purgedBefore: ?DateTimeImmutable}> $lines */
            $lines = $this->entityManager->createQueryBuilder()
                ->select('l.id AS id', 'l.effectiveDate AS effectiveDate', 'l.hash AS hash', 'l.prevHash AS prevHash', 'l.purgedBefore AS purgedBefore')
                ->from(BalanceLedgerLine::class, 'l')
                ->where('l.ownerRef = :owner')
                ->andWhere('l.scopeKey = :scope')
                ->setParameter('owner', $chain['ownerRef'])
                ->setParameter('scope', $chain['scopeKey'])
                ->orderBy('l.createdAt', SortDirection::Ascending->value)
                ->addOrderBy('l.id', SortDirection::Ascending->value)
                ->getQuery()
                ->getArrayResult();
            $prefix = [];
            $keep   = null;
            foreach ($this->chainOrder($lines) as $line) {
                if ($line['effectiveDate']->format('Y-m-d') >= $before->format('Y-m-d')) {
                    $keep = $line['id'];
                    break;
                }
                $prefix[] = $line['id'];
            }
            if ($prefix === []) {
                continue;
            }
            foreach (array_chunk($prefix, self::IDS_PER_QUERY) as $chunk) {
                $deleted += (int) $this->entityManager->createQueryBuilder()
                    ->delete(BalanceLedgerLine::class, 'l')
                    ->where('l.id IN (:ids)')
                    ->setParameter('ids', $chunk)
                    ->getQuery()
                    ->execute();
            }
            if ($keep !== null) {
                $this->entityManager->createQueryBuilder()
                    ->update(BalanceLedgerLine::class, 'l')
                    ->set('l.purgedBefore', ':before')
                    ->where('l.id = :id')
                    ->setParameter('before', $before, Types::DATE_IMMUTABLE)
                    ->setParameter('id', $keep)
                    ->getQuery()
                    ->execute();
            }
        }

        return $deleted;
    }

    /**
     * Since 1.17.1 (audit HT-L1): a chain's lines in chain order, not in sort order. From each start (the first line, or the
     * line marked `purged_before`), in sort order, the links are followed to the end; lines that no start reaches (a line
     * deleted by hand before them) follow in sort order, as before. A line written on a clock behind sorts before the line
     * it follows, so a prefix taken in sort order could delete it and keep its predecessor, and `verify` would then report
     * the next line as not linked.
     *
     * @template T of array{id: string, hash: string, prevHash: ?string, purgedBefore: ?DateTimeImmutable}
     *
     * @param list<T> $lines in sort order (`created_at`, `id`)
     *
     * @return list<T>
     */
    private function chainOrder(array $lines): array
    {
        $followers = [];
        foreach ($lines as $index => $line) {
            $followers[$line['prevHash'] ?? ''][] = $index;
        }
        $ordered = [];
        $placed  = [];
        foreach ($lines as $index => $line) {
            if ($line['purgedBefore'] === null && ($line['prevHash'] ?? BalanceLedgerLine::FIRST_LINE_PREV_HASH) !== BalanceLedgerLine::FIRST_LINE_PREV_HASH) {
                continue;
            }
            $at = $index;
            while ($at !== null && !isset($placed[$at])) {
                $placed[$at] = true;
                $ordered[]   = $lines[$at];
                $next        = $followers[$lines[$at]['hash']] ?? [];
                $at          = count($next) === 1 ? $next[0] : null;
            }
        }
        foreach ($lines as $index => $line) {
            if (!isset($placed[$index])) {
                $ordered[] = $line;
            }
        }

        return $ordered;
    }

    /**
     * Ids of the timesheets to verify, keyset by id.
     */
    public function timesheetIds(?string $scopeKey, ?string $ownerRef, string $afterId, int $limit): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('t.id AS id')
            ->from(Timesheet::class, 't')
            ->where('t.id > :after')
            ->setParameter('after', $afterId)
            ->orderBy('t.id', SortDirection::Ascending->value)
            ->setMaxResults(max(1, $limit));
        if ($scopeKey !== null) {
            $query->andWhere('t.scopeKey = :scope')->setParameter('scope', $scopeKey);
        }
        if ($ownerRef !== null) {
            $query->andWhere('t.ownerRef = :owner')->setParameter('owner', $ownerRef);
        }

        /** @var list<array{id: string}> $rows */
        $rows = $query->getQuery()->getArrayResult();

        return array_column($rows, 'id');
    }

    /**
     * Ledger chains to verify.
     */
    public function ledgerChains(?string $scopeKey, ?string $ownerRef): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('l.ownerRef AS ownerRef', 'l.scopeKey AS scopeKey')
            ->distinct()
            ->from(BalanceLedgerLine::class, 'l')
            ->orderBy('l.ownerRef', SortDirection::Ascending->value)
            ->addOrderBy('l.scopeKey', SortDirection::Ascending->value);
        if ($scopeKey !== null) {
            $query->andWhere('l.scopeKey = :scope')->setParameter('scope', $scopeKey);
        }
        if ($ownerRef !== null) {
            $query->andWhere('l.ownerRef = :owner')->setParameter('owner', $ownerRef);
        }

        /** @var list<array{ownerRef: string, scopeKey: string}> $rows */
        $rows = $query->getQuery()->getArrayResult();

        return $rows;
    }
}
