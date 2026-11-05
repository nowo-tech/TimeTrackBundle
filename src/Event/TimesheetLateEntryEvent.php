<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 1.13 ({@see TimeTrackEvents::TIMESHEET_LATE_ENTRY}): a timer that ran while its period was locked was stopped,
 * and its entry was written into the locked period (contract §5.6), so the approver can see it. Ids only.
 */
final class TimesheetLateEntryEvent extends Event
{
    /**
     * Creates the event.
     */
    public function __construct(
        public readonly string $timesheetId,
        public readonly string $entryId,
    ) {
    }
}
