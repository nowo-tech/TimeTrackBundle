<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Integration;

/**
 * Since 1.21 (Beacon D-20261111-12): the `billable` value of a new live entry stored without an explicit one (a timer
 * stop or switch, an idle reassign, `POST entries` without `billable`, an accepted calendar suggestion). Imported entries
 * keep the value of their source and never ask.
 *
 * Scalars only (REQ-ARCH-001 rule 3). `$scopeKey` is the entry's tenant scope (`''` when it has none, e.g. timesheets
 * off without a scope provider); `$taskRef` is the task id. Return null to keep the behaviour before 1.21 (the flag stays
 * unset, `billable: null`). Called once per stored entry, inside the write transaction: keep it fast and side-effect free.
 * Alias this interface to your own service; the default ({@see NullTimeEntryBillableDefaultProvider}) answers null.
 */
interface TimeEntryBillableDefaultProviderInterface
{
    /**
     * Default `billable` of a new entry in the scope and on the task (null = leave it unset).
     */
    public function defaultFor(string $scopeKey, ?string $taskRef): ?bool;
}
