<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port;

use Nowo\TimeTrackBundle\Timesheet\TimesheetApproverPolicyInterface;
use Nowo\TimeTrackBundle\Timesheet\TimesheetApproverProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Asks a host {@see TimesheetApproverPolicyInterface} (1.23.0) where the bundle asks the deprecated
 * {@see TimesheetApproverProviderInterface}. Exceptions propagate. Built by {@see Ports}; stateless.
 *
 * @internal
 */
final readonly class TimesheetApproverPolicyGate implements TimesheetApproverProviderInterface
{
    /**
     * Wraps the host policy.
     */
    public function __construct(private TimesheetApproverPolicyInterface $policy)
    {
    }

    /**
     * The policy's answer for the approver's reference.
     */
    public function canApprove(UserInterface $approver, string $ownerId, string $scopeKey): bool
    {
        return $this->policy->mayApprove(TimeTrackUserRefs::fromUser($approver), $ownerId, $scopeKey);
    }

    /**
     * The policy's reviewable ids for the approver's reference.
     */
    public function reviewableUserIds(UserInterface $approver, string $scopeKey): array
    {
        return $this->policy->reviewableUserIdsFor(TimeTrackUserRefs::fromUser($approver), $scopeKey);
    }

    /**
     * The policy's approver ids.
     */
    public function approverIdsFor(string $ownerId, string $scopeKey): array
    {
        return $this->policy->approverIdsFor($ownerId, $scopeKey);
    }
}
