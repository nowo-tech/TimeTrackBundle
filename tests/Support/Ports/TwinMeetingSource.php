<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support\Ports;

use Nowo\TimeTrackBundle\Meeting\MeetingPage;
use Nowo\TimeTrackBundle\Meeting\MeetingQuery;
use Nowo\TimeTrackBundle\Meeting\MeetingSourceInterface;
use Nowo\TimeTrackBundle\Meeting\MeetingSyncState;
use Nowo\TimeTrackBundle\Tests\Stub\StubMeetingProvider;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/** Twin of {@see StubMeetingProvider} (same state). */
final class TwinMeetingSource implements MeetingSourceInterface
{
    public int $calls = 0;

    public function __construct(private readonly StubMeetingProvider $inner)
    {
    }

    public function meetingsOf(TimeTrackUserRef $user, MeetingQuery $query): MeetingPage
    {
        ++$this->calls;

        return $this->inner->meetingsFor(TwinPorts::user($user), $query);
    }

    public function syncStateOf(TimeTrackUserRef $user): MeetingSyncState
    {
        ++$this->calls;

        return $this->inner->stateFor(TwinPorts::user($user));
    }
}
