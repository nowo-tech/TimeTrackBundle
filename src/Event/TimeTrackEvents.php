<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

/**
 * Names of the events dispatched by the bundle.
 */
final class TimeTrackEvents
{
    public const TIMER_START = 'nowo_time_track.timer.start';

    public const TIMER_STOP = 'nowo_time_track.timer.stop';

    public const TIME_ENTRY_LIST_QUERY = 'nowo_time_track.time_entry.list_query';

    public const TIME_ENTRY_ACCESS_CHECK = 'nowo_time_track.time_entry.access_check';

    /** {@see IdleResolvedEvent}: the user's decision about an idle span was applied (1.10). */
    public const IDLE_RESOLVED = 'nowo_time_track.idle.resolved';

    /** {@see ClientApiEvent}: client API audit trail (1.9). */
    public const CLIENT_API = 'nowo_time_track.client_api';

    /** {@see TimeEntryImportedEvent}: an imported entry was created or changed by the writer (1.11). */
    public const TIME_ENTRY_IMPORTED = 'nowo_time_track.time_entry.imported';

    /** {@see TimeEntriesRemovedEvent}: imported entries were deleted (one, or in bulk) (1.11). */
    public const TIME_ENTRIES_REMOVED = 'nowo_time_track.time_entries.removed';
}
