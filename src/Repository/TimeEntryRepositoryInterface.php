<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\TimeEntry;

interface TimeEntryRepositoryInterface
{
    public function save(TimeEntry $entry): void;

    /**
     * @return list<TimeEntry>
     */
    public function findByUserAndPeriod(string $userId, DateTimeImmutable $from, DateTimeImmutable $to): array;
}
