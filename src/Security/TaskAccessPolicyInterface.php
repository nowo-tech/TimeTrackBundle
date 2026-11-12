<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Security;

use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/**
 * Since 1.23.0: framework-free twin of {@see TaskAccessCheckerInterface} (REQ-ARCH-001 rule 3), same
 * `client_api.task_access_checker` option. Deny by default: return true only when the user may track time on the task
 * now.
 */
interface TaskAccessPolicyInterface
{
    /**
     * Whether the user may track time on the task.
     */
    public function mayTrack(TimeTrackUserRef $user, string $taskId): bool;
}
