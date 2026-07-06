<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Bridge;

use Nowo\TimeTrackBundle\Dto\TaskListQuery;
use Nowo\TimeTrackBundle\Dto\TaskReference;
use Nowo\TimeTrackBundle\Integration\TaskProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_slice;

/**
 * Demo/test task provider with in-memory sample tasks.
 * Replace with TaskBoardBundle bridge in production.
 */
final class StubTaskProvider implements TaskProviderInterface
{
    /** @var array<string, TaskReference> */
    private array $tasks;

    public function __construct()
    {
        $this->tasks = [
            'task-1' => new TaskReference('task-1', 'Implement login API', 'board-1', 'Sprint 1'),
            'task-2' => new TaskReference('task-2', 'Write unit tests', 'board-1', 'Sprint 1'),
            'task-3' => new TaskReference('task-3', 'Review pull requests', 'board-2', 'Backlog'),
        ];
    }

    public function findForUser(string $taskId, UserInterface $user): ?TaskReference
    {
        return $this->tasks[$taskId] ?? null;
    }

    public function listTrackableForUser(UserInterface $user, TaskListQuery $query): array
    {
        $tasks = array_values($this->tasks);

        if ($query->search !== null && $query->search !== '') {
            $search = mb_strtolower($query->search);
            $tasks  = array_values(array_filter(
                $tasks,
                static fn (TaskReference $task): bool => str_contains(mb_strtolower($task->title), $search),
            ));
        }

        return array_slice($tasks, $query->offset, max(1, $query->limit));
    }

    public function canUserTrack(UserInterface $user, string $taskId): bool
    {
        return isset($this->tasks[$taskId]);
    }
}
