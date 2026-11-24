<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Fixtures\TwinIds\TwinIdElsewhere;

use Nowo\TimeTrackBundle\Timesheet\TimesheetScopePolicyInterface;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * 2.0 audit ZAR-V1 fixture: the application points the twin id at another service than the configured scope provider
 * (TimeTrack and TaskBoard would ask different scopes).
 */
#[AsAlias(TimesheetScopePolicyInterface::class)]
final class OtherScopes implements TimesheetScopePolicyInterface
{
    public function scopesOf(TimeTrackUserRef $user): array
    {
        return [];
    }

    public function scopeKeyForUserTask(TimeTrackUserRef $user, string $taskId, ?string $boardId): string
    {
        return 'other';
    }

    public function scopeKeysForEntryViews(array $entries): array
    {
        return [];
    }
}
