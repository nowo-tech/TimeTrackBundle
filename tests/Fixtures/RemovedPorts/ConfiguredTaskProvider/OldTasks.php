<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Fixtures\RemovedPorts\ConfiguredTaskProvider;

use Nowo\TimeTrackBundle\Integration\TaskProviderInterface;

/**
 * 2.0 W1-1 fixture (registered explicitly and configured as `task_provider`, NOT loadable): the removed task provider
 * interface.
 */
final class OldTasks implements TaskProviderInterface
{
}
