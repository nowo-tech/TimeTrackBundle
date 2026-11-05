<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScope;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScopeProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Test scope provider: `$memberships[userId] = list of scope keys`; tasks on board `board-2` are in `org-b`, others in
 * `org-a`.
 */
final class FixedScopeProvider implements TimesheetScopeProviderInterface
{
    /**
     * @param array<int|string, list<string>> $memberships
     * @param array<string, string> $timezones scope key => zone
     */
    public function __construct(
        public array $memberships = [],
        private readonly array $timezones = [],
    ) {
    }

    public function scopesFor(UserInterface $user): array
    {
        return array_map(fn (string $key): TimesheetScope => new TimesheetScope($key, 'Org ' . $key, $this->timezones[$key] ?? null), $this->memberships[UserIdResolver::getId($user)] ?? []);
    }

    public function scopeKeyForTask(UserInterface $user, string $taskId, ?string $boardId): string
    {
        return $boardId === 'board-2' ? 'org-b' : 'org-a';
    }

    public function scopeKeysForEntries(array $entries): array
    {
        $keys = [];
        foreach ($entries as $entry) {
            $keys[$entry->getId()] = $entry->getBoardIdSnapshot() === 'board-2' ? 'org-b' : 'org-a';
        }

        return $keys;
    }
}
