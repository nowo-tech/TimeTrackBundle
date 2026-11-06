<?php

declare(strict_types=1);

namespace App\Meeting;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Meeting\MeetingPage;
use Nowo\TimeTrackBundle\Meeting\MeetingProviderInterface;
use Nowo\TimeTrackBundle\Meeting\MeetingQuery;
use Nowo\TimeTrackBundle\Meeting\MeetingSyncState;
use Nowo\TimeTrackBundle\Meeting\MeetingSyncStatus;
use Nowo\TimeTrackBundle\Meeting\MeetingTaskLink;
use Nowo\TimeTrackBundle\Meeting\MeetingView;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Demo meeting provider (TimeTrack 1.14 meetings API, `GET /api/time-track/me/meetings`): two **fake** meetings a day
 * relative to now — no real calendar, person or link. A real host (Beacon spec 210) answers from its synced read model.
 */
final class DemoMeetingProvider implements MeetingProviderInterface
{
    public function meetingsFor(UserInterface $user, MeetingQuery $query): MeetingPage
    {
        $meetings = [];
        $day      = $query->from->setTime(0, 0);
        for ($i = 0; $day < $query->to && $i < 15; ++$i, $day = $day->modify('+1 day')) {
            $key        = $day->format('Ymd');
            $meetings[] = new MeetingView('mtg_demo_standup_' . $key, $day->setTime(8, 30), $day->setTime(8, 45), 'UTC', false, true, false, 'Demo stand-up', 'https://meet.jit.si/TimeTrackDemoStandup', true, [], 0, [], $day);
            $meetings[] = new MeetingView('mtg_demo_private_' . $key, $day->setTime(12, 0), $day->setTime(13, 0), 'UTC', false, true, true, 'Hidden: private events lose their title', null, false, [], null, [new MeetingTaskLink('demo', '#demo')], $day);
        }

        return new MeetingPage($meetings, $this->stateFor($user));
    }

    public function stateFor(UserInterface $user): MeetingSyncState
    {
        return new MeetingSyncState(MeetingSyncStatus::Connected, new DateTimeImmutable('-2 minutes'));
    }
}
