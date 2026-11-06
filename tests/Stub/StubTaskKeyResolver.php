<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Stub;

use Nowo\TimeTrackBundle\Calendar\TaskKeyResolverInterface;
use Nowo\TimeTrackBundle\Dto\TaskReference;
use Symfony\Component\Security\Core\User\UserInterface;

use function mb_strlen;
use function preg_match_all;

/**
 * Test task-key resolver (fake, Beacon-like): keys are `#task-<n>` or `#<8 hex>`; `$tasks` maps a key to a task per
 * scope (`$tasks[scope][key]`). Remembers the key lists it resolved (never titles).
 */
final class StubTaskKeyResolver implements TaskKeyResolverInterface
{
    /** @var list<list<string>> */
    public array $resolved = [];

    /** @var list<int> lengths of the titles it was given (never the titles) */
    public array $titleLengths = [];

    /** @var list<mixed> extra raw values appended to every extraction (to test the bundle's filtering) */
    public array $extraKeys = [];

    /**
     * @param array<string, array<string, TaskReference>> $tasks
     */
    public function __construct(public array $tasks = [])
    {
        if ($tasks === []) {
            foreach (['org-a', ''] as $scope) {
                $this->tasks[$scope] = [
                    '#task-1' => new TaskReference('task-1', 'Implement login API', 'board-1'),
                    '#task-2' => new TaskReference('task-2', 'Write unit tests', 'board-1'),
                    '#task-3' => new TaskReference('task-3', 'Review pull requests', 'board-2'),
                    '#task-9' => new TaskReference('task-9', 'Not trackable', 'board-1'),
                ];
            }
        }
    }

    public function extractKeys(string $title): array
    {
        $this->titleLengths[] = mb_strlen($title);
        preg_match_all('/#(?:task-\d+|[0-9a-f]{8})\b/', $title, $matches);

        /** @var list<string> $keys */
        $keys = [...$matches[0], ...$this->extraKeys];

        return $keys;
    }

    public function resolve(UserInterface $user, string $scopeKey, array $keys): array
    {
        $this->resolved[] = $keys;
        $found            = [];
        foreach ($keys as $key) {
            if (isset($this->tasks[$scopeKey][$key])) {
                $found[$key] = $this->tasks[$scopeKey][$key];
            }
        }

        return $found;
    }
}
