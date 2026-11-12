<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Timesheet;

use Nowo\TimeTrackBundle\Dto\TimeEntryView;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/**
 * Since 1.23.0: framework-free twin of {@see TimesheetScopeProviderInterface} (REQ-ARCH-001 rules 1 and 3), same
 * `timesheets.scope_provider` option: the user is a {@see TimeTrackUserRef}, the entries are {@see TimeEntryView}
 * snapshots instead of the Doctrine entity.
 */
interface TimesheetScopePolicyInterface
{
    /**
     * Scopes of the user, the current one first (the client API's default scope).
     *
     * @return list<TimesheetScope>
     */
    public function scopesOf(TimeTrackUserRef $user): array;

    /**
     * Scope of a task the user tracks (null = unknown: the entry belongs to no timesheet).
     */
    public function scopeKeyForUserTask(TimeTrackUserRef $user, string $taskId, ?string $boardId): ?string;

    /**
     * Scopes of existing entries (back-fill command), keyed by entry id; null = cannot be placed.
     *
     * @param list<TimeEntryView> $entries
     *
     * @return array<string, string|null>
     */
    public function scopeKeysForEntryViews(array $entries): array;
}
