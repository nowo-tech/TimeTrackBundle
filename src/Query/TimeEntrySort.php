<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Query;

/**
 * Since 1.35.0: the order of a {@see TimeEntryQueryInterface::list()} page. Every order ends on the entry id, so pages
 * are stable.
 */
enum TimeEntrySort: string
{
    /** Newest start first (the order of the bundle's own lists). */
    case StartedAtDescending = 'started_at_desc';

    /** Oldest start first. */
    case StartedAtAscending = 'started_at_asc';

    /** By entry id (the keyset order of {@see TimeEntryQueryInterface::listAfter()}). */
    case IdAscending = 'id_asc';
}
