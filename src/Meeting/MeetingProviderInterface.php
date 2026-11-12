<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Meeting;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.14 (host, contract §22.6, Q-B13, Q-B14): the host's meeting read model (Beacon spec 210). The host owns OAuth,
 * tokens, sync, retention, organization switches and attendee matching; the bundle stores nothing about meetings and
 * only shapes and filters what this provider returns, per request. Configured with `meetings.provider`.
 *
 * @deprecated since 1.23.0, implement {@see MeetingSourceInterface} instead (framework-free, REQ-ARCH-001 rule 3); removed in 2.0.
 */
interface MeetingProviderInterface
{
    /**
     * Own meetings of `$user` overlapping `[from, to)`, already minimal (declined and cancelled events left out). MUST
     * NOT return other people's meetings.
     */
    public function meetingsFor(UserInterface $user, MeetingQuery $query): MeetingPage;

    /**
     * Feature state for this user (organization switch, connection). Cheap; called by `GET me`.
     */
    public function stateFor(UserInterface $user): MeetingSyncState;
}
