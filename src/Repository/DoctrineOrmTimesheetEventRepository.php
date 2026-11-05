<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use Doctrine\Common\Collections\Order as SortDirection;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TimeTrackBundle\Entity\TimesheetEvent;

/**
 * Since 1.13: Doctrine ORM audit trail of the timesheets (append and read only).
 */
final readonly class DoctrineOrmTimesheetEventRepository implements TimesheetEventRepositoryInterface
{
    /**
     * Receives the entity manager.
     */
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * Persists a new row (flushed by the surrounding transaction).
     */
    public function append(TimesheetEvent $event): void
    {
        $this->entityManager->persist($event);
    }

    /**
     * Rows of a timesheet in chain order.
     */
    public function findByTimesheet(string $timesheetId): array
    {
        /** @var list<TimesheetEvent> $result */
        $result = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(TimesheetEvent::class, 'e')
            ->where('IDENTITY(e.timesheet) = :id')
            ->setParameter('id', $timesheetId)
            ->orderBy('e.seq', SortDirection::Ascending->value)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * The last row of a timesheet.
     */
    public function findLast(string $timesheetId): ?TimesheetEvent
    {
        /** @var TimesheetEvent|null $event */
        $event = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(TimesheetEvent::class, 'e')
            ->where('IDENTITY(e.timesheet) = :id')
            ->setParameter('id', $timesheetId)
            ->orderBy('e.seq', SortDirection::Descending->value)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $event;
    }
}
