<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port\Internal;

use Nowo\TimeTrackBundle\Integration\BatchTaskDirectoryInterface;
use Nowo\TimeTrackBundle\Service\TimerService;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.12 (optional): a {@see TaskProviderInterface} that answers {@see TaskProviderInterface::canUserTrack()} for
 * many tasks at once. {@see TimerService::hiddenTaskEntryIds()} then makes one call per entry owner instead of one per
 * (owner, task), e.g. on a 10 000-row export.
 *
 * @internal since 2.0 (REQ-ARCH-001 rule 3): the shape the bundle asks this port through; the 1.x public interface
 * `Nowo\TimeTrackBundle\Integration\BatchTaskProviderInterface` (deprecated since 1.23.0) is removed in 2.0. Hosts implement {@see BatchTaskDirectoryInterface} (a gate adapts it).
 */
interface BatchTaskProviderInterface extends TaskProviderInterface
{
    /**
     * The subset of `$taskIds` the user may track (same answer as {@see TaskProviderInterface::canUserTrack()} for each).
     *
     * @param list<string> $taskIds distinct task ids
     *
     * @return list<string>
     */
    public function filterTrackable(UserInterface $user, array $taskIds): array;
}
