<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Meeting;

use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/**
 * Since 1.23.0: framework-free twin of {@see MeetingProviderInterface} (REQ-ARCH-001 rule 3), same `meetings.provider`
 * option: the host's meeting read model.
 */
interface MeetingSourceInterface
{
    /**
     * Own meetings of `$user` overlapping `[from, to)`, already minimal. MUST NOT return other people's meetings.
     */
    public function meetingsOf(TimeTrackUserRef $user, MeetingQuery $query): MeetingPage;

    /**
     * Feature state for this user (organization switch, connection). Cheap; called by `GET me`.
     */
    public function syncStateOf(TimeTrackUserRef $user): MeetingSyncState;
}
