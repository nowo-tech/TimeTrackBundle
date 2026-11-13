<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use Doctrine\Common\Collections\Order as SortDirection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TimeTrackBundle\Doctrine\RecoveringFlusher;
use Nowo\TimeTrackBundle\Entity\BalanceLedgerLine;
use Nowo\TimeTrackBundle\Timesheet\Period;
use Nowo\TimeTrackBundle\Timesheet\ScopeKeys;
use Nowo\TimeTrackBundle\UserRef\ExactUserIds;

/**
 * Since 1.13: Doctrine ORM balance ledger (append and read only). Chain order is creation order, then id (UUID v7).
 */
final readonly class DoctrineOrmBalanceLedgerRepository implements BalanceLedgerRepositoryInterface
{
    /**
     * Receives the entity manager.
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $managerRegistry = null,
    ) {
    }

    /**
     * Persists a new line.
     */
    public function append(BalanceLedgerLine $line): void
    {
        $this->entityManager->persist($line);
    }

    /**
     * Flushes appended lines.
     */
    public function flush(): void
    {
        RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
    }

    /**
     * The line with this id.
     */
    public function find(string $id): ?BalanceLedgerLine
    {
        return $this->entityManager->find(BalanceLedgerLine::class, $id);
    }

    /**
     * The head of an owner and scope chain: since 1.16.2 (audit HQ-L3) the line no other line follows, because a sort alone can
     * pick an older line (two lines in one millisecond, or a clock behind), which then refused every later line for good. The
     * unique `(owner_ref, scope_key, prev_hash)` key serves the subquery; `scope_key` is never NULL. The sort is only a tie-break,
     * for a chain that forked at its start before 1.16.2.
     *
     * Since 1.24.1 (REQ-SEC-006): every head the query returned is re-checked byte for byte (scope key always, owner
     * reference with the id mapping) before the first one is taken, so a look-alike chain's head never stands for this
     * chain's (no `LIMIT` before the check).
     */
    public function findLast(string $ownerRef, string $scopeKey): ?BalanceLedgerLine
    {
        /** @var list<BalanceLedgerLine> $heads */
        $heads = $this->chain($ownerRef, $scopeKey)
            ->andWhere('NOT EXISTS (SELECT 1 FROM ' . BalanceLedgerLine::class . ' n WHERE n.ownerRef = l.ownerRef AND n.scopeKey = l.scopeKey AND n.prevHash = l.hash)')
            ->orderBy('l.createdAt', SortDirection::Descending->value)
            ->addOrderBy('l.id', SortDirection::Descending->value)
            ->getQuery()
            ->getResult();

        // Since 1.23.1 (id user mapping, audit OA-R1): the owner reference must match byte for byte.
        return ExactUserIds::keep($this->entityManager, BalanceLedgerLine::class, $this->inScope($heads, $scopeKey), [$ownerRef], $this->ownerRef(...))[0] ?? null;
    }

    /**
     * Lines of an owner and scope in the date range, chain order.
     */
    public function findByOwner(string $ownerRef, string $scopeKey, ?string $fromDate = null, ?string $toDate = null): array
    {
        $query = $this->chain($ownerRef, $scopeKey)
            ->orderBy('l.createdAt', SortDirection::Ascending->value)
            ->addOrderBy('l.id', SortDirection::Ascending->value);
        if ($fromDate !== null) {
            // @igor-ignore - query builder created for this call, not a shared service.
            $query->andWhere('l.effectiveDate >= :from')->setParameter('from', Period::date($fromDate), Types::DATE_IMMUTABLE);
        }
        if ($toDate !== null) {
            // @igor-ignore - query builder created for this call, not a shared service.
            $query->andWhere('l.effectiveDate <= :to')->setParameter('to', Period::date($toDate), Types::DATE_IMMUTABLE);
        }

        /** @var list<BalanceLedgerLine> $result */
        $result = $query->getQuery()->getResult();

        // Since 1.23.1 (id user mapping, audit OA-R1): the owner reference must match byte for byte; since 1.24.1
        // (REQ-SEC-006) the scope key too.
        return ExactUserIds::keep($this->entityManager, BalanceLedgerLine::class, $this->inScope($result, $scopeKey), [$ownerRef], $this->ownerRef(...));
    }

    /**
     * Since 1.24.1 (REQ-SEC-006): the lines whose scope key is the key byte for byte.
     *
     * @param list<BalanceLedgerLine> $lines
     *
     * @return list<BalanceLedgerLine>
     */
    private function inScope(array $lines, string $scopeKey): array
    {
        return ScopeKeys::keep($lines, $scopeKey, static fn (BalanceLedgerLine $line): string => $line->getScopeKey());
    }

    /**
     * The owner reference of a line (the re-checks of the id user mapping).
     */
    private function ownerRef(BalanceLedgerLine $line): string
    {
        return $line->getOwnerRef();
    }

    /**
     * The reversal of a line.
     */
    public function findReversalOf(string $lineId): ?BalanceLedgerLine
    {
        return $this->entityManager->getRepository(BalanceLedgerLine::class)->findOneBy(['reversesId' => $lineId]);
    }

    /**
     * Lines linked to a timesheet, chain order.
     */
    public function findByTimesheet(string $timesheetId): array
    {
        /** @var list<BalanceLedgerLine> $result */
        $result = $this->entityManager->createQueryBuilder()
            ->select('l')
            ->from(BalanceLedgerLine::class, 'l')
            ->where('IDENTITY(l.timesheet) = :id')
            ->setParameter('id', $timesheetId)
            ->orderBy('l.createdAt', SortDirection::Ascending->value)
            ->addOrderBy('l.id', SortDirection::Ascending->value)
            ->getQuery()
            ->getResult();

        return $result;
    }

    private function chain(string $ownerRef, string $scopeKey): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('l')
            ->from(BalanceLedgerLine::class, 'l')
            ->where('l.ownerRef = :owner')
            ->andWhere('l.scopeKey = :scope')
            ->setParameter('owner', $ownerRef)
            ->setParameter('scope', $scopeKey);
    }
}
