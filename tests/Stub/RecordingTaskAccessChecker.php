<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Stub;

use Nowo\TimeTrackBundle\Security\TaskAccessCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function in_array;

/**
 * Task check that refuses `$denied` and records every task id it was asked about.
 */
final class RecordingTaskAccessChecker implements TaskAccessCheckerInterface
{
    /** @var list<string> */
    public array $checked = [];

    /**
     * @param list<string> $denied
     */
    public function __construct(public array $denied = [])
    {
    }

    public function canTrack(UserInterface $user, string $taskId): bool
    {
        $this->checked[] = $taskId;

        return !in_array($taskId, $this->denied, true);
    }
}
