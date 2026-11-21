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
     * `monitoringUploads`, `timesheets` and, when calendars are enabled, `calendarConnections`, `calendarRules` and
     * `calendarSuggestions`. Cells hold the stored text: a host writing a CSV passes them through
     * `TimeEntryCsv::cell()`.
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
     * changes). Bulk statements only: no entity manager is flushed, cleared or reset. Idempotent: a second call changes
     * nothing more.
     *
     * Kept: timesheets and their audit trail (the working-time register: a legal obligation, GDPR art. 17.3.b) and
     * calendar connections (`CalendarConnectionService::revokeAllFor()` revokes the host grant before deleting them).
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
