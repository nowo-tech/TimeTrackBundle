<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Security;

use Nowo\TimeTrackBundle\Dto\TaskReference;
use Nowo\TimeTrackBundle\Integration\TaskProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

/**
 * Default task check: the task provider says the user may track the task and resolves it for that user. Any error of
 * the provider denies.
 */
final readonly class TaskProviderTaskAccessChecker implements TaskAccessCheckerInterface
{
    /**
     * Receives the task provider.
     */
    public function __construct(private TaskProviderInterface $taskProvider)
    {
    }

    /**
     * True only when the provider allows tracking and finds the task for the user.
     */
    public function canTrack(UserInterface $user, string $taskId): bool
    {
        if ($taskId === '') {
            return false;
        }

        try {
            return $this->taskProvider->canUserTrack($user, $taskId)
                && $this->taskProvider->findForUser($taskId, $user) instanceof TaskReference;
        } catch (Throwable) {
            return false;
        }
    }
}
