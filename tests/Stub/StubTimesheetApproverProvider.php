<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Stub;

use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\Timesheet\TimesheetApproverProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_keys;
use function in_array;

/**
 * Test approver provider: `$approvers[scopeKey][approverId] = list of owner ids`.
 */
final class StubTimesheetApproverProvider implements TimesheetApproverProviderInterface
{
    /**
     * @param array<string, array<int|string, list<string>>> $approvers
     */
    public function __construct(public array $approvers = [])
    {
    }

    public function canApprove(UserInterface $approver, string $ownerId, string $scopeKey): bool
    {
        return in_array($ownerId, $this->approvers[$scopeKey][UserIdResolver::getId($approver)] ?? [], true);
    }

    public function reviewableUserIds(UserInterface $approver, string $scopeKey): array
    {
        return $this->approvers[$scopeKey][UserIdResolver::getId($approver)] ?? [];
    }

    public function approverIdsFor(string $ownerId, string $scopeKey): array
    {
        $ids = [];
        foreach ($this->approvers[$scopeKey] ?? [] as $approverId => $owners) {
            if (in_array($ownerId, $owners, true)) {
                $ids[] = (string) $approverId;
            }
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    public function scopes(): array
    {
        return array_keys($this->approvers);
    }
}
