<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Fixtures\RemovedPorts\AliasOfTaskProvider;

use Nowo\TimeTrackBundle\Dto\TaskListQuery;
use Nowo\TimeTrackBundle\Dto\TaskReference;
use Nowo\TimeTrackBundle\Integration\TaskDirectoryInterface;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * 2.0 W1-1 fixture (autodiscovered, loadable): a host task directory still aliased under the removed task provider id.
 */
#[AsAlias('Nowo\TimeTrackBundle\Integration\TaskProviderInterface')]
final class HostTasks implements TaskDirectoryInterface
{
    public function findTaskFor(string $taskId, TimeTrackUserRef $user): ?TaskReference
    {
        return null;
    }

    public function listTrackableFor(TimeTrackUserRef $user, TaskListQuery $query): array
    {
        return [];
    }

    public function canTrackTask(TimeTrackUserRef $user, string $taskId): bool
    {
        return false;
    }
}
