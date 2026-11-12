<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support\Ports;

use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScopePolicyInterface;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/** Twin of {@see FixedScopeProvider} (same state, same rules). */
final class TwinScopePolicy implements TimesheetScopePolicyInterface
{
    public int $calls = 0;

    public function __construct(private readonly FixedScopeProvider $inner)
    {
    }

    public function scopesOf(TimeTrackUserRef $user): array
    {
        ++$this->calls;

        return $this->inner->scopesFor(TwinPorts::user($user));
    }

    public function scopeKeyForUserTask(TimeTrackUserRef $user, string $taskId, ?string $boardId): string
    {
        ++$this->calls;

        return $this->inner->scopeKeyForTask(TwinPorts::user($user), $taskId, $boardId);
    }

    public function scopeKeysForEntryViews(array $entries): array
    {
        ++$this->calls;
        $keys = [];
        foreach ($entries as $entry) {
            $keys[$entry->id] = $entry->boardId === 'board-2' ? 'org-b' : 'org-a';
        }

        return $keys;
    }
}
