# High load

Since 1.27.0 (Beacon spec 239) the bundle is built for many concurrent requests on long-running workers: FrankenPHP
worker mode with the kernel **not** reset between requests, RoadRunner, Messenger consumers, and several clients of one
account at once (the desktop agent, the browser extension, the web UI, an agent through `HostTimerApi`).

The write-path table, the conflict codes (`CONFLICT_001` / `CONFLICT_002`), the host options and the residual risks are
in [CONCURRENCY](CONCURRENCY.md); this page keeps the mechanics and the history of each fix.

## Table of contents

- [One unit of work per write](#one-unit-of-work-per-write)
- [Your entity manager is yours](#your-entity-manager-is-yours)
- [Transient failures and conflicts: 409](#transient-failures-and-conflicts-409)
- [Races that no longer happen](#races-that-no-longer-happen)
- [One lock order](#one-lock-order)
- [Exact identifiers](#exact-identifiers)
- [Database settings](#database-settings)
- [Workers](#workers)
- [Known issues](#known-issues)
- [Tests](#tests)

## One unit of work per write

Every bundle write that needs a transaction runs on the bundle's own runner, `nowo_time_track.transaction_runner`
(`Nowo\TimeTrackBundle\Transaction\TimeTrackTransactionRunner`, built on nowo-tech/transaction-doctrine and implementing
nowo-tech/transaction-contract's `TransactionRunnerInterface`). The stores and services receive it by its concrete
class: the bundle never runs its writes through a runner your application binds to `TransactionRunnerInterface`
(DoctrineDeadlockRetryBundle's adapter, your own), because such a runner drives your entity manager.

- `run()`: one attempt, one transaction (a savepoint inside a transaction your code opened). The manager's pending work
  is flushed first, on its own; the unit's own changes are flushed inside the transaction, which the runner commits.
- `runStatements()`: the same for units made of bulk DQL / DBAL statements only (the retention purge, task and owner
  moves): no entity manager is flushed.
- `runRetrying()`: replays a transient failure (deadlock, lock wait timeout, serialization failure) up to three
  attempts in all, with full-jitter backoff (0–50 ms, then 0–100 ms, capped at 1 s), and **only when it is the
  outermost transaction**: inside a transaction of your code the server may have rolled back your work too, so the
  failure reaches you. The batches of a task move and of an owner move use it; HTTP writes make one attempt and answer
  409 + `Retry-After` (503 with the policy opt-in; the client retries).
- After any failure no transaction of the runner stays open: the levels it opened are rolled back, and the connection
  is closed only when that rollback fails (after a MySQL / MariaDB deadlock inside a nested flush the server drops the
  savepoints, so `ROLLBACK TO SAVEPOINT` fails with 1305). The worker's next request never inherits a nested connection.
- Side effects follow the commit and are never duplicated or lost: timer, entry, timesheet, ledger and import events
  are dispatched after `run()` returned; the `Idempotency-Key` answer of `POST entries` is stored inside the unit, so a
  retry that gets the account's lock right after the commit replays it, and it is dropped when the commit fails.

New rows whose unique key another request may take at the same moment (a running timer, a timesheet's period row, an
acknowledgement, an upload intent, an imported entry) are written through DBAL in a savepoint: when the key refuses one,
only that savepoint is rolled back, and the entity manager stays open.

## Your entity manager is yours

- **Private entity manager** (the only mode since 2.0): the bundle reads and writes through its own manager. No bundle
  write flushes, clears, closes or resets an application entity manager (REQ-ARCH-001 rule 6); a failed unit replaces
  only the private manager.

The connection is shared with your manager: when your code opens a transaction around a bundle call, the bundle's unit
nests in it as a savepoint, and a bundle failure rolls back to that savepoint only (on DBAL 3 enable
`use_savepoints: true`). A transient failure inside your transaction is not replayed by the bundle: it reaches you as
`TransientStorageFailure`; clear or reset your entity manager, then retry your whole transaction (after a MySQL / MariaDB
deadlock the server rolled back all of it).

Inside a transaction of yours that already read data (MySQL REPEATABLE READ, PostgreSQL REPEATABLE READ / SERIALIZABLE)
the read view predates the bundle's locks: a timesheet transition may then hash the entries without one committed just
before its lock (the approval later answers `stale`). Call transitions outside your own transaction, or use READ
COMMITTED.

## Transient failures and conflicts: 409

On the bundle's routes (a controller of the bundle, or a `nowo_time_track*` route), `StorageFailureSubscriber`
(`kernel.exception`, priority -64, after your own listeners) maps:

| Failure | Answer |
|---------|--------|
| `Nowo\TransactionContract\TransientStorageFailure`, or a DBAL `RetryableException` (deadlock, lock wait timeout), anywhere in the exception chain | `409 Conflict` with `Retry-After` (the runner's hint, else the shared policy's 1 s; since 1.30.0, was 503; `nowo_transaction_doctrine.conflict_response.status: 503` keeps 503). A `ConcurrencyConflict` with `committed: true` gets no `Retry-After` and a reload message |
| A DBAL `UniqueConstraintViolationException` | **Not mapped** since 1.31.0 (1.27.0 to 1.30.0: `409 {"error": "conflict"}`). A unique violation is not a lock conflict; the bundle resolves its expected "only one" races itself (see [CONCURRENCY](CONCURRENCY.md#what-is-not-a-conflict)) and any other one is a real error |

The client API (desktop agent, browser extension, calendar, meetings, monitoring, working time, device flow) answers its
usual error body, `{"error": "unavailable", "message": "…", "code": "CONFLICT_001"}` (since 1.31.0 `code` is
`CONFLICT_001` when nothing was committed and `CONFLICT_002`, without `Retry-After`, when part was), with its CORS
headers and `Cache-Control: no-store`. The manage UI and the device verification page get an `HttpException` of the
policy (header `X-Conflict-Code`) rendered by your error page. The storage error stays the previous exception (for your logs):
no SQL, no parameter reaches the client. Your own routes are never touched: your controllers that call bundle services
receive `TransientStorageFailure` and decide.

## Races that no longer happen

Each one has a test that fails on 1.26.0 (`tests/Functional/LoadReadiness*Test.php`, `MonitoringLoadReadinessTest`,
`ImportedEntriesTest`, `tests/Database`).

| Race (≤ 1.26) | Since 1.27.0 |
|---------------|--------------|
| Two starts of one account (web, extension, desktop, `HostTimerApi`): the loser's insert hit the unique `user_id` index, closed the manager and **reset your manager** (your entities detached) | The start re-checks the running timer under the account's write lock: the loser gets `ActiveTimerConflictException` (409) with the manager open |
| A stop while the task moved to another board (FR-008): the entry was built from the timer loaded before the delete and kept the **old board and scope** | The timer row is locked and read again before the entry is built: the time follows the moved task |
| Two notes kept on the running timer (`timer/discard-idle` keep): the second write put back the metadata loaded before the first one committed (**first note lost**) | The timer is locked and read again before the note is appended |
| Submit while an entry of the period was being written: the hash was taken before the submit's transaction, so the submitted period missed the entry (**approval refused as `stale`**) | Every transition takes the owner's lock, then the timesheet row's lock, re-reads the timesheet and only then hashes the entries; a decision another request committed meanwhile answers `invalid_transition`. Every entry and timer writer of the bundle takes the owner's lock too, imports included since 1.31.0 (audit QO-L3) |
| Two first views of a period: the second insert failed (`conflict`) and reset your manager | The period's row is read again under the owner's lock: both share it |
| A retry of `POST entries` with the same `Idempotency-Key` that got the account's lock right after the commit, before the answer was stored: an agent's retry wrote a **second entry**; a person's answered `overlap` and stored it for the key | The answer is stored inside the transaction; a stored 201 is replayed only when its entry is committed |
| Two approvals of one device request (double submit, another tab): **two credentials issued**, the first one orphaned; an approval racing a denial | The request is locked and read again: the later decision issues nothing and the page answers "expired" |
| Two screenshot intents for one slot (spec 199): both got an upload URL, then each upload refused the other's slot: **no screenshot stored** | The slot is checked again and the intent written under the account's lock: the second one answers 409 `duplicate` |
| A raced acknowledgement or upload intent with the same key: the flush failed and reset your manager | Written in a savepoint: the first record is kept, the manager stays open |
| Two ledger lines for one owner at once: one refused as `conflict`, and your manager reset | The rules and the chain head are read under the owner's lock: both lines chain |
| A raced import batch: the 1.11 retry worked only by resetting your manager (and failed closed without a registry) | The new entries are written in savepoints: the batch is read again with the manager open, with or without a registry |
| `HostTimerApi::start()` retrying after a lost start relied on the same reset | It retries with the manager open |

## One lock order

Every bundle write that locks takes its locks in this order, so two of them never wait for each other in a cycle:

1. the account's (owner's) user row (`SELECT … FOR UPDATE` on your users table, or on the bundle's host-user row with
   `user_mapping: id`): timer start, stop, switch, note, manual entries, timesheet transitions and first views, ledger
   lines, monitoring intents;
2. the timesheet row (transitions), the running timer row (stop, note), the device request row (approve / deny);
3. the entry rows (task and owner moves lock them by primary key, in id order, after reading the ids without a lock).

Stops are written even into a locked period (late-entry semantics): they take the account's lock, never the
timesheet's. Task moves read the period locks with a shared lock (`FOR SHARE`) inside their batch. Since 1.31.0 an import plans
and writes under the write lock of every owner of its batch, in id order (the owner's row first, as every other writer),
and reads the period locks under them; the `edit_blocked` audit row of a refusal is appended under the timesheet row's
lock (owner, then timesheet).

## Exact identifiers

The id columns that hold a person, owner or tenant scope (`scope_key`, and with `user_mapping: id` the user id and person
reference columns) use a binary **NO PAD** collation on MySQL / MariaDB since 1.24.1 (`utf8mb4_0900_bin` on MySQL ≥ 8.0.17,
`utf8mb4_nopad_bin` on MariaDB; `utf8mb4_bin`, PAD SPACE, only on older MySQL with a warning), and every lookup re-checks
the ids byte for byte (`===`) before it counts, pages or groups rows, so `'42 '` or `'Acme'` never stands for `'42'` or
`'acme'`. 1.27.0 changes no column. See [UPGRADING](UPGRADING.md#to-1241) for the SQL of an existing database.

## Database settings

- Keep lock waits short for web workers: MySQL `innodb_lock_wait_timeout` 5–10 s, PostgreSQL `lock_timeout` 5–10 s. A
  wait that times out answers 409 + `Retry-After`; a replayed background batch can wait up to three times that.
- DBAL 3: enable `use_savepoints: true` (DBAL 4 always uses savepoints). Without it a bundle failure inside your
  transaction marks it rollback-only.
- PostgreSQL behind PgBouncer: transaction pooling is fine (the bundle uses no session lock).
- Galera / Percona XtraDB Cluster: a certification failure is reported as a deadlock (1213): it is transient and
  answers 409 + `Retry-After`.

## Workers

- The runner, the subscriber and the stores are stateless; the runner's `reset()` (also `kernel.reset`) only replaces a
  shared manager a failed write closed. The boundary subscriber (`PrivateEntityManagerSubscriber`, every mode since
  1.27.0) calls it at the start and end of every main request, Messenger message and outermost console command.
- No static or per-process cache was added; nothing of a request survives in a service.

## Known issues

None open. Closed in 1.31.0 (they were planned for 1.27.x):

- **Imports and period locks (audit QO-L3):** an import checked the period locks before its batch's unit and took no
  lock, so an import that coincided with a submit, an approval or a lock of the same owner and period could write into
  that period (the approval then answered `stale`, or a locked period changed without a new hash). Now the plan and the
  write run under the owners' write locks in id order and the check is made under them. Rows of a person with no account
  have no user row to lock (see [CONCURRENCY](CONCURRENCY.md#residual-risks)).
- **`edit_blocked` audit rows (audit QO-L4):** a refused write in a locked period (with `timesheets.audit_blocked_edits`)
  appended its audit row without the row's lock; when a transition of the same timesheet committed at that moment the
  append failed on the version or sequence key and, with the shared manager, the caller got a 409 / 500 instead of the
  `period_locked` refusal. Now the timesheet row is locked and read again first (and the rate limit checked under the
  lock), so the refusal is always the answer.

## Tests

- `tests/Functional/LoadReadinessRaceTest.php`, `LoadReadinessTimesheetRaceTest.php`, `MonitoringLoadReadinessTest.php`:
  every race above played deterministically (a hook writes what a concurrent request commits at the worst moment), on
  SQLite and, in CI, on MySQL / MariaDB / PostgreSQL.
- `tests/Database` (`--testsuite database`, `TIME_TRACK_TEST_DATABASE_URL`, skipped on SQLite): a lock wait answers 409
  with `Retry-After`; two processes adding ledger lines for one owner all chain; two processes starting and stopping
  timers of one account never detach your entities; an import holds the owner's lock while it plans and writes. CI job
  "Load readiness": MySQL 8.4, MariaDB 11.4 and PostgreSQL 16, each with doctrine/dbal 4 and 3, passwordless services,
  `--fail-on-skipped`.
- `tests/Functional/ConcurrencyWaveTest.php`: the two 1.30.0 known issues (QO-L3, QO-L4) played deterministically.
- `tests/Functional/TransactionRunnerTest.php`, `TransactionRunnerPrivateTest.php`, `TimeTrackRowsTest.php`: the
  runner and the savepoint inserts in both entity manager modes.
