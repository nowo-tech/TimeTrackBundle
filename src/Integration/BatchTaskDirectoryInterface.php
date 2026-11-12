<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Integration;

use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/**
 * Since 1.23.0: framework-free twin of {@see BatchTaskProviderInterface} (REQ-ARCH-001 rule 3): a
 * {@see TaskDirectoryInterface} that answers {@see TaskDirectoryInterface::canTrackTask()} for many tasks at once.
 */
interface BatchTaskDirectoryInterface extends TaskDirectoryInterface
{
    /**
     * The subset of `$taskIds` the user may track (same answer as {@see TaskDirectoryInterface::canTrackTask()} for each).
     *
     * @param list<string> $taskIds distinct task ids
     *
     * @return list<string>
     */
    public function filterTrackableFor(TimeTrackUserRef $user, array $taskIds): array;
}
