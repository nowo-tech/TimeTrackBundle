<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Enum\TimesheetPeriodKind;
use Nowo\TimeTrackBundle\Enum\TimesheetStatus;

/**
 * Since 1.13: timesheet store. Owners are matched by their user id (the `user_id` column, the same value as
 * `owner_ref` while the account exists); local dates are `Y-m-d` strings.
 */
interface TimesheetRepositoryInterface
{
    /**
     * Persists and flushes a timesheet (an optimistic lock conflict surfaces as a Doctrine exception).
     */
    public function save(Timesheet $timesheet): void;

    /**
     * Runs `$operation` in one database transaction and flushes at its end; the result of `$operation` is returned.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function transactional(callable $operation): mixed;

    /**
     * The timesheet with this id.
     */
    public function find(string $id): ?Timesheet;

    /**
     * The timesheet of an owner, scope and period (period kind + first local date).
     */
    public function findForPeriod(string $ownerId, string $scopeKey, TimesheetPeriodKind $kind, string $periodStart): ?Timesheet;

    /**
     * Timesheets of the owner that are locked or submitted and overlap the local dates `[$fromDate, $toDate]`, in one
     * scope or (null) in every scope: what the period lock guard checks.
     *
     * @return list<Timesheet>
     */
    public function findGuarding(string $ownerId, ?string $scopeKey, string $fromDate, string $toDate): array;

    /**
     * Timesheets of the owner in a scope overlapping `[$fromDate, $toDate]`, oldest first.
     *
     * @return list<Timesheet>
     */
    public function findByOwner(string $ownerId, string $scopeKey, string $fromDate, string $toDate): array;

    /**
     * Approval queue: timesheets of these owners in the scope (optionally of one status), oldest period first.
     *
     * @param list<string> $ownerIds
     *
     * @return list<Timesheet>
     */
    public function findQueue(string $scopeKey, array $ownerIds, ?TimesheetStatus $status, int $limit): array;

    /**
     * The latest locked timesheet of the owner and scope that ends before `$beforeDate` (closed-period balance cache).
     */
    public function findLatestLockedBefore(string $ownerId, string $scopeKey, string $beforeDate): ?Timesheet;
}
