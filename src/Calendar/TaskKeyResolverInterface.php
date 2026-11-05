<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use Nowo\TimeTrackBundle\Dto\TaskReference;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.14 (host, contract §6.3, Q-O8, Q-B8): the task-key format. The bundle has no task keys (TaskBoard tasks have
 * UUIDs only), so the host says what a key looks like and which task it means. Beacon: `#<first 8 hex of the task
 * UUID>` (unique per organization) and Beacon task URLs. Configured with `calendar.task_key_resolver`; without it the
 * calendar suggestions and `resolve-keys` are off.
 */
interface TaskKeyResolverInterface
{
    /**
     * The task keys in an event title, in title order: at most 5, each at most 32 characters (the bundle drops the rest).
     * Called with the title in memory only; implementations MUST NOT store or log it.
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
