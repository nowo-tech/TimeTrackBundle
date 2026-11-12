<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Timesheet;

use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/**
 * Since 1.23.0: framework-free twin of {@see TimesheetApproverProviderInterface} (REQ-ARCH-001 rule 3), same
 * `timesheets.approver_provider` option. Who approves whose timesheets, per tenant scope. The bundle never lets anybody
 * approve their own timesheet, whatever this policy says. Must be scope-safe and side-effect free.
 */
interface TimesheetApproverPolicyInterface
{
    /**
     * Whether `$approver` may approve, reject and reopen the timesheets of `$ownerId` in the scope.
     */
    public function mayApprove(TimeTrackUserRef $approver, string $ownerId, string $scopeKey): bool;

    /**
     * Ids of the users whose timesheets `$approver` reviews in the scope (the approval queue).
     *
     * @return list<string>
     */
    public function reviewableUserIdsFor(TimeTrackUserRef $approver, string $scopeKey): array;

    /**
     * Ids of the approvers of `$ownerId` in the scope (host notifications).
     *
     * @return list<string>
     */
    public function approverIdsFor(string $ownerId, string $scopeKey): array;
}
