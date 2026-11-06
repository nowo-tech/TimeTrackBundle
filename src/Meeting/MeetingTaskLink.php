<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Meeting;

/**
 * Since 1.14 (contract §22.4, Q-B21): a task linked to a meeting (`{taskId, key}`, no title); the bundle keeps only the
 * tasks the caller can track.
 */
final readonly class MeetingTaskLink
{
    /**
     * Creates the link.
     */
    public function __construct(
        public string $taskId,
        public string $key,
    ) {
    }
}
