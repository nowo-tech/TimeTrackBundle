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

    /** {@see TimesheetTransitionEvent}: a timesheet was submitted (1.13). */
    public const TIMESHEET_SUBMITTED = 'nowo_time_track.timesheet.submitted';

    /** {@see TimesheetTransitionEvent}: a submitted timesheet was withdrawn by its owner (1.13). */
    public const TIMESHEET_WITHDRAWN = 'nowo_time_track.timesheet.withdrawn';

    /** {@see TimesheetTransitionEvent}: a timesheet was approved and locked (1.13). */
    public const TIMESHEET_APPROVED = 'nowo_time_track.timesheet.approved';

    /** {@see TimesheetTransitionEvent}: a timesheet was rejected with a comment (1.13). */
    public const TIMESHEET_REJECTED = 'nowo_time_track.timesheet.rejected';

    /** {@see TimesheetTransitionEvent}: a locked timesheet was reopened with a reason (1.13). */
    public const TIMESHEET_REOPENED = 'nowo_time_track.timesheet.reopened';

    /** {@see TimesheetTransitionEvent}: a period was auto-locked by the scheduled command (1.13). */
    public const TIMESHEET_AUTO_LOCKED = 'nowo_time_track.timesheet.auto_locked';

    /** {@see TimesheetAccessCheckEvent}: deny-only check of a timesheet action (1.13). */
    public const TIMESHEET_ACCESS_CHECK = 'nowo_time_track.timesheet.access_check';

    /** {@see TimeEntriesMovedEvent}: imported entries moved between a user and a person, or kept by a lock or the scope rule (1.16). */
    public const TIME_ENTRIES_MOVED = 'nowo_time_track.time_entries.moved';

    /** {@see TaskTimeMovedEvent}: the time of tasks moved to another board followed them, or a lock kept some of it (1.17). */
    public const TASK_TIME_MOVED = 'nowo_time_track.task_time.moved';

    /** {@see TimesheetLateEntryEvent}: an entry was written into a period locked while its timer ran (1.13). */
    public const TIMESHEET_LATE_ENTRY = 'nowo_time_track.timesheet.late_entry';

    /** {@see PeriodLockRefusedEvent}: a write path was refused by a locked or submitted period (1.13). */
    public const PERIOD_LOCK_REFUSED = 'nowo_time_track.period_lock.refused';

    /** {@see BalanceLedgerLineAddedEvent}: a balance ledger line was appended (1.13). */
    public const BALANCE_LEDGER_LINE_ADDED = 'nowo_time_track.balance_ledger.line_added';

    /** Since 1.13: {@see TaskEffortAccessCheckEvent} — may the user see the all-users `loggedSeconds` of a task? (deny by default). */
    public const TASK_EFFORT_ACCESS_CHECK = 'nowo_time_track.task_effort.access_check';

    /** Since 1.14: {@see CalendarConnectionChangedEvent} — a calendar connection was created, renewed, paused, resumed or revoked. */
    public const CALENDAR_CONNECTION_CHANGED = 'nowo_time_track.calendar.connection_changed';

    /** Since 1.14: {@see CalendarSyncFinishedEvent} — one connection was synced (counts and error code only). */
    public const CALENDAR_SYNC_FINISHED = 'nowo_time_track.calendar.sync_finished';

    /** Since 1.14: {@see TimeEntryCreatedEvent} — an entry was created without a timer (`POST entries`, an accepted calendar suggestion). */
    public const TIME_ENTRY_CREATED = 'nowo_time_track.time_entry.created';

    /** Since 1.14: {@see CalendarSuggestionDecidedEvent} — calendar suggestions were accepted or dismissed (ids only). */
    public const CALENDAR_SUGGESTION_DECIDED = 'nowo_time_track.calendar.suggestion_decided';

    /** Since 1.21: {@see TimeEntryBillableChangedEvent} — the `billable` flag of an entry changed (audit record, ids only). */
    public const TIME_ENTRY_BILLABLE_CHANGED = 'nowo_time_track.time_entry.billable_changed';

    /** Since 1.26: {@see MonitoringSpanDiscardedEvent} — the worker discarded a span of tracked time; delete its evidence (only while monitoring is configured). */
    public const MONITORING_SPAN_DISCARDED = 'nowo_time_track.monitoring.span_discarded';
}
