<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port;

use Nowo\TimeTrackBundle\Meeting\MeetingPage;
use Nowo\TimeTrackBundle\Meeting\MeetingQuery;
use Nowo\TimeTrackBundle\Meeting\MeetingSourceInterface;
use Nowo\TimeTrackBundle\Meeting\MeetingSyncState;
use Nowo\TimeTrackBundle\Port\Internal\MeetingProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Asks a host {@see MeetingSourceInterface} (1.23.0) where the bundle asks its internal shape
 * {@see MeetingProviderInterface} (the 1.x interface of that name was removed in 2.0). Exceptions propagate. Built by {@see Ports}; stateless.
 *
 * @internal
 */
final readonly class MeetingSourceGate implements MeetingProviderInterface
{
    /**
     * Wraps the host source.
     */
    public function __construct(private MeetingSourceInterface $source)
    {
    }

    /**
     * The source's meetings for the user's reference.
     */
    public function meetingsFor(UserInterface $user, MeetingQuery $query): MeetingPage
    {
        return $this->source->meetingsOf(TimeTrackUserRefs::fromUser($user), $query);
    }

    /**
     * The source's sync state for the user's reference.
     */
    public function stateFor(UserInterface $user): MeetingSyncState
    {
        return $this->source->syncStateOf(TimeTrackUserRefs::fromUser($user));
    }
}
