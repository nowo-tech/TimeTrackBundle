<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port\Internal;

use Nowo\TimeTrackBundle\Timesheet\TimesheetApproverPolicyInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.13 (host, `timesheets.approver_provider`): who approves whose timesheets, **per tenant scope** (Beacon:
 * `working_time.manage_team` in the organization, fallback `time_track.timesheet.approve`). The bundle never lets
 * anybody approve their own timesheet, whatever this provider says. Must be scope-safe and side-effect free.
 *
 * @internal since 2.0 (REQ-ARCH-001 rule 3): the shape the bundle asks this port through; the 1.x public interface
 * `Nowo\TimeTrackBundle\Timesheet\TimesheetApproverProviderInterface` (deprecated since 1.23.0) is removed in 2.0. Hosts implement {@see TimesheetApproverPolicyInterface} (a gate adapts it).
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
