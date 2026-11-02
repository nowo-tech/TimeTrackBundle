<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Per-request task check of the client API (`timer/start`, `timer/switch`; contract v0.2 §3/§6). Deny by default:
 * return true only when the user may track time on the task now. The API answers a missing task and a foreign task
 * with the same `403 task_forbidden` (no existence oracle). Default: {@see TaskProviderTaskAccessChecker}.
 */
interface TaskAccessCheckerInterface
{
    /**
     * Whether the user may track time on the task.
     */
    public function canTrack(UserInterface $user, string $taskId): bool;
}
