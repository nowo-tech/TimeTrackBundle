<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port\Internal;

use Nowo\TimeTrackBundle\Meeting\MeetingPage;
use Nowo\TimeTrackBundle\Meeting\MeetingQuery;
use Nowo\TimeTrackBundle\Meeting\MeetingSourceInterface;
use Nowo\TimeTrackBundle\Meeting\MeetingSyncState;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.14 (host, contract §22.6, Q-B13, Q-B14): the host's meeting read model (Beacon spec 210). The host owns OAuth,
 * tokens, sync, retention, organization switches and attendee matching; the bundle stores nothing about meetings and
 * only shapes and filters what this provider returns, per request. Configured with `meetings.provider`.
 *
 * @internal since 2.0 (REQ-ARCH-001 rule 3): the shape the bundle asks this port through; the 1.x public interface
 * `Nowo\TimeTrackBundle\Meeting\MeetingProviderInterface` (deprecated since 1.23.0) is removed in 2.0. Hosts implement {@see MeetingSourceInterface} (a gate adapts it).
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
