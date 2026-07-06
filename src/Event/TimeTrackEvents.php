<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

final class TimeTrackEvents
{
    public const TIMER_START = 'nowo_time_track.timer.start';

    public const TIMER_STOP = 'nowo_time_track.timer.stop';

    public const TIME_ENTRY_LIST_QUERY = 'nowo_time_track.time_entry.list_query';

    public const TIME_ENTRY_ACCESS_CHECK = 'nowo_time_track.time_entry.access_check';
}
