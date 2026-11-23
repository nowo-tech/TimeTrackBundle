<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port\Internal;

use Nowo\TimeTrackBundle\Security\TaskAccessPolicyInterface;
use Nowo\TimeTrackBundle\Security\TaskProviderTaskAccessChecker;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Per-request task check of the client API (`timer/start`, `timer/switch`; contract v0.2 §3/§6). Deny by default:
 * return true only when the user may track time on the task now. The API answers a missing task and a foreign task
 * with the same `403 task_forbidden` (no existence oracle). Default: {@see TaskProviderTaskAccessChecker}.
 *
 * @internal since 2.0 (REQ-ARCH-001 rule 3): the shape the bundle asks this port through; the 1.x public interface
 * `Nowo\TimeTrackBundle\Security\TaskAccessCheckerInterface` (deprecated since 1.23.0) is removed in 2.0. Hosts implement {@see TaskAccessPolicyInterface} (a gate adapts it).
 */
interface TaskAccessCheckerInterface
{
    /**
     * Whether the user may track time on the task.
     */
    public function canTrack(UserInterface $user, string $taskId): bool;
}
