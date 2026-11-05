<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Timesheet;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.13 (host, `timesheets.approver_provider`): who approves whose timesheets, **per tenant scope** (Beacon:
 * `working_time.manage_team` in the organization, fallback `time_track.timesheet.approve`). The bundle never lets
 * anybody approve their own timesheet, whatever this provider says. Must be scope-safe and side-effect free.
 */
interface TimesheetApproverProviderInterface
{
    /**
     * Whether `$approver` may approve, reject and reopen the timesheets of `$ownerId` in the scope.
     */
    public function canApprove(UserInterface $approver, string $ownerId, string $scopeKey): bool;

    /**
     * Ids of the users whose timesheets `$approver` reviews in the scope (the approval queue).
     *
     * @return list<string>
     */
    public function reviewableUserIds(UserInterface $approver, string $scopeKey): array;

    /**
     * Ids of the approvers of `$ownerId` in the scope (host notifications).
     *
     * @return list<string>
     */
    public function approverIdsFor(string $ownerId, string $scopeKey): array;
}
