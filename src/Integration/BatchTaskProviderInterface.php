<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Integration;

use Nowo\TimeTrackBundle\Service\TimerService;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.12 (optional): a {@see TaskProviderInterface} that answers {@see TaskProviderInterface::canUserTrack()} for
 * many tasks at once. {@see TimerService::hiddenTaskEntryIds()} then makes one call per entry owner instead of one per
 * (owner, task), e.g. on a 10 000-row export.
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
