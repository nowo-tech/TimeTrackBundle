<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\TimeEntry;

/**
 * Since 1.40.1 (audit ZAN-1): optional store capability — {@see OverlappingTimeEntryRepositoryInterface::findLiveOverlapping()}
 * read under a shared lock inside the caller's transaction, so a REPEATABLE READ transaction the host opened earlier
 * (MySQL, MariaDB) sees the rows committed since its snapshot instead of hiding them. The Doctrine store implements it.
 */
interface LockingOverlappingTimeEntryRepositoryInterface extends OverlappingTimeEntryRepositoryInterface
{
    /**
     * Live entries of the user with `started_at < $to` and `ended_at > $from`, under a shared lock (a plain read outside a
     * transaction).
     *
     * @return list<TimeEntry>
     */
    public function findLiveOverlappingLocked(string $userId, DateTimeImmutable $from, DateTimeImmutable $to): array;
}
