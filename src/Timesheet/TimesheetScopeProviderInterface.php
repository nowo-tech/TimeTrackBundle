<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Timesheet;

use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.13 (host, `timesheets.scope_provider`): which tenant scopes a user tracks time in, and the scope of a task
 * or an entry. Must be scope-safe (never return a scope the user is not in), side-effect free and fast (called per
 * request and per written entry).
 *
 * @deprecated since 1.23.0, implement {@see TimesheetScopePolicyInterface} instead (framework-free, REQ-ARCH-001 rule 3); removed in 2.0.
 */
interface TimesheetScopeProviderInterface
{
    /**
     * Scopes of the user, the current one first (the client API's default scope).
     *
     * @return list<TimesheetScope>
     */
    public function scopesFor(UserInterface $user): array;

    /**
     * Scope of a task the user tracks (null = unknown: the entry belongs to no timesheet).
     */
    public function scopeKeyForTask(UserInterface $user, string $taskId, ?string $boardId): ?string;

    /**
     * Scopes of existing entries (back-fill command), keyed by entry id; null = cannot be placed.
     *
     * @param list<TimeEntry> $entries
     *
     * @return array<string, string|null>
     */
    public function scopeKeysForEntries(array $entries): array;
}
