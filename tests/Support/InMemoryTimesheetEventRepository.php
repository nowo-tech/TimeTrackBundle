<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use Nowo\TimeTrackBundle\Entity\TimesheetEvent;
use Nowo\TimeTrackBundle\Repository\TimesheetEventRepositoryInterface;

use function array_filter;
use function array_values;
use function end;
use function usort;

/**
 * In-memory audit trail of the unit tests.
 */
final class InMemoryTimesheetEventRepository implements TimesheetEventRepositoryInterface
{
    /** @var list<TimesheetEvent> */
    public array $events = [];

    public function append(TimesheetEvent $event): void
    {
        $this->events[] = $event;
    }

    public function findByTimesheet(string $timesheetId): array
    {
        $rows = array_values(array_filter($this->events, static fn (TimesheetEvent $e): bool => $e->getTimesheet()->getId() === $timesheetId));
        usort($rows, static fn (TimesheetEvent $a, TimesheetEvent $b): int => $a->getSeq() <=> $b->getSeq());

        return $rows;
    }

    public function findLast(string $timesheetId): ?TimesheetEvent
    {
        $rows = $this->findByTimesheet($timesheetId);

        return $rows === [] ? null : end($rows);
    }
}
