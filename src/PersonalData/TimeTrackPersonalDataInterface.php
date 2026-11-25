<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\PersonalData;

use Nowo\TimeTrackBundle\Exception\PersonalDataErasureRefusedException;
use Nowo\TransactionContract\TransientStorageFailure;

/**
 * Since 1.35.0 (DDD-host checklist B7): GDPR export (arts. 15 / 20) and erasure (art. 17) of the TimeTrack data of one
 * user, by user id, for a host's personal-data flow. No entity, no user object; the host authorises the call. Public
 * service (`Nowo\TimeTrackBundle\PersonalData\TimeTrackPersonalDataInterface`).
 *
 * Stateless (FrankenPHP worker mode, kernel reset false).
 */
interface TimeTrackPersonalDataInterface
{
    /**
     * The user's TimeTrack data as JSON-ready sections (dates as RFC 3339 strings, never a token hash, device code or
     * calendar credential): `entries` (owned, every one, read in batches), `formerPersonEntries` (entries left under the
     * user's former reference), `runningTimer`, `clientSessions`, `deviceSignIns`, `monitoringAcknowledgements`,
     * `monitoringUploads`, `timesheets`, `balanceLedgerLines` (the user's balance movements: kind, seconds, reason),
     * `timesheetEvents` (the audit trail of the user's own timesheets, comments included) and, when calendars are enabled,
     * `calendarConnections`, `calendarRules` and `calendarSuggestions`. Cells hold the stored text: a host writing a CSV
     * passes them through `TimeEntryCsv::cell()`.
     *
     * Since 2.1.0 (Beacon spec 256, Q-TS-17) entries and the running timer that carry a subject or an activity type also
     * have `subjectType`, `subjectId`, `subjectLabel`, `activityTypeKey` and `activityTypeLabel`: ids and keys always,
     * labels resolved with the data subject as actor (a reference by id, without a user object) and masked when not
     * readable. Erasure keeps the three columns and the time: they describe the work, not the person.
     *
     * Most sections select scalars; `formerPersonEntries` and the calendar sections are read through the bundle's stores,
     * which load entities into the bundle's entity manager (the private one with `database.private_entity_manager: true`,
     * dropped at the request boundary; with the shared manager they stay managed until the host clears it). Nothing is
     * written or flushed.
     *
     * @param string $userId The user id (as `TimeTrackUserRef::$id`), compared byte for byte
     *
     * @return array<string, mixed>
     */
    public function export(string $userId): array;

    /**
     * Erases the user's TimeTrack personal data in one transaction (a savepoint inside the host's open transaction):
     * deletes the client sessions, device sign-ins, monitoring upload intents and monitoring acknowledgements, and treats
     * the time entries as `$entries` says ({@see TimeEntryErasure}; an entry is never deleted and its time never
     * changes). Bulk statements only: no entity manager is flushed, cleared or reset. Rows are matched by owner and
     * checked again byte for byte before they are written (by id), so a case-insensitive collation of the host's user ids
     * never reaches a look-alike. Idempotent: a second call changes nothing more.
     *
     * Kept: timesheets and their audit trail (the working-time register: a legal obligation, GDPR art. 17.3.b) and
     * calendar connections (`CalendarConnectionService::revokeAllFor()` revokes the host grant before deleting them; a
     * warning is logged when some remain).
     *
     * Owner decision 1 (confirmed 2026-11-21): `TimeEntryErasure::Anonymise` is the default; `FreeText` is opt-in.
     *
     * @param string $userId The user id (as `TimeTrackUserRef::$id`), compared byte for byte
     *
     * @throws PersonalDataErasureRefusedException `timer_running` when the user's timer runs (stop it first:
     *                                             `HostTimerApi::stop()` / `TimerService::stopForUserDeletion()`), or
     *                                             `invalid_user_id`; nothing is written
     * @throws TransientStorageFailure When the storage failed transiently (deadlock, lock wait timeout): nothing of the
     *                                 erasure is committed, retry
     */
    public function erase(string $userId, TimeEntryErasure $entries = TimeEntryErasure::Anonymise): TimeTrackErasureResult;
}
