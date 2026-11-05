<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use Nowo\TimeTrackBundle\Entity\BalanceLedgerLine;

/**
 * Since 1.13: the balance ledger. Append and read only (lines are never edited; a correction is a reversal).
 */
interface BalanceLedgerRepositoryInterface
{
    /**
     * Persists a new line (flushed by the surrounding transaction, or by {@see self::flush()}).
     */
    public function append(BalanceLedgerLine $line): void;

    /**
     * Flushes appended lines outside a transaction.
     */
    public function flush(): void;

    /**
     * The line with this id.
     */
    public function find(string $id): ?BalanceLedgerLine;

    /**
     * The last line of an owner and scope (head of its chain).
     */
    public function findLast(string $ownerRef, string $scopeKey): ?BalanceLedgerLine;

    /**
     * Lines of an owner and scope with an effective date in `[$fromDate, $toDate]` (null = open bound), in chain order.
     *
     * @return list<BalanceLedgerLine>
     */
    public function findByOwner(string $ownerRef, string $scopeKey, ?string $fromDate = null, ?string $toDate = null): array;

    /**
     * The reversal of a line, if any.
     */
    public function findReversalOf(string $lineId): ?BalanceLedgerLine;

    /**
     * Lines linked to a timesheet (`overtime_ratified` and their reversals), in chain order.
     *
     * @return list<BalanceLedgerLine>
     */
    public function findByTimesheet(string $timesheetId): array;
}
