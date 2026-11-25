<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Fixtures\TwinIds\TwinIdDecorated;

use Nowo\TimeTrackBundle\Timesheet\TimesheetScopePolicyInterface;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * 2.0 audit ZAR-V1 fixture: the application decorates the twin id; TimeTrack and every bundle that injects the id must
 * both ask the decorator.
 */
#[AsDecorator(TimesheetScopePolicyInterface::class)]
final readonly class ScopeDecorator implements TimesheetScopePolicyInterface
{
    public function __construct(#[AutowireDecorated] private TimesheetScopePolicyInterface $inner)
    {
    }

    public function scopesOf(TimeTrackUserRef $user): array
    {
        return $this->inner->scopesOf($user);
    }

    public function scopeKeyForUserTask(TimeTrackUserRef $user, string $taskId, ?string $boardId): string
    {
        return 'decorated:' . $this->inner->scopeKeyForUserTask($user, $taskId, $boardId);
    }

    public function scopeKeysForEntryViews(array $entries): array
    {
        return $this->inner->scopeKeysForEntryViews($entries);
    }
}
