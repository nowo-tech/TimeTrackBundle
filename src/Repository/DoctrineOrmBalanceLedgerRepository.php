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
     * The head of an owner and scope chain.
     */
    public function findLast(string $ownerRef, string $scopeKey): ?BalanceLedgerLine
    {
        /** @var BalanceLedgerLine|null $line */
        $line = $this->chain($ownerRef, $scopeKey)
            ->orderBy('l.createdAt', SortDirection::Descending->value)
            ->addOrderBy('l.id', SortDirection::Descending->value)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $line;
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

        return $result;
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
