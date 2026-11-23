<?php

declare(strict_types=1);

namespace App\TimeTrack;

use Nowo\TimeTrackBundle\Meeting\MeetingPage;
use Nowo\TimeTrackBundle\Meeting\MeetingQuery;
use Nowo\TimeTrackBundle\Meeting\MeetingSyncState;
use Nowo\TimeTrackBundle\Meeting\MeetingSyncStatus;
use Nowo\TimeTrackBundle\Port\Internal\MeetingProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * 2.0 test fixture (loaded with require_once, outside the bundle namespace): an application class on the bundle's
 * internal shape of a port. Only the bundle's own classes may be; a host implements the 1.23 twin.
 */
final class HostOnInternalShape implements MeetingProviderInterface
{
    public function meetingsFor(UserInterface $user, MeetingQuery $query): MeetingPage
    {
        return new MeetingPage([], $this->stateFor($user));
    }

    public function stateFor(UserInterface $user): MeetingSyncState
    {
        return new MeetingSyncState(MeetingSyncStatus::Connected, null);
    }
}
