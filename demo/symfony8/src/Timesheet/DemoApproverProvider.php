<?php

declare(strict_types=1);

namespace App\Timesheet;

use Nowo\TimeTrackBundle\Timesheet\TimesheetApproverProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Demo approver provider (TimeTrack 1.13 timesheets): the demo has one signed-in user and nobody approves their own
 * timesheet, so nobody approves here; a real host answers from its permission model (per tenant scope).
 */
final class DemoApproverProvider implements TimesheetApproverProviderInterface
{
    public function canApprove(UserInterface $approver, string $ownerId, string $scopeKey): bool
    {
        return false;
    }

    public function reviewableUserIds(UserInterface $approver, string $scopeKey): array
    {
        return [];
    }

    public function approverIdsFor(string $ownerId, string $scopeKey): array
    {
        return [];
    }
}
