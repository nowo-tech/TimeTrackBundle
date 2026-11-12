<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port;

use Nowo\TimeTrackBundle\Dto\TimeEntryView;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScopePolicyInterface;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScopeProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_map;

/**
 * Asks a host {@see TimesheetScopePolicyInterface} (1.23.0) where the bundle asks the deprecated
 * {@see TimesheetScopeProviderInterface}: users become references, entries {@see TimeEntryView} snapshots. Exceptions
 * propagate. Built by {@see Ports}; stateless.
 *
 * @internal
 */
final readonly class TimesheetScopePolicyGate implements TimesheetScopeProviderInterface
{
    /**
     * Wraps the host policy.
     */
    public function __construct(private TimesheetScopePolicyInterface $policy)
    {
    }

    /**
     * The policy's scopes for the user's reference.
     */
    public function scopesFor(UserInterface $user): array
    {
        return $this->policy->scopesOf(TimeTrackUserRefs::fromUser($user));
    }

    /**
     * The policy's scope for the user's reference and the task.
     */
    public function scopeKeyForTask(UserInterface $user, string $taskId, ?string $boardId): ?string
    {
        return $this->policy->scopeKeyForUserTask(TimeTrackUserRefs::fromUser($user), $taskId, $boardId);
    }

    /**
     * The policy's scopes for the snapshots of the entries.
     */
    public function scopeKeysForEntries(array $entries): array
    {
        return $this->policy->scopeKeysForEntryViews(array_map(TimeEntryView::fromEntry(...), $entries));
    }
}
