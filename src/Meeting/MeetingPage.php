<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Meeting;

/**
 * Since 1.14 (contract §22.6): the provider's answer — meetings, the sync state and whether it stopped early.
 */
final readonly class MeetingPage
{
    /**
     * @param list<MeetingView> $meetings
     */
    public function __construct(
        public array $meetings,
        public MeetingSyncState $sync,
        public bool $truncated = false,
    ) {
    }
}
