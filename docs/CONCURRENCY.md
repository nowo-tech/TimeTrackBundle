# Concurrency

How TimeTrackBundle stays correct when the same data is written at the same time (nowo-tech REQ-RUNTIME-003): the
write paths, the conflict answer and its codes, the host options and the residual risks. The mechanics (the bundle's
runner, the lock order, what happens to your entity manager, the races fixed in each release) are in
[HIGH-LOAD](HIGH-LOAD.md).

## Table of contents

- [Write paths](#write-paths)
- [Conflict codes](#conflict-codes)
- [What is not a conflict](#what-is-not-a-conflict)
- [Host options](#host-options)
- [Workers and entity managers](#workers-and-entity-managers)
- [Tests and CI](#tests-and-ci)
- [Residual risks](#residual-risks)

## Write paths

Every transaction below is a unit of the bundle's runner (`nowo_time_track.transaction_runner`, built on
nowo-tech/transaction-doctrine ^1.6): one attempt for an HTTP write, replayed up to three attempts in all (outermost
transaction only) for background batches. "Owner lock" is `SELECT … FOR UPDATE` on the account's user row, always the
first lock of a unit ([One lock order](HIGH-LOAD.md#one-lock-order)).

| Path | Transaction | Concurrency control | Conflict answer |
| --- | --- | --- | --- |
| Timer start, stop, switch, discard, note (web, desktop, extension, `HostTimerApi`; since 1.36.0 also without a task) | One unit under the owner lock (stop and note also lock the timer row) | Owner lock; the running-timer slot re-checked under it; unique `user_id` plus savepoint insert as the last resort | 409 + `CONFLICT_001` + `Retry-After`. A lost start is the domain answer `timer_running` (`ActiveTimerConflictException`, 409, its own error key) |
| Manual entries, entry edit and delete, `POST entries` with `Idempotency-Key` | One unit under the owner lock; the idempotent answer is stored inside it | Owner lock; stored 201 replayed only when its entry is committed | 409 + `CONFLICT_001`; a retry with the key replays the stored answer |
| Timesheet first view, submit, withdraw, approve, reject, reopen, lock, auto-lock | One unit under the owner lock, then the timesheet row lock, read again | Owner lock, row lock, entries hashed only after both; unique period row plus catch | 409 + `CONFLICT_001`; a decision another request committed meanwhile is the domain answer `invalid_transition` |
| `edit_blocked` audit row of a refused write (`timesheets.audit_blocked_edits`) | Its own unit (a savepoint inside the caller's), since 1.31.0 | Timesheet row lock, last row and head read again under it, at most one row per timesheet per minute | The caller's own refusal (`period_locked` / `period_submitted`) stays the answer; a lock wait is 409 + `CONFLICT_001` |
| Balance ledger lines (working time) | One unit under the owner lock | Owner lock; the chain head read under it; unique (owner, scope, previous hash) plus catch | 409 + `CONFLICT_001`; a chain fork the unique key refused is the domain refusal `conflict` |
| Imports (`ImportedTimeEntryWriter::upsertMany()` and `upsert*()`) | One unit under the write lock of every owner of the batch, in id order, when `timesheets` is on (since 1.31.0); a single unit otherwise | Owner locks; stored rows and period locks read under them; new rows in savepoints; unique external identity, the batch read again once | 409 + `CONFLICT_001`. Rows of a person with no account take no owner lock (see [Residual risks](#residual-risks)) |
| Imported entry moves between owners and people (`moveTo*()`, `moveBetweenUsers()`) | One transaction for the whole move, replayed after a lock conflict; entries locked by primary key in id order | Ordered row locks; period locks read with `FOR SHARE` | 409 + `CONFLICT_001` (the whole move rolled back) |
| Task time moves (`TaskTimeMover::moveTasks()`) | One unit per batch of entries (and one statement for the timers of a chunk), replayed after a lock conflict | Entries locked by primary key in id order; moves are idempotent | 409 + `CONFLICT_001` when nothing was moved yet; **`CONFLICT_002`** (a committed `ConcurrencyConflict`) when an earlier batch is saved: run the move again to finish |
| Device flow (approve, deny), screenshot intents, acknowledgements | One unit; the request row (or the owner lock) is locked and read again | Row lock; unique key plus savepoint insert (the first record is kept) | 409 + `CONFLICT_001`; a second intent for one slot is the domain answer `duplicate` (409, its own key) |
| Calendar suggestions accepted as entries (`entries` with `Idempotency-Key` too) | One transaction per occurrence under the owner lock; the suggestion is read again, the entry and the accepted status commit together | Owner lock; a concurrent accept or dismiss wins | 409 + `CONFLICT_001`; the loser's outcome is `not_found` |
| Personal-data erasure (`TimeTrackPersonalDataInterface::erase()`, 1.35.0) | One statement-only unit (a savepoint inside the host's transaction): the running-timer check, the deletes and the entry pass commit together; no entity manager is flushed | The deletes read and lock the owner's rows (`FOR UPDATE`, id order), check the owner byte for byte and delete by id in batches; the free-text pass locks the owner's entries by primary key in id order, batch by batch, and writes only rows still owned and not anonymised; the anonymise pass reuses the former-person statements and takes no row lock (rows read unlocked, then one `UPDATE` by id per row: a concurrent change to such a row is overwritten by the anonymisation, which is the intended outcome); idempotent | `TransientStorageFailure` with nothing committed (409 + `CONFLICT_001` on a bundle route): replay. A running timer is the domain refusal `timer_running`; a timer started by another request after the check is not seen, so a host blocks the account (or signs it out) before it erases |
| Sign out everywhere (`ClientTokenRevoker::revokeAllForUser()`, 1.35.0) | One statement-only unit | The user's tokens read and locked by owner (`FOR UPDATE`), checked byte for byte, deleted by id; idempotent | `TransientStorageFailure`, nothing committed |
| Entry correction by id (`TimeEntryOperationsInterface::correct()`, 1.40.0) | One unit under the owner lock (a savepoint inside the host's transaction) | Owner lock, then the entry read again `FOR UPDATE` and compared byte for byte (owner, id) and with the caller's version (`TimeEntryRecord::version()`: optimistic check); period lock of the old and the new start and the overlap check read under the locks | A changed version is the domain answer `conflict` (409, nothing written: read again); a lock wait is `TransientStorageFailure` / 409 + `CONFLICT_001` |
| Manual entry by owner id (`TimeEntryOperationsInterface::createManual()`, 1.40.0) | The unit of manual entries (owner lock); with `user_mapping: id` the host-user row of a new owner is inserted first (savepoint, a concurrent insert of the same row tolerated) | As manual entries | As manual entries |
| Client session by ids (`ClientSessionRevokerInterface::revokeSession()`, 1.40.0) | One statement | The `DELETE` by id: of two concurrent calls one revokes, the other answers `NotFound`; idempotent | `TransientStorageFailure`, nothing committed |
| Retention purge, console commands | Statement-only units in batches | Idempotent batches | Console error: run it again |

Side effects (timer, entry, timesheet, ledger and import events, a revocation) run after the bundle's unit: after the
commit when the bundle opened the transaction, before the host's commit when the host opened it.

**Writes by id and the host's transaction (1.40.0).** The id-based writes (`correct()`, `createManual()`,
`revokeSession()`) and the personal-data erasure (`erase()`, 1.35.0) never open a connection or manager of their own:
they run on the bundle's manager (private or shared), which shares the connection of `database.entity_manager`. Without
an open transaction each is its own unit and is committed when the call returns. Inside a transaction the host opened on
that connection each is a savepoint of it: **the GDPR paths (art. 16 correction, art. 17 erasure) join the host
transaction**, so the host's own revision, audit or erasure-log rows commit or roll back together with the bundle's
change; a rollback of the host undoes the bundle's write (with the private manager its identity map is replaced, DBAL 4
at once, DBAL 3 at the next boundary). A lock conflict inside the host's transaction rolls back to the bundle's savepoint
and surfaces as `TransientStorageFailure`; the host rolls back and retries its whole unit. The read
ports of 1.35.0 (`Query\*`) take no lock and write nothing. The entry access port of 1.37.0 (`Access\TimeEntryAccessInterface`)
takes no lock and writes nothing either: a grant holds as of the check only (time of check). The bundle's write services
do not rely on it: under the owner's lock they re-read the entry and check again its owner, its state (imported,
anonymised) and the period lock (e.g. `TimeEntryBillableService::change()` re-reads the entry `FOR UPDATE`).

## Conflict codes

Since **1.31.0**, with nowo-tech/transaction-doctrine ^1.5 (request-level detection since the next release, ^1.6). A lock conflict (deadlock, lock wait timeout,
serialization failure, a lost savepoint after an implicit commit) that reaches the kernel on a bundle route is answered
by `StorageFailureSubscriber`, and by transaction-doctrine's responder on any route where the exception is a
`ConcurrencyConflict`; the bundle's `StorageFailureConflictFormatter` gives the client API its usual body.

Request-level detection (transaction-doctrine ^1.6): the code comes from `ConflictCode::forRequest($request, $failure)`, so
a commit made earlier in the same request by the host or another bundle (the request's `CommitMark`, taken at
`kernel.request` when the host's `nowo_transaction_doctrine.commit_detection` is on) also answers `CONFLICT_002`, with
no `Retry-After`; with no mark the failure alone decides, as before.

| Code | When | Status | `Retry-After` | Client should |
| --- | --- | --- | --- | --- |
| `CONFLICT_001` | A lock conflict and nothing of the request was committed | 409 | the runner's hint, else the policy's (1 s) | Retry |
| `CONFLICT_002` | The conflict came after part of the request was committed (a `ConcurrencyConflict` with `committed: true`, or a lost savepoint with no deadlock behind it) | 409 | none | Read again, do not repeat blindly |

Client API body (`/api/time-track/v1/…`, and the unversioned aliases), additive: the existing `error` and `message`
keys are unchanged and `code` is new.

```json
{ "error": "unavailable", "message": "The service is temporarily unavailable. Retry later.", "code": "CONFLICT_001" }
```

`CONFLICT_002` answers `"message": "Part of your change may have been saved. Reload before trying again."`. The
manage UI and the device verification page get an `HttpException` with the same status and `Retry-After` (none for
`CONFLICT_002`) plus the header `X-Conflict-Code`, rendered by your error page.

Code that calls the bundle directly receives `Nowo\TransactionContract\TransientStorageFailure` (a
`ConcurrencyConflict` after a replayed batch); `Nowo\TransactionDoctrine\ConflictCode::forFailure($failure)` gives the
code (`forRequest($request, $failure)` also sees a commit made earlier in the request).

## What is not a conflict

Unique-constraint violations and lost connections are never `CONFLICT_*` and, since 1.31.0, the bundle's kernel
subscriber no longer maps them to 409 (1.27.0 to 1.30.0 answered a unique violation anywhere in the chain as
`{"error": "conflict"}`, 409). Where an "only one" rule can be raced, the bundle resolves it where it happens:

- **Resolved with the winner's row:** the running timer, the timesheet's period row, the host user row, imported entries
  (the batch is read again once), acknowledgements, upload intents.
- **Domain answers with their own error key** (never `CONFLICT_*`): `ActiveTimerConflictException` (`timer_running`),
  `TimesheetException('conflict')` for a first view or transition that lost an optimistic or unique race on a store
  without the owner lock, `LedgerRefusedException('conflict')` for a ledger chain fork, `duplicate` for a second
  screenshot intent, since 1.40.0 `TimeEntryCorrectionException('conflict')` for a correction whose version is stale. They are expected results of a lost "only one" race, answered 409 by the client API with those
  keys; retrying gives the answer the winner made.
- **Anything else** (a unique violation your listeners or hosts cause, a lost connection, a constraint) is a real error:
  it reaches your error handling and answers 500.

## Host options

| Option | Default | Effect |
| --- | --- | --- |
| `nowo_transaction_doctrine.conflict_response.status` | `409` | `409` or `503`. 503 keeps the 1.29 answer for every bundle that reads the shared policy, TimeTrack included; the codes stay. |
| `nowo_transaction_doctrine.conflict_response.retry_after` | `1` | Seconds in `Retry-After` when the runner gives no hint. |
| `nowo_transaction_doctrine.controller_routes` | none | Listing TimeTrack routes makes the library's responder answer them (the bundle's formatter keeps the client API body, plus `code`). |
| `nowo_time_track.timesheets.audit_blocked_edits` | `false` | `edit_blocked` rows, written under the timesheet lock since 1.31.0. |
| `doctrine.dbal.use_savepoints: true` | — | Required with DBAL 3. |
| `innodb_lock_wait_timeout` / `lock_timeout` | server | 5–10 s for web workers. |

The bundle's writes always go through `nowo_time_track.transaction_runner`, never through a runner your application binds
to `TransactionRunnerInterface`.

## Workers and entity managers

- FrankenPHP worker mode with the kernel not reset between requests: the runner, the subscriber, the formatter and the
  stores are stateless; nothing of a request survives in a service ([HIGH-LOAD, Workers](HIGH-LOAD.md#workers)).
- Standard and DDD hosts: both are tested; with `user_mapping: id` the owner lock is on the bundle's host-user row.
- Private entity manager (the only mode since 2.0): the bundle reads and writes through its own manager; a failed unit
  replaces only that one. Your entity manager is never flushed, cleared, closed or reset by the bundle.

## Tests and CI

- **Load readiness** (required check, `Load readiness (…)`): MySQL 8.4, MariaDB 11.4 and PostgreSQL 16, each with
  doctrine/dbal 4 and 3, passwordless services, `--fail-on-skipped`. `tests/Database`: a lock wait answers 409 +
  `Retry-After` + `CONFLICT_001`; an import holds the owner's lock while it plans and writes; two processes adding
  ledger lines and starting timers. The race replays and the runner tests run on the same servers, with the shared and
  the private entity manager.
- **Functional suite** on MariaDB 10.6 and 11.4, MySQL 8.0 and 8.4, PostgreSQL 16 and SQLite.
- Conflict mapping: `tests/Unit/EventSubscriber/StorageFailureSubscriberTest.php` (001 with `Retry-After`, 002 without,
  the lost savepoint, 503 opt-in, a unique violation left alone), `StorageFailureConflictFormatterTest.php`,
  `tests/Unit/TaskMove/TaskTimeMoverTest.php` (002 after a saved batch).
- Writes by id (1.40.0): `tests/Database/LoadReadinessDatabaseTest.php` (two processes correcting one entry by
  read-version-write: no lost update; a correction behind the owner's lock: `CONFLICT_001`, nothing committed;
  sessions revoked by ids once), `tests/Functional/EntryOperations*Test.php` (the host's transaction rolled back and
  committed, both user mappings, private and shared manager).
- Races: `tests/Functional/ConcurrencyWaveTest.php` (QO-L3 import under the owner locks, QO-L4 audit row under the
  timesheet lock), `LoadReadiness*Test.php`, `MonitoringLoadReadinessTest.php`, `ImportedEntriesTest.php`.

## Residual risks

- **Imports of people with no account.** A row naming a person (`ImportedTimeEntryPerson`) has no user row to lock; the
  guard's ownerless rule reads the period lock without one, so a lock of that scope committed at that moment can still
  be overtaken by one import. Imports of users and claims are covered since 1.31.0.
- **Imports with timesheets off.** The owner locks are taken only with `timesheets.enabled` (nothing to check
  otherwise); two imports of one source still serialise on the unique external identity.
- **A transaction of yours around a bundle call.** Inside a REPEATABLE READ transaction that already read data, the read
  view predates the bundle's locks (a transition may hash without an entry committed just before its lock; the approval
  then answers `stale`). Call the bundle outside your own transaction, or use READ COMMITTED.
- **Replayed batches inside your transaction.** A transient failure inside a transaction of yours is not replayed:
  clear or reset your manager and retry your whole transaction.
- **`CONFLICT_002` is conservative** for task moves: it is given whenever an earlier batch moved rows, even when
  running the move again is harmless (it is idempotent).
- **Topology.** Galera / Percona XtraDB Cluster reports a certification failure as a deadlock (1213): transient, 409.
  PgBouncer in transaction pooling is fine (no session lock).
