<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

/**
 * Since 1.13: the only delete path of timesheets, their audit rows and ledger lines: the retention purge
 * (`nowo:time-track:timesheets:purge`), and the chain listing of the `verify` command.
 */
interface TimesheetRetentionRepositoryInterface
{
    /**
     * Deletes up to `$batchSize` timesheets whose period ended before `$beforeDate` (with their audit rows) and the
     * ledger lines with an effective date before it; returns `{timesheets, events, ledger}` counts.
     *
     * @return array{timesheets: int, events: int, ledger: int}
     */
    public function purgeBefore(string $beforeDate, int $batchSize = 500): array;

    /**
     * Ids of the timesheets to verify (optionally of one scope and one owner), keyset by id.
     *
     * @return list<string>
     */
    public function timesheetIds(?string $scopeKey, ?string $ownerRef, string $afterId, int $limit): array;

    /**
     * `(owner_ref, scope_key)` pairs of the ledger to verify (optionally filtered).
     *
     * @return list<array{ownerRef: string, scopeKey: string}>
     */
    public function ledgerChains(?string $scopeKey, ?string $ownerRef): array;
}
