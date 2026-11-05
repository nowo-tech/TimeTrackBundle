<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Enum\TimesheetPeriodKind;
use Nowo\TimeTrackBundle\Enum\TimesheetStatus;
use Nowo\TimeTrackBundle\Repository\TimesheetRepositoryInterface;
use Throwable;

use function array_filter;
use function array_slice;
use function array_values;
use function in_array;
use function usort;

/**
 * In-memory timesheet store of the unit tests (owner matched by `owner_ref`).
 */
final class InMemoryTimesheetRepository implements TimesheetRepositoryInterface
{
    /** @var array<string, Timesheet> */
    public array $timesheets = [];

    public int $saves = 0;

    public int $transactions = 0;

    /** Thrown by the next save (race simulations). */
    public ?Throwable $failSave = null;

    public function save(Timesheet $timesheet): void
    {
        if ($this->failSave instanceof Throwable) {
            throw $this->failSave;
        }
        $this->timesheets[$timesheet->getId()] = $timesheet;
        ++$this->saves;
    }

    public function transactional(callable $operation): mixed
    {
        ++$this->transactions;

        return $operation();
    }

    public function find(string $id): ?Timesheet
    {
        return $this->timesheets[$id] ?? null;
    }

    public function findForPeriod(string $ownerId, string $scopeKey, TimesheetPeriodKind $kind, string $periodStart): ?Timesheet
    {
        foreach ($this->timesheets as $timesheet) {
            if ($timesheet->getOwnerRef() === $ownerId && $timesheet->getScopeKey() === $scopeKey && $timesheet->getPeriodKind() === $kind && $timesheet->getPeriodStart() === $periodStart) {
                return $timesheet;
            }
        }

        return null;
    }

    public function findGuarding(string $ownerId, ?string $scopeKey, string $fromDate, string $toDate): array
    {
        return $this->sorted(array_filter(
            $this->timesheets,
            static fn (Timesheet $t): bool => $t->getOwnerRef() === $ownerId
                && ($scopeKey === null || $t->getScopeKey() === $scopeKey)
                && $t->getPeriodEnd() >= $fromDate && $t->getPeriodStart() <= $toDate
                && ($t->isLocked() || $t->getStatus() === TimesheetStatus::Submitted),
        ));
    }

    public function findByOwner(string $ownerId, string $scopeKey, string $fromDate, string $toDate): array
    {
        return $this->sorted(array_filter(
            $this->timesheets,
            static fn (Timesheet $t): bool => $t->getOwnerRef() === $ownerId && $t->getScopeKey() === $scopeKey
                && $t->getPeriodEnd() >= $fromDate && $t->getPeriodStart() <= $toDate,
        ));
    }

    public function findQueue(string $scopeKey, array $ownerIds, ?TimesheetStatus $status, int $limit): array
    {
        return array_slice($this->sorted(array_filter(
            $this->timesheets,
            static fn (Timesheet $t): bool => $t->getScopeKey() === $scopeKey && in_array($t->getOwnerRef(), $ownerIds, true)
                && (!$status instanceof TimesheetStatus || $t->getStatus() === $status),
        )), 0, $limit);
    }

    public function findLatestLockedBefore(string $ownerId, string $scopeKey, string $beforeDate): ?Timesheet
    {
        $found = null;
        foreach ($this->timesheets as $t) {
            if ($t->getOwnerRef() === $ownerId && $t->getScopeKey() === $scopeKey && $t->isLocked() && $t->getPeriodEnd() < $beforeDate
                && (!$found instanceof Timesheet || $t->getPeriodEnd() > $found->getPeriodEnd())) {
                $found = $t;
            }
        }

        return $found;
    }

    /**
     * @param array<string, Timesheet> $timesheets
     *
     * @return list<Timesheet>
     */
    private function sorted(array $timesheets): array
    {
        $list = array_values($timesheets);
        usort($list, static fn (Timesheet $a, Timesheet $b): int => $a->getPeriodStart() <=> $b->getPeriodStart());

        return $list;
    }
}
