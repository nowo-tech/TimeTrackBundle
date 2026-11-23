<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Entry;

use Nowo\TimeTrackBundle\Exception\ManualEntryException;
use Nowo\TimeTrackBundle\Exception\PeriodWriteRefusedException;
use Nowo\TimeTrackBundle\Exception\TimeEntryCorrectionException;
use Nowo\TimeTrackBundle\Query\TimeEntryQueryInterface;
use Nowo\TimeTrackBundle\Query\TimeEntryRecord;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use Nowo\TransactionContract\TransientStorageFailure;

/**
 * Since 1.40.0 (Beacon, REQ-ARCH-001 rules 3 and 6): writes of time entries by ids, so a host corrects or creates
 * entries without loading bundle entities in its own entity manager and without writing the bundle's tables itself
 * (DQL, DBAL). Every write goes through the bundle's own (private, since 2.0 the only mode) entity manager and stores.
 * Public service (service id = this interface).
 * Not deprecated: 2.0 keeps it.
 *
 * The owner is a user id (`string|int`, the `getId()` of your user) or a {@see TimeTrackUserRef}; a reference whose user
 * object has another id than the reference is refused like an unknown user. Ids compare byte for byte (`'07'` is not
 * `7`, `ABC` is not `abc`). The host authorises the call (who may act for that owner); the bundle scopes the write to
 * the owner and applies its own rules (period lock, overlaps, validation).
 *
 * Each write is one unit of the bundle's runner (`nowo_time_track.transaction_runner`) under the owner's row lock: its
 * own transaction, or a savepoint of the transaction you opened on the same connection (the private manager shares the
 * connection), so your commit or rollback decides. A lock conflict surfaces as {@see TransientStorageFailure} with
 * nothing of the unit committed (REQ-RUNTIME-003 `CONFLICT_001`: retry). Events are dispatched after the unit: inside
 * your transaction that is before your commit, so a listener with side effects outside the database defers its work to
 * your own post-commit hook (audit ZAN-I1).
 *
 * Since 1.40.1 (audit ZAN-1) the overlap check and the period lock are read under a shared lock, so on MySQL and MariaDB
 * an earlier REPEATABLE READ snapshot of your transaction cannot hide an entry or a period lock committed since. On
 * PostgreSQL keep READ COMMITTED (the default) for a transaction around these calls (docs/CONCURRENCY.md, Residual risks).
 *
 * Reads: {@see TimeEntryQueryInterface::find()} returns one entry by id as a {@see TimeEntryRecord} (with
 * {@see TimeEntryRecord::version()}); it takes no lock and applies no access rule.
 *
 * Stateless (FrankenPHP worker mode, kernel reset false): nothing is kept between calls.
 */
interface TimeEntryOperationsInterface
{
    /**
     * Corrects the start, the end, the duration and the metadata of one of the owner's live entries, and returns the
     * values before and after (write your own revision record from them).
     *
     * Under the owner's lock the entry is read again with a row lock and checked: it must still belong to the owner
     * (byte for byte), be live (not imported) and not anonymised, and still have `$expectedVersion`
     * ({@see TimeEntryRecord::version()} of the record you read): a change committed meanwhile by anyone is refused with
     * `conflict`, never overwritten. Then the new values are checked (end after start, at most 24 h, not in the future,
     * a duration of at most the span), the period lock of the old and of the new start
     * ({@see PeriodWriteRefusedException}: locked, approved or submitted periods are never changed), and, when the times
     * change, overlaps with the owner's other live entries and running timer (not for a service account). Since 1.40.1 a
     * new start may be at most {@see TimeEntryCorrection::$maxAgeDays} days old (default `manual_entries.max_age_days`;
     * `entry_too_old`; only when the start changes), the 24 h cap applies only when the times change (a forgotten timer's
     * long entry can still get a note or duration fix), and the metadata may not grow past
     * 32 keys / 16 KB (`metadata_too_large`). A correction
     * that changes nothing writes nothing and dispatches nothing ({@see TimeEntryCorrectionResult::$changed} false).
     *
     * @param int|string|TimeTrackUserRef $owner The entry owner (see the class comment)
     * @param string $entryId The entry id (exact)
     * @param string $expectedVersion The version of the entry as you read it ({@see TimeEntryRecord::version()})
     *
     * @throws TimeEntryCorrectionException `not_found` (unknown id or owner, another owner's, ownerless or anonymised entry:
     *                                      one answer), `read_only`, `conflict`, `invalid_range`, `entry_too_long`,
     *                                      `entry_in_future`, `entry_too_old`, `invalid_duration`, `metadata_too_large`,
     *                                      `overlap`
     * @throws PeriodWriteRefusedException When the old or the new start is in a locked or submitted period
     * @throws TransientStorageFailure When the storage failed transiently (deadlock, lock wait timeout): nothing was
     *                                 committed, retry
     */
    public function correct(TimeTrackUserRef|string|int $owner, string $entryId, string $expectedVersion, TimeEntryCorrection $correction): TimeEntryCorrectionResult;

    /**
     * Creates a manual entry (source `manual`) for the owner, with the rules of `POST entries`: the task must be one the
     * owner may track (or none, with `entries.allow_without_task`), end after start, at most 24 h, not in the future,
     * not older than the allowed age, no overlap (except for a service account), period not locked or submitted.
     * Dispatches `nowo_time_track.time_entry.created`. With `user_mapping: relation` the owner must be a row of
     * `user_class`; with `user_mapping: id` the bundle's host-user row is created when needed (before the entry's unit: a
     * refused entry leaves that row, as a first timer start would). Since 1.40.1 the draft validates its allowed age and
     * metadata when it is built ({@see ManualTimeEntryDraft}).
     *
     * @param int|string|TimeTrackUserRef $owner The owner (see the class comment)
     *
     * @throws ManualEntryException `unknown_user`, or a refusal of the entry (`invalid_range`, `entry_too_long`,
     *                              `entry_in_future`, `entry_too_old`, `not_trackable`, `overlap`, `billable_forbidden`)
     * @throws PeriodWriteRefusedException When the period is locked or submitted
     * @throws TransientStorageFailure When the storage failed transiently: nothing was committed, retry
     */
    public function createManual(TimeTrackUserRef|string|int $owner, ManualTimeEntryDraft $draft): TimeEntryRecord;
}
