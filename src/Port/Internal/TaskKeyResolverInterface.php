<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port\Internal;

use Nowo\TimeTrackBundle\Calendar\TaskKeyLookupInterface;
use Nowo\TimeTrackBundle\Dto\TaskReference;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.14 (host, contract §6.3, Q-O8, Q-B8): the task-key format. The bundle has no task keys (TaskBoard tasks have
 * UUIDs only), so the host says what a key looks like and which task it means. Beacon: `#<first 8 hex of the task
 * UUID>` (unique per organization) and Beacon task URLs. Configured with `calendar.task_key_resolver`; without it the
 * calendar suggestions and `resolve-keys` are off.
 *
 * @internal since 2.0 (REQ-ARCH-001 rule 3): the shape the bundle asks this port through; the 1.x public interface
 * `Nowo\TimeTrackBundle\Calendar\TaskKeyResolverInterface` (deprecated since 1.23.0) is removed in 2.0. Hosts implement {@see TaskKeyLookupInterface} (a gate adapts it).
 */
interface TaskKeyResolverInterface
{
    /**
     * The task keys in an event title, in title order: at most 5, each at most 32 characters (the bundle drops the rest).
     * Called with the title in memory only (at most 1024 characters, cut by the bundle); implementations MUST NOT store
     * or log it and MUST use linear-time matching (no backtracking regex): titles come from anyone who can send the
     * user an invitation.
     *
     * @return list<string>
     */
    public function extractKeys(string $title): array;

    /**
     * The tasks the keys mean in the user's tenant scope (key → task). Unknown keys and tasks of other scopes are left
     * out; the bundle checks separately that the user may track each task.
     *
     * @param list<string> $keys
     *
     * @return array<string, TaskReference>
     */
    public function resolve(UserInterface $user, string $scopeKey, array $keys): array;
}
