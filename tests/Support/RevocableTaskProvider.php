<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Nowo\TimeTrackBundle\Dto\TaskListQuery;
use Nowo\TimeTrackBundle\Dto\TaskReference;
use Nowo\TimeTrackBundle\Port\Internal\TaskProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function in_array;

/**
 * Since 2.1.0 (audit ZBQ-L3): the stub tasks, whose tracking a test revokes after a timer started (`$revoked`); every
 * `canUserTrack()` call is recorded. Finding and listing are unchanged (the task still exists).
 */
final class RevocableTaskProvider implements TaskProviderInterface
{
    /** @var list<string> task ids the user may no longer track */
    public array $revoked = [];

    /** @var list<string> task ids canUserTrack() was asked about, in order */
    public array $asked = [];

    private readonly StubTaskProvider $inner;

    public function __construct()
    {
        $this->inner = new StubTaskProvider();
    }

    /**
     * The stub task.
     */
    public function findForUser(string $taskId, UserInterface $user): ?TaskReference
    {
        return $this->inner->findForUser($taskId, $user);
    }

    /**
     * The stub tasks.
     */
    public function listTrackableForUser(UserInterface $user, TaskListQuery $query): array
    {
        return $this->inner->listTrackableForUser($user, $query);
    }

    /**
     * Whether the stub knows the task and it is not revoked (recorded).
     */
    public function canUserTrack(UserInterface $user, string $taskId): bool
    {
        $this->asked[] = $taskId;

        return !in_array($taskId, $this->revoked, true) && $this->inner->canUserTrack($user, $taskId);
    }
}
