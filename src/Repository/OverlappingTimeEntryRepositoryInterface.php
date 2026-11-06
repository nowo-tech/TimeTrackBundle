<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\TimeEntry;

/**
 * Since 1.14: optional store capability — the live entries (never imported) of a user that overlap a range. Stores
 * without it are read from two days before the range ({@see TimeEntryRepositoryInterface::findByUserAndPeriod()}).
 */
interface OverlappingTimeEntryRepositoryInterface
{
    /**
     * Live entries of the user with `started_at < $to` and `ended_at > $from`.
     *
     * @return list<TimeEntry>
     */
    public function findLiveOverlapping(string $userId, DateTimeImmutable $from, DateTimeImmutable $to): array;
}
