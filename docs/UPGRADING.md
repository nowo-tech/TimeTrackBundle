# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 1.22.0](#to-1220)
- [To 1.21.0](#to-1210)
- [To 1.20.0](#to-1200)
  - [To 1.20.0](#to-1200-1)
- [To 1.19.1](#to-1191)
- [To 1.19.0](#to-1190)
  - [Behaviour change: wrapper security users need `accepted_user_classes` (JV-T3)](#behaviour-change-wrapper-security-users-need-accepted_user_classes-jv-t3)
  - [Calendar readers for your meeting sync (Beacon spec 210)](#calendar-readers-for-your-meeting-sync-beacon-spec-210)
  - [Other additions](#other-additions)
- [To 1.18.0](#to-1180)
- [To 1.17.1](#to-1171)
- [To 1.17.0](#to-1170)
- [To 1.16.2](#to-1162)
- [To 1.16.1](#to-1161)
- [To 1.16.0](#to-1160)
- [To 1.15.0](#to-1150)
- [To 1.14.0](#to-1140)
- [To 1.13.0](#to-1130)
- [To 1.12.0](#to-1120)
- [To 1.11.0](#to-1110)
- [To 1.10.3](#to-1103)
- [To 1.10.2](#to-1102)
- [To 1.10.1](#to-1101)
- [To 1.10.0](#to-1100)
- [To 1.9.2](#to-192)
- [To 1.9.1](#to-191)
- [To 1.9.0](#to-190)
  - [To 1.9.0](#to-190-1)
- [To 1.8.0](#to-180)
- [To 1.7.0](#to-170)
- [To 1.6.0](#to-160)
- [To 1.5.2](#to-152)
- [From 1.5.0 to 1.5.1](#from-150-to-151)
- [From 1.4.0 to 1.5.0](#from-140-to-150)
- [From 1.3.7 to 1.4.0](#from-137-to-140)
- [From 1.3.6 to 1.3.7](#from-136-to-137)
- [From 1.3.5 to 1.3.6](#from-135-to-136)
- [From 1.3.4 to 1.3.5](#from-134-to-135)
- [From 1.3.2 to 1.3.3](#from-132-to-133)
- [1.2.0 (2026-08-03)](#120-2026-08-03)
  - [Install / update](#install--update)
  - [Behaviour change (manage roles)](#behaviour-change-manage-roles)
  - [New optional config](#new-optional-config)
  - [Breaking changes](#breaking-changes)
- [1.1.0 (2026-07-30)](#110-2026-07-30)
  - [Layout integration (REQ-UI-001)](#layout-integration-req-ui-001)
  - [CSS framework (REQ-UI-001)](#css-framework-req-ui-001)
- [1.0.x patch releases](#10x-patch-releases)
  - [1.0.6 (2026-07-29)](#106-2026-07-29)
  - [1.0.5 (2026-07-16)](#105-2026-07-16)
  - [1.0.4 (2026-07-13)](#104-2026-07-13)
  - [1.0.3 (2026-07-08)](#103-2026-07-08)
  - [1.0.2 (2026-07-07)](#102-2026-07-07)
  - [1.0.1 (2026-07-06)](#101-2026-07-06)
- [1.0.0 (2026-07-06)](#100-2026-07-06)
  - [Requirements](#requirements)
  - [Installation checklist](#installation-checklist)
  - [Client apps (repository only)](#client-apps-repository-only)
- [To 1.3.5](#to-135)
- [To 1.3.4](#to-134)
- [To 1.3.2](#to-132)
- [To 1.3.1](#to-131)
- [To 1.3.0](#to-130)
  - [Twig Extra Bundle (REQ-TWIG-004)](#twig-extra-bundle-req-twig-004)
  - [Twig-CS-Fixer (maintainers)](#twig-cs-fixer-maintainers)

## Unreleased

## To 1.22.0

Target **1.22.0** (minor), from **1.21.0**:

```bash
composer update nowo-tech/time-track-bundle
```

No migration, no schema change, no new scope. **Nothing to change**: without `client_api.leave_provider` every client
API body is the 1.21 one.

To let the clients show *On leave until …* (Beacon spec 204 FR-029):

- **Standard host**: write a service implementing `Nowo\TimeTrackBundle\Client\ClientUserLeaveProviderInterface`
  (`getCurrentLeave(string $userId, DateTimeImmutable $day): ?ClientUserLeave`) that reads your leave entity by
  `$userId`, and set it as `client_api.leave_provider`.
- **DDD host**: the same port is an infrastructure adapter over your application's leave query; it takes the id string
  and returns the bundle's `ClientUserLeave` value object, nothing from Symfony or Doctrine.
- In both: return `ClientUserLeave::absent($until)` for medical / sick leave and for any type the devices must not
  show, `ClientUserLeave::typed($publicLabel, $until)` otherwise. Never put a reason, a comment or an attachment in a
  label. `GET workday` must answer, so a `workday_provider` is needed for the `leave` key.
- Optional: `client_api.links_leave_route` (your Leave page, https) for `GET me` `links.leave`.
- **Days off**: with `client_api.leave_provider` set, your `ClientUserDaysOffProviderInterface` may return
  `kind: 'leave'` for approved leave days; the label is always sent as null. Without the leave provider such an entry is
  still dropped, as in 1.21.
- **Reminders**: your workday provider / register must still suppress reminders on leave days itself (spec 204
  FR-025). The bundle's `reminder: null` is a second layer that fails open when the leave provider throws.
- `GET workday` now always sends `Cache-Control: no-store, private` and `Vary: Authorization`.
- If you construct `ClientWorkingTimeContext` by hand, the new arguments are trailing and optional.
- If your clients validate `daysOff[].kind`, accept `leave` (treat it like `vacation`).

## To 1.21.0

From **1.20.0**:

```bash
composer update nowo-tech/time-track-bundle
```

Target **1.21.0** (minor), from **1.20.0**:

```bash
composer update nowo-tech/time-track-bundle
```

No migration and no schema change (`time_track_entries.billable` exists since 1.11). **Nothing to change**: without a
provider new entries keep `billable: null` as before, and the new route only answers clients that call it.

- **If you write `billable` yourself** (a DBAL statement, a Doctrine listener that sets it on `prePersist`), replace it:
  - the default for new entries → alias `Nowo\TimeTrackBundle\Integration\TimeEntryBillableDefaultProviderInterface`
    to a service of yours (`defaultFor(string $scopeKey, ?string $taskRef): ?bool`; null = no default);
  - a change → `TimeEntryBillableService::change()` / `changeById()`, which checks the edit grant and the period lock and
    dispatches `TimeEntryBillableChangedEvent` for your audit store.
- **Edit rules** are those of `TeamAccessGuard::canEditEntry()`: a `nowo_time_track.time_entry.access_check` listener
  that answers the `edit` action now also decides who may change `billable`, unless it answers the new `edit_billable`
  action (`TeamAccessGuard::ACTION_EDIT_BILLABLE`), which comes after it and wins. An explicit `billable` on `POST
  entries` asks `edit_billable` too.
- **A store of your own** may implement `FindableTimeEntryRepositoryInterface` (`findById()`): the billable change then
  re-reads the entry inside the owner's transaction and checks it again. Implement the optional
  `LockingFindableTimeEntryRepositoryInterface` (`findForUpdate()`, a `SELECT … FOR UPDATE` re-read) to row-lock it as
  well; without it the entry is only re-read. Nothing breaks if you implement neither.
- **Client API**: `PATCH entries/{id}` exists (`clients.routes.entries_update`, default
  `/api/time-track/entries/{id}`). If a reverse proxy or firewall lists allowed methods, allow `PATCH` on that path.
- If you construct `TimeTrackCalendarApiController` by hand, pass the new `TimeEntryBillableService` argument.

## To 1.20.0

From **1.19.1**:

```bash
composer update nowo-tech/time-track-bundle
```

### To 1.20.0

From **1.19.1**:

```bash
composer update nowo-tech/time-track-bundle
```

Target **1.20.0** (minor). No migration, no schema change, no client contract change. **Nothing to change** if you keep
the OAuth apps in `calendar.oauth.google` / `.microsoft`: without `calendar.oauth.app_provider` the helpers behave
exactly as in 1.19.1.

To keep the apps in your database instead (encrypted, edited in an admin page, write-only):

1. Implement `Calendar\CalendarOAuthAppProviderInterface` (example with InstanceSettings:
   [USAGE.md](USAGE.md#calendar-oauth-apps-at-runtime-120)) and set `calendar.oauth.app_provider` to its service id.
2. Copy the client ids and secrets from `.env` into the admin page, deploy, check the Integrations page, then remove the
   `.env` values (with a provider they are ignored and no longer compiled into the container).
3. Handle the new `CalendarSyncException::NOT_CONFIGURED` (`not_configured`) where you call `authorizationUrl()`,
   `exchangeCode()` or `refresh()`: the app was removed. Show it on the connection, skip it in your refresh job and keep
   the stored tokens. In a Messenger refresh handler, rethrow it as
   `Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException` so the message is not retried in a
   loop. `revoke()` needs no app (Google revokes by the token alone; Microsoft never revokes).
4. If the Symfony profiler can be on where real secrets are used, set `calendar.http_client` to a client it does not
   trace: its HTTP Client panel records token request bodies, `client_secret` included (audit ML-L1). Better: never
   enable the profiler there.
5. Optional: use `Calendar\CalendarOAuthClients` (`configuredProviders()`, `isConfigured()`) for provider discovery.

Code that builds `GoogleCalendarOAuthClient` / `MicrosoftCalendarOAuthClient` by hand keeps working; both take an
optional `$appProvider` and `$logger` (PSR-3) after the existing ones. Microsoft tenants `.` and `..` now fall back to
`common` (audit ML-I2).

## To 1.19.1

From **1.19.0**:

```bash
composer update nowo-tech/time-track-bundle
```

Target **1.19.1** (patch). Nothing to change: if your `Psr\Clock\ClockInterface` points to a clock that is not a
`Symfony\Component\Clock\ClockInterface`, the calendar readers and OAuth helpers now build (1.19.0 failed with a
`TypeError`). Code that builds `CalendarTokenEndpoint` or `CalendarHttpClient` by hand may pass any PSR-20 clock.

## To 1.19.0

From **1.18.0**:

```bash
composer update nowo-tech/time-track-bundle
```

Target **1.19.0**, from **1.18.0**:

```bash
composer update nowo-tech/time-track-bundle
```

No migration, no schema change. Clients see one new key (`GET me` `links.workingTime`, null unless you configure it).

### Behaviour change: wrapper security users need `accepted_user_classes` (JV-T3)

Only if your firewall user is **not** your `user_class` entity (the 1.18 wrapper / DTO path). Since 1.19 the default
`DoctrineUserReferenceResolver` converts such a user only when its class is listed, and only when a `user_class` row
has its id; otherwise the call fails with `AccessDeniedException` (403) before anything is written. Before, any
`UserInterface` with a usable `getId()` was turned into an unchecked reference: a user of another provider whose id
matched got entries and tokens of `user_class`#id, and an unknown id was stored wherever the database did not enforce
the foreign keys.

```yaml
nowo_time_track:
  user_class: App\Entity\User
  accepted_user_classes:
    - App\Security\SecurityUser      # your wrapper class (interfaces are refused)
```

Hosts whose firewall user **is** the `user_class` entity (the standard path, Beacon) change nothing. Hosts that alias
`UserReferenceResolverInterface` to their own resolver change nothing either. The resolver now loads the user with
`find()` (one primary-key read per write, usually from the identity map) instead of `getReference()`.

### Calendar readers for your meeting sync (Beacon spec 210)

Optional. If you keep your own Google / Graph readers for meetings, you can replace them with the bundle's (see
[MEETINGS.md](MEETINGS.md#reading-the-calendars-in-your-sync)):

```yaml
nowo_time_track:
  calendar:
    microsoft_scope: Calendars.Read        # default Calendars.ReadBasic: no body, task keys from titles only
    oauth:                                 # optional OAuth helpers; empty = not configured
      google:    { client_id: '%env(CALENDAR_GOOGLE_CLIENT_ID)%', client_secret: '%env(CALENDAR_GOOGLE_CLIENT_SECRET)%' }
      microsoft: { client_id: '%env(CALENDAR_MICROSOFT_CLIENT_ID)%', client_secret: '%env(CALENDAR_MICROSOFT_CLIENT_SECRET)%', tenant: common }
```

The readers are registered even with `calendar.enabled: false`; the suggestions sync still reads nothing then. If you
change `microsoft_scope` to `Calendars.Read`, existing grants lack the scope: bump your consent version so users
reconnect. Google's `calendar.calendarlist.readonly` (calendar list) is new for grants made with only
`calendar.events.readonly`.

### Other additions

- `client_api.links_working_time_route` (null): your working-time page route for `GET me` `links.workingTime`
  (spec 198; sent only while `features.workday` is true and only as an https URL).
- `retention.former_person_listener` (true): set false to keep `FormerPersonSnapshotListener` off your entity manager
  when you delete users through your own flow (then call `FormerPersonTimeEntries::release()` or `anonymiseUser()`
  before the delete).
- Worker mode with `FRANKENPHP_RESET_KERNEL=false`: nothing to do; the bundle clears its only per-flush state itself at
  each request, message and command boundary.

## To 1.18.0

From **1.17.1**:

```bash
composer update nowo-tech/time-track-bundle
```

Target **1.18.0**, from **1.17.x**:

```bash
composer update nowo-tech/time-track-bundle
```

No action needed, no migration. The services now pass the user through `UserReferenceResolverInterface` before
storing it; with a security user that is the `user_class` entity nothing changes.

- A firewall user that is not the entity (wrapper / DTO) now works when its `getId()` returns the entity id; alias
  `Nowo\TimeTrackBundle\Integration\UserReferenceResolverInterface` to your own service for another mapping.
- Code that builds the services by hand keeps working (they default to `PassThroughUserReferenceResolver`); pass
  `userReferences:` to get the Doctrine behaviour.
- Listeners can switch from `getUser()` / `getEntry()` to `getUserId()`, `getEntryId()` and `getEntryView()`.
- With a separate security wrapper, keep the `user_class` entity implementing `UserInterface` (the former-person
  listener depends on it) and make the wrapper's `getId()` return the entity id in the same string form.

## To 1.17.1

From **1.17.0**:

```bash
composer update nowo-tech/time-track-bundle
```

No schema change, no contract change, nothing to do for most hosts.

- **`verify` on a chain with two starts** (audit HT-L2) now also checks that chain's links. If a line is missing behind the alarm, the message gains `, and K line(s) are not linked to them`. A host that matches the exact text `the chain has 2 starts` keeps matching the start of it. The baseline list of two-start chains from the 1.16.2 notes stays valid.
- **Retention purge** (audit HT-L1): the ledger prefix now follows the chain's links. On a chain where a line was written on a clock behind, the purge stops where the chain reaches the first line effective on or after the cut-off, so it may keep a line the sort order would have taken; it no longer cuts a line out of the middle of the chain.

## To 1.17.0

From **1.16.2**:

```bash
composer update nowo-tech/time-track-bundle
```

**No schema change, nothing to do unless you move tasks between boards.** Target **1.17.0**. Audit status: Audit ID (2026-11-08): Pass (good) / Info at `41e0ef2`.

**New: the time of a moved task follows it.** When a task (with its subtasks) moves to another board, possibly in another tenant, call `TaskTimeMover::moveTasks($taskIds, $targetBoardId, $targetScopeKey)` after the move is committed; USAGE has the listener for TaskBoard's `TASK_BOARD_CHANGED`. Until you call it, entries keep the old `board_id_snapshot` and `scope_key`, which is what every earlier version did.

- **Resolve the target when you handle the message, and pass `fromBoardId`.** The mover trusts its target and the last call wins: a message replayed after a later move would otherwise put the time back on a board the task left. Read the task's current board and its scope at handling time, and pass the event's `sourceBoardId` as `fromBoardId`, so entries on any other board stay (`on_another_board`).
- **Pass the scope your scope provider gives for the target board's tasks.** The bundle does not ask the provider (it needs a user per entry), so the host vouches for it. A wrong scope shows the entries' hours, task titles, descriptions and notes in the other tenant's reports, exports and bills. A null scope takes the entries out of every timesheet: pass it only on hosts without tenant scopes.
- **Read `TaskTimeMoveResult::$kept`.** With timesheets on, on a move into another scope, an entry in a locked, approved or submitted period, in its current scope or in the target one, stays with the old board and scope, and so do anonymised entries. Within one scope such an entry follows in the board only. Those hours keep counting in the source tenant. Nothing is lost and nothing is thrown: tell the manager, and to move them reopen (or withdraw or reject) the period through the audited flow and call again with the same arguments.
- **Safe to retry.** A listener that failed leaves entries with the old snapshot; the same call later finishes it and counts what was already moved in `alreadyAtTarget`.
- **Loaded entries and timers of those tasks are detached:** read them again after the call; a change you had pending on one is not written.
- **Custom stores:** the service needs `TaskTimeMoveRepositoryInterface` on the entry store and `TaskTimerMoveRepositoryInterface` on the timer store, and throws `LogicException` without them. Nothing else changes.
- Store `TASK_TIME_MOVED` (`TaskTimeMovedEvent`) if you keep an audit trail: the bundle has no audit table. It fires once per committed batch, also for entries already at the target, so expect repeats: `alreadyAtTarget`, `kept` and `secondsKept` repeat in every call's event, so a host that sums them across calls counts twice. Two moves of one task handled at the same time can leave the time on the wrong board: re-dispatch every move after a delay, or handle moves of one task one at a time. A listener that throws makes the call throw `TaskTimeMoveEventException` after the move finished; call again to get the event again.
- **A timer stopping during a move** can leave its entry on the old board: re-send the same message a few seconds later, or check new entries against the task's board (USAGE).

## To 1.16.2

From **1.16.1**:

```bash
composer update nowo-tech/time-track-bundle
```

Target **1.16.2**, from **1.16.1**. **No schema change, no contract change, nothing to do for most hosts.** Audit status: Appendix HT: Pass (good) / Low at `ad1de5c`.

- **A ledger chain stuck on `conflict` goes on by itself after the upgrade**, with no repair: the head is now the line no other line follows. `verify` keeps saying intact.
- **Two concurrent first lines of a new chain:** the second is now refused with `conflict`, like any concurrent append; retry it. A chain that already has two starts keeps them: `verify` keeps reporting "the chain has 2 starts", which is a false alarm if those two lines are both real. It cannot be repaired by hand, because the hashes cover the rows; a retention purge removing them clears it. New lines go on from the newest and both lines count in the balance. **While the alarm stands, `verify` does not check that chain's links**, only each line's hash. Since the bundle no longer writes NULL there, list these chains right after upgrading and keep the list: a chain that joins it later was edited by hand.

  ```sql
  SELECT owner_ref, scope_key, COUNT(*) FROM time_track_balance_ledger WHERE prev_hash IS NULL GROUP BY owner_ref, scope_key HAVING COUNT(*) > 1;
  ```
- **`prev_hash` of a new chain's first line now holds 64 zeros in the database,** not NULL. `getPrevHash()` and the hash treat it as NULL, so exports and `verify` do not change. SQL of your own reading that column should treat both as a first line. **Rolling back to 1.16.1 or older makes `verify` report those first lines as broken**, because older code hashes the column as stored; the balance is not affected, and this repairs it (lift any append-only trigger for it first):

  ```sql
  UPDATE time_track_balance_ledger SET prev_hash = NULL WHERE prev_hash = '0000000000000000000000000000000000000000000000000000000000000000';
  ```

  On PostgreSQL, run `ANALYZE time_track_balance_ledger` after a bulk load of ledger lines: with no statistics, finding a chain's head is very slow on long chains.

## To 1.16.1

From **1.16.0**:

```bash
composer update nowo-tech/time-track-bundle
```

Target **1.16.1**, from **1.16.0**. **No schema change, no contract change, nothing to do.** Audit status: Appendix HQ: Pass (good) / Low at `1fe4dd1`.

- A move no longer makes a sync wait on MySQL unless it touches the same rows.
- With `retention.anonymise_on_user_delete`, on SQLite without foreign keys, rows that were not loaded are now anonymised too.
- A move no longer overwrites a change you had pending on a loaded `Timesheet`.

## To 1.16.0

From **1.15.0**:

```bash
composer update nowo-tech/time-track-bundle
```

Target **1.16.0**, from **1.15.0**. **No schema change.** Audit status: Appendix HM (2026-11-07): Pass (good) / Low at `6848136`; residuals in `docs/SECURITY.md`.

**New: moving imported time between owners.** `ImportedTimeEntryWriter::moveToPerson()`, `moveToUser()` and `moveBetweenUsers()` move a source's imported entries in one transaction, keeping seconds, times, task and external ids. They never delete time. Stop using remove-then-upsert, or direct SQL, for this.

**What `keptLocked` means when your host shows no period locks.** A row in a locked or submitted timesheet period, of its current owner or of the new user, does not move and counts in `ImportedTimeEntryMoveResult::$keptLocked`. With timesheets off nothing is ever locked and it is always 0. Once timesheets are on it can be above 0 even if your UI shows no locks, for example through `timesheets.auto_lock_after_days`: those hours stay with their current owner, in reports too, and the move still succeeds for the other rows. Nothing is lost and no exception is thrown. Read the count, or `TimeEntriesMovedEvent::getKeptCount()`, and tell the manager whose hours stayed: they move only once the period is open again (reopened if locked or approved, withdrawn or rejected if submitted) and the move is run again.

**`$refusedScope`** counts rows whose `scope_key` is not exactly the target's, a null one included: they stay too, and the scope is never rewritten. Back-fill `scope_key` on existing rows before relying on a move: run the back-fill command, which needs a scope provider and skips ownerless rows. A sync does not fill the scope of a row that has not changed.

**Scope provider: `scopesFor()` must be truthful.** With `timesheets.scope_provider`, a move or a claim to a user also needs the row's scope among `scopesFor($user)`, and it is refused otherwise: a provider that answers `scopeKeyForTask()` from the task alone says where the task is, not whether this user belongs there. **With no scope provider this check does not run:** the row's scope must equal the scope you pass, so the host vouches for it, and a null scope with no scope passed moves across anything, because such a row has no tenant. Authorise the target user yourself.

**Mismatch as a per-row outcome (opt-in).** `upsertMany($rows, skipMismatches: true)` gives a row naming another owner the new outcome `ImportedTimeEntryOutcome::Mismatch`, writes nothing for it and writes the others; `ImportedTimeEntryBatchResult::$mismatched` counts them. The default stays a refusal of the whole batch (`ImportedEntryOwnerMismatchException`, with `$externalId`), and a `match` on the outcome only meets the new case if you pass the flag. Use it when moves can leave rows behind: a kept row mismatches the next sync.

**Custom stores:** the moves need `ImportedTimeEntryOwnerRepositoryInterface`; without it they throw `LogicException`, and nothing else changes. Store `TIME_ENTRIES_MOVED`: the bundle has no audit table.

**`FormerPersonSnapshotListener`:** its `postFlush` tag now has priority 1000, so a host `postFlush` listener that flushes cannot cancel the settling unless it has a higher priority. It settles only if the user row is really gone, so a `remove($user)` inside another listener's `onFlush`, with nothing else to flush, changes nothing on that user's rows. Calling `remove()` inside a flush stays unsupported.

## To 1.15.0

From **1.14.0**:

```bash
composer update nowo-tech/time-track-bundle
```

Target **1.15.0**, from **1.14.0**.

Audit status: Appendix HA (2026-11-06): Pass (conditional) / Medium at `38f5230`. The only open Medium was accepted by the owner on 2026-11-06; residuals in `docs/SECURITY.md`.

**Read this first: a plain user delete no longer erases.** Deleting a user used to delete their tracked time. Now the hours stay (owner rule 2026-11-06: tracked time is not lost, including when a user is deleted), and so, by default, do their name, descriptions and notes, **with no expiry**. If "delete the user" is your erasure flow, you must act: set `retention.anonymise_on_user_delete: true`, or call `FormerPersonTimeEntries::anonymiseUser($user)`, and set a retention term with `anonymiseOwnerlessEndedBefore($cutOff)`. See [host duties](USAGE.md#what-the-host-must-do).

**Schema migration required (every host).** `time_track_entries.user_id` becomes nullable and `ON DELETE SET NULL`, the table gets `person_name_snapshot`, `person_ref` (69 long: `user:` and an `owner_ref` of 64) and `anonymised_at`, and `time_track_timesheets` gets an index on `(owner_ref, period_end)`. Run `doctrine:migrations:diff`, or apply the reference SQL of your platform. Replace `users` and `INT` with your user table and primary-key type, and the foreign-key name if yours differs (Doctrine's default, also Beacon's, is `FK_7CB77957A76ED395`; the demo's own was `FK_TIME_TRACK_ENTRIES_USER`). No row is rewritten. The files are in `docs/sql/1.15.0/`, and the demo ships the MySQL one as `demo/symfony8/migrations/Version20261108000000.php`. On a large MySQL table the `ALTER` rebuilds the table: plan the window.

**Rollback.** While an entry has no user, every down script refuses and changes nothing: a rollback never deletes tracked time. Decide about those entries first. This holds with the native clients' default options, as `.scripts/check-rollback-clients.sh` proves in CI on `sqlite3`, `mysql`, `mariadb` and `psql`. Run the SQLite scripts with the `sqlite3` CLI: they rely on `.bail on`. Any other runner must skip the `.` and `\` lines, stop at the first error and roll back: a SQLite runner that carries on after an error loses the rows. `mysql --force` on a refused rollback exits 0 and drops the timesheets index, and a killed MySQL `up` after its first statement leaves no foreign key and needs a hand fix. Run them outside a transaction: on SQLite a surrounding transaction makes `PRAGMA foreign_keys` a no-op.

<details><summary>MySQL 8 / MariaDB 10.6+ (docs/sql/1.15.0/mysql-up.sql)</summary>

```sql
-- TimeTrackBundle 1.15.0, MySQL 8 / MariaDB 10.6+ (default table prefix; replace `users` and INT with your user table and PK type, and FK_7CB77957A76ED395 with the name of your foreign key if it differs). Entries with no user; no row is rewritten. The first statement is one atomic change.
ALTER TABLE time_track_entries DROP FOREIGN KEY FK_7CB77957A76ED395, ADD person_name_snapshot VARCHAR(255) DEFAULT NULL, ADD person_ref VARCHAR(69) DEFAULT NULL, ADD anonymised_at DATETIME DEFAULT NULL, CHANGE user_id user_id INT DEFAULT NULL, ADD INDEX idx_time_track_entries_person_ref (person_ref);
ALTER TABLE time_track_entries ADD CONSTRAINT FK_7CB77957A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL;
CREATE INDEX idx_time_track_timesheets_owner_ref ON time_track_timesheets (owner_ref, period_end);
```

Rollback:

```sql
-- Rollback to 1.14 (MySQL 8 / MariaDB 10.6+). While an entry has no user the first ALTER is refused and changes nothing: a rollback never deletes tracked time. Decide about those entries first. Strict mode is forced because without it NOT NULL would turn their user into 0.
SET @time_track_sql_mode = @@SESSION.sql_mode;
SET SESSION sql_mode = CONCAT_WS(',', NULLIF(@@SESSION.sql_mode, ''), 'STRICT_ALL_TABLES');
ALTER TABLE time_track_entries DROP FOREIGN KEY FK_7CB77957A76ED395, DROP INDEX idx_time_track_entries_person_ref, DROP person_name_snapshot, DROP person_ref, DROP anonymised_at, CHANGE user_id user_id INT NOT NULL;
ALTER TABLE time_track_entries ADD CONSTRAINT FK_7CB77957A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE;
DROP INDEX idx_time_track_timesheets_owner_ref ON time_track_timesheets;
SET SESSION sql_mode = @time_track_sql_mode;
```

</details>

<details><summary>PostgreSQL 13+ (docs/sql/1.15.0/postgresql-up.sql)</summary>

```sql
-- TimeTrackBundle 1.15.0, PostgreSQL 13+ (default table prefix; replace `users` with your user table, and fk_7cb77957a76ed395 with the name of your foreign key if it differs). Entries with no user; no row is rewritten. One transaction. A runner other than psql skips the backslash line and stops at the first error.
\set ON_ERROR_STOP on
BEGIN;
ALTER TABLE time_track_entries DROP CONSTRAINT FK_7CB77957A76ED395;
ALTER TABLE time_track_entries ADD person_name_snapshot VARCHAR(255) DEFAULT NULL;
ALTER TABLE time_track_entries ADD person_ref VARCHAR(69) DEFAULT NULL;
ALTER TABLE time_track_entries ADD anonymised_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL;
ALTER TABLE time_track_entries ALTER user_id DROP NOT NULL;
CREATE INDEX idx_time_track_entries_person_ref ON time_track_entries (person_ref);
ALTER TABLE time_track_entries ADD CONSTRAINT FK_7CB77957A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE;
CREATE INDEX idx_time_track_timesheets_owner_ref ON time_track_timesheets (owner_ref, period_end);
COMMIT;
```

Rollback:

```sql
-- Rollback to 1.14 (PostgreSQL 13+). One transaction: while an entry has no user SET NOT NULL fails and nothing changes, a rollback never deletes tracked time. Decide about those entries first. A runner other than psql skips the backslash line and stops at the first error.
\set ON_ERROR_STOP on
BEGIN;
ALTER TABLE time_track_entries ALTER user_id SET NOT NULL;
ALTER TABLE time_track_entries DROP CONSTRAINT FK_7CB77957A76ED395;
DROP INDEX idx_time_track_entries_person_ref;
ALTER TABLE time_track_entries DROP person_name_snapshot;
ALTER TABLE time_track_entries DROP person_ref;
ALTER TABLE time_track_entries DROP anonymised_at;
ALTER TABLE time_track_entries ADD CONSTRAINT FK_7CB77957A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE;
DROP INDEX idx_time_track_timesheets_owner_ref;
COMMIT;
```

</details>

<details><summary>SQLite 3.35+ (docs/sql/1.15.0/sqlite-up.sql)</summary>

```sql
-- TimeTrackBundle 1.15.0, SQLite 3.35+ (default table prefix; replace `users` with your user table). SQLite cannot change a column or a foreign key: the table is rebuilt, with foreign keys off so the rebuild touches no other table. Run it with the sqlite3 CLI, outside a transaction; any other runner skips the dot line and stops at the first error.
.bail on
PRAGMA foreign_keys = OFF;
BEGIN;
CREATE TABLE time_track_entries_new (id VARCHAR(36) NOT NULL, created_at DATETIME NOT NULL, external_source VARCHAR(64) DEFAULT NULL, external_id VARCHAR(128) DEFAULT NULL, billable BOOLEAN DEFAULT NULL, description CLOB DEFAULT NULL, scope_key VARCHAR(64) DEFAULT NULL, person_name_snapshot VARCHAR(255) DEFAULT NULL, person_ref VARCHAR(69) DEFAULT NULL, anonymised_at DATETIME DEFAULT NULL, task_id VARCHAR(36) NOT NULL, task_title_snapshot VARCHAR(255) NOT NULL, board_id_snapshot VARCHAR(36) DEFAULT NULL, started_at DATETIME NOT NULL, ended_at DATETIME NOT NULL, duration_seconds INTEGER NOT NULL, source VARCHAR(16) NOT NULL, metadata CLOB DEFAULT NULL, user_id INTEGER DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_7CB77957A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE);
INSERT INTO time_track_entries_new (id, created_at, external_source, external_id, billable, description, scope_key, task_id, task_title_snapshot, board_id_snapshot, started_at, ended_at, duration_seconds, source, metadata, user_id) SELECT id, created_at, external_source, external_id, billable, description, scope_key, task_id, task_title_snapshot, board_id_snapshot, started_at, ended_at, duration_seconds, source, metadata, user_id FROM time_track_entries;
DROP TABLE time_track_entries;
ALTER TABLE time_track_entries_new RENAME TO time_track_entries;
CREATE INDEX time_track_entries_user_idx ON time_track_entries (user_id);
CREATE INDEX time_track_entries_task_idx ON time_track_entries (task_id);
CREATE INDEX time_track_entries_started_idx ON time_track_entries (started_at);
CREATE INDEX time_track_entries_ended_idx ON time_track_entries (ended_at, id);
CREATE INDEX idx_time_track_entries_source ON time_track_entries (source);
CREATE UNIQUE INDEX uniq_time_track_entries_external ON time_track_entries (external_source, external_id);
CREATE INDEX idx_time_track_entries_user_scope_started ON time_track_entries (user_id, scope_key, started_at);
CREATE INDEX idx_time_track_entries_person_ref ON time_track_entries (person_ref);
CREATE INDEX idx_time_track_timesheets_owner_ref ON time_track_timesheets (owner_ref, period_end);
COMMIT;
PRAGMA foreign_keys = ON;
```

Rollback:

```sql
-- Rollback to 1.14 (SQLite 3.35+). While an entry has no user the INSERT fails and the transaction ends with nothing changed: a rollback never deletes tracked time. Decide about those entries first. Run it with the sqlite3 CLI, outside a transaction; any other runner skips the dot line and MUST stop at the first error and roll back.
.bail on
PRAGMA foreign_keys = OFF;
BEGIN;
CREATE TABLE time_track_entries_old (id VARCHAR(36) NOT NULL, created_at DATETIME NOT NULL, external_source VARCHAR(64) DEFAULT NULL, external_id VARCHAR(128) DEFAULT NULL, billable BOOLEAN DEFAULT NULL, description CLOB DEFAULT NULL, scope_key VARCHAR(64) DEFAULT NULL, task_id VARCHAR(36) NOT NULL, task_title_snapshot VARCHAR(255) NOT NULL, board_id_snapshot VARCHAR(36) DEFAULT NULL, started_at DATETIME NOT NULL, ended_at DATETIME NOT NULL, duration_seconds INTEGER NOT NULL, source VARCHAR(16) NOT NULL, metadata CLOB DEFAULT NULL, user_id INTEGER NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_7CB77957A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE);
INSERT INTO time_track_entries_old (id, created_at, external_source, external_id, billable, description, scope_key, task_id, task_title_snapshot, board_id_snapshot, started_at, ended_at, duration_seconds, source, metadata, user_id) SELECT id, created_at, external_source, external_id, billable, description, scope_key, task_id, task_title_snapshot, board_id_snapshot, started_at, ended_at, duration_seconds, source, metadata, user_id FROM time_track_entries;
DROP TABLE time_track_entries;
ALTER TABLE time_track_entries_old RENAME TO time_track_entries;
CREATE INDEX time_track_entries_user_idx ON time_track_entries (user_id);
CREATE INDEX time_track_entries_task_idx ON time_track_entries (task_id);
CREATE INDEX time_track_entries_started_idx ON time_track_entries (started_at);
CREATE INDEX time_track_entries_ended_idx ON time_track_entries (ended_at, id);
CREATE INDEX idx_time_track_entries_source ON time_track_entries (source);
CREATE UNIQUE INDEX uniq_time_track_entries_external ON time_track_entries (external_source, external_id);
CREATE INDEX idx_time_track_entries_user_scope_started ON time_track_entries (user_id, scope_key, started_at);
DROP INDEX idx_time_track_timesheets_owner_ref;
COMMIT;
PRAGMA foreign_keys = ON;
```

</details>

**Behaviour changes for hosts:**

- **Deleting a user keeps the entries.** A host that relied on the cascade must decide, as above.
- **`TimeEntry::getUser()` may return null.** Check your code that reads `getUser()`, `IDENTITY(e.user)`, `user_id`, or joins the user. An inner join leaves ownerless entries out of a report (their hours disappear from totals by person), a cast like `(int) $row['userId']` adds them to user 0, and SQL `user_id <> :x` does not match `NULL`. Listeners of `TIME_ENTRY_IMPORTED` get ownerless entries too.
- **A running timer is saved when the user is deleted.** `$entityManager->remove($user)` stops it and saves it as a marked entry ending at the deletion time, with no refusal from a locked period. It flushes at `remove()`, your pending work included, because `TIMER_STOP` needs a live user and its listeners flush: wrap the delete in a transaction to make it one unit, and do not call it inside a flush.
- **A bulk DQL or raw SQL delete, or a database cascade from a parent table, must be preceded by `FormerPersonTimeEntries::release($user, $name)`**, which stops the timer and writes the name and reference. Without it the running timer is lost (`time_track_active_timers` stays `ON DELETE CASCADE`), and the entries have no name and no reference, so a later erasure or export cannot find one person's: only `anonymiseUnidentified()` reaches them, all at once. Code that deletes `ActiveTimer` rows itself, such as an eraser, loses the time in progress as well: call `TimerService::stopForUserDeletion($user)` first.
- **`removeForUser()` is for re-association only** (remove, then upsert). Do not call it for a deleted or erased account any more: it deletes time. It now also respects the period lock: it used to skip it for erasure.
- **A plain user row no longer takes an ownerless entry.** See the [table](USAGE.md#entries-with-no-user-115). Send `new ImportedTimeEntryClaim($user, $personRef, $scopeKey)` for a mapped person. The scope must equal the entry's: a claim with a scope is refused on an entry with none. `ImportedEntryOwnerMismatchException::$externalId` says which row stopped a batch. With a scope provider, a row that has no scope cannot be claimed, and a person row cannot give it one: a manager removes it and the next sync re-creates it, provided the remote still holds the entry. Check this before upgrading if you import former people without a scope. A sink must set the same scope on person rows and claims from the start. Host code that updates `user_id` directly must skip rows where it is null.
- **Anonymising also blanks `task_title_snapshot` and hashes `external_id`**, and it keeps only the `closed_by` mark in `metadata`.
- **Manager removal of ownerless imported entries stays allowed**, respecting the period lock. `TimeEntriesRemovedEvent` has `getPersonRefs()` and `getKeptCount()`, and it now also fires for a removal the lock refused. The bundle keeps no audit table: listen to that event to record removals, and pass `$actorId` to the bulk calls.
- **Nothing shows by default.** Ownerless entries are listed only for a viewer a `TIME_ENTRY_LIST_QUERY` listener allows with `includeOwnerless($scopeKeys)`, and never on the client API. A full override of `reports.html.twig` or of `nowo_ui_list_row` must add the name (`row.ownerless`, `row.personName`, `reports.former_person`).
- **Custom stores:** a timesheet store should implement `FormerOwnerTimesheetRepositoryInterface`, or a deleted user's entries count as locked. A `removeImportedWhere()` `$keep` callback gets `personRef`, and `userId` may be null. Implement `FormerPersonTimeEntryRepositoryInterface` to use `FormerPersonTimeEntries`; without it deletes work as usual and the entries stay with no name.
- **SQLite:** with no `PRAGMA foreign_keys = ON` (DBAL's default, add `Doctrine\DBAL\Driver\AbstractSQLiteDriver\Middleware\EnableForeignKeys`), a deleted user's `user_id` dangles: the time stays, but `getUser()` then fails to load.

**Optional:** implement `ClientUserPrivacyProviderInterface` on your `client_api.profile_provider` to send `privacy` on `GET me`. Without it the key stays absent.

## To 1.14.0

From **1.13.0** (contract `timetrack-next` v1.1: calendar suggestions §6, `POST entries` and `resolve-keys` §6.6 / §10.3,
meetings API §22):

```bash
composer update nowo-tech/time-track-bundle
```

**Schema migration required (every host).** 1.14 adds three tables that are always mapped, whatever the configuration
(empty until calendar suggestions are enabled): `time_track_calendar_connections`, `time_track_calendar_suggestions` and
`time_track_calendar_rules`. Run `doctrine:migrations:diff` (the diff is exactly the statements below for the default
prefix) or apply the reference SQL of your platform. Replace `users` and `INT` with your user table and its primary-key
type (Beacon: `INT`). On MySQL / MariaDB the new tables must use the collation of your `time_track_entries` table (the
`entry_id` foreign key needs the same one; the reference SQL uses `utf8mb4_unicode_ci`, like Beacon and the demo; a
`doctrine:migrations:diff` uses your `doctrine.dbal.default_table_options`). No data is rewritten; the files are also in
`docs/sql/1.14.0/`, and the demo ships the MySQL one as
`demo/symfony8/migrations/Version20261107000000.php`. The meetings API adds no table.

<details><summary>MySQL 8 / MariaDB (docs/sql/1.14.0/mysql-up.sql)</summary>

```sql
-- TimeTrackBundle 1.14.0, MySQL 8 / MariaDB 10.6+ (default table prefix; replace `users` and INT with your user table and PK type, and utf8mb4_unicode_ci with the collation of your time_track_entries table: the entry_id foreign key needs the same one). Calendar suggestions (always mapped); the meetings API adds no table.
CREATE TABLE time_track_calendar_connections (id VARCHAR(36) NOT NULL, status VARCHAR(16) NOT NULL, consented_at DATETIME NOT NULL, last_sync_at DATETIME DEFAULT NULL, last_error_code VARCHAR(32) DEFAULT NULL, last_attempt_at DATETIME DEFAULT NULL, next_attempt_at DATETIME DEFAULT NULL, failure_count INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, scope_key VARCHAR(64) NOT NULL, provider VARCHAR(16) NOT NULL, mode VARCHAR(8) NOT NULL, credential_ref VARCHAR(128) DEFAULT NULL, consent_version VARCHAR(16) NOT NULL, user_id INT NOT NULL, INDEX idx_time_track_calendar_connections_due (mode, status, next_attempt_at), UNIQUE INDEX uniq_time_track_calendar_connections (user_id, scope_key, provider), INDEX IDX_12CE44F6A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
CREATE TABLE time_track_calendar_suggestions (id VARCHAR(36) NOT NULL, status VARCHAR(12) NOT NULL, created_at DATETIME NOT NULL, starts_at DATETIME NOT NULL, ends_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, event_ref CHAR(64) NOT NULL, series_ref CHAR(64) DEFAULT NULL, group_key VARCHAR(48) DEFAULT NULL, task_id VARCHAR(36) NOT NULL, match_kind VARCHAR(8) NOT NULL, entry_id VARCHAR(36) DEFAULT NULL, connection_id VARCHAR(36) NOT NULL, user_id INT NOT NULL, INDEX idx_time_track_calendar_suggestions_user (user_id, status, starts_at), UNIQUE INDEX uniq_time_track_calendar_suggestions_ref (connection_id, event_ref), INDEX IDX_3C8E3EF7BA364942 (entry_id), INDEX IDX_3C8E3EF7DD03F01 (connection_id), INDEX IDX_3C8E3EF7A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
CREATE TABLE time_track_calendar_rules (id VARCHAR(36) NOT NULL, created_at DATETIME NOT NULL, scope_key VARCHAR(64) NOT NULL, pattern VARCHAR(120) NOT NULL, task_id VARCHAR(36) NOT NULL, group_matches TINYINT DEFAULT 1 NOT NULL, user_id INT NOT NULL, INDEX idx_time_track_calendar_rules_user (user_id, scope_key), INDEX IDX_84F867CAA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
ALTER TABLE time_track_calendar_connections ADD CONSTRAINT FK_12CE44F6A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE;
ALTER TABLE time_track_calendar_suggestions ADD CONSTRAINT FK_3C8E3EF7BA364942 FOREIGN KEY (entry_id) REFERENCES time_track_entries (id) ON DELETE SET NULL;
ALTER TABLE time_track_calendar_suggestions ADD CONSTRAINT FK_3C8E3EF7DD03F01 FOREIGN KEY (connection_id) REFERENCES time_track_calendar_connections (id) ON DELETE CASCADE;
ALTER TABLE time_track_calendar_suggestions ADD CONSTRAINT FK_3C8E3EF7A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE;
ALTER TABLE time_track_calendar_rules ADD CONSTRAINT FK_84F867CAA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE;
```

Rollback:

```sql
-- Rollback to 1.13 (MySQL / MariaDB): drops the 1.14 calendar tables; 1.13 ignores them otherwise.
ALTER TABLE time_track_calendar_suggestions DROP FOREIGN KEY FK_3C8E3EF7BA364942;
ALTER TABLE time_track_calendar_suggestions DROP FOREIGN KEY FK_3C8E3EF7DD03F01;
ALTER TABLE time_track_calendar_suggestions DROP FOREIGN KEY FK_3C8E3EF7A76ED395;
ALTER TABLE time_track_calendar_rules DROP FOREIGN KEY FK_84F867CAA76ED395;
ALTER TABLE time_track_calendar_connections DROP FOREIGN KEY FK_12CE44F6A76ED395;
DROP TABLE time_track_calendar_suggestions;
DROP TABLE time_track_calendar_rules;
DROP TABLE time_track_calendar_connections;
```

</details>

<details><summary>PostgreSQL 13+ (docs/sql/1.14.0/postgresql-up.sql)</summary>

```sql
-- TimeTrackBundle 1.14.0, PostgreSQL 13+ (default table prefix; replace `users` and INT with your user table and PK type). Calendar suggestions (always mapped); the meetings API adds no table.
CREATE TABLE time_track_calendar_connections (id VARCHAR(36) NOT NULL, status VARCHAR(16) NOT NULL, consented_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_sync_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_error_code VARCHAR(32) DEFAULT NULL, last_attempt_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, next_attempt_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, failure_count INT DEFAULT 0 NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, scope_key VARCHAR(64) NOT NULL, provider VARCHAR(16) NOT NULL, mode VARCHAR(8) NOT NULL, credential_ref VARCHAR(128) DEFAULT NULL, consent_version VARCHAR(16) NOT NULL, user_id INT NOT NULL, PRIMARY KEY (id));
CREATE INDEX idx_time_track_calendar_connections_due ON time_track_calendar_connections (mode, status, next_attempt_at);
CREATE UNIQUE INDEX uniq_time_track_calendar_connections ON time_track_calendar_connections (user_id, scope_key, provider);
CREATE INDEX IDX_12CE44F6A76ED395 ON time_track_calendar_connections (user_id);
CREATE TABLE time_track_calendar_suggestions (id VARCHAR(36) NOT NULL, status VARCHAR(12) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, starts_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ends_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, event_ref CHAR(64) NOT NULL, series_ref CHAR(64) DEFAULT NULL, group_key VARCHAR(48) DEFAULT NULL, task_id VARCHAR(36) NOT NULL, match_kind VARCHAR(8) NOT NULL, entry_id VARCHAR(36) DEFAULT NULL, connection_id VARCHAR(36) NOT NULL, user_id INT NOT NULL, PRIMARY KEY (id));
CREATE INDEX idx_time_track_calendar_suggestions_user ON time_track_calendar_suggestions (user_id, status, starts_at);
CREATE UNIQUE INDEX uniq_time_track_calendar_suggestions_ref ON time_track_calendar_suggestions (connection_id, event_ref);
CREATE INDEX IDX_3C8E3EF7BA364942 ON time_track_calendar_suggestions (entry_id);
CREATE INDEX IDX_3C8E3EF7DD03F01 ON time_track_calendar_suggestions (connection_id);
CREATE INDEX IDX_3C8E3EF7A76ED395 ON time_track_calendar_suggestions (user_id);
CREATE TABLE time_track_calendar_rules (id VARCHAR(36) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, scope_key VARCHAR(64) NOT NULL, pattern VARCHAR(120) NOT NULL, task_id VARCHAR(36) NOT NULL, group_matches BOOLEAN DEFAULT true NOT NULL, user_id INT NOT NULL, PRIMARY KEY (id));
CREATE INDEX idx_time_track_calendar_rules_user ON time_track_calendar_rules (user_id, scope_key);
CREATE INDEX IDX_84F867CAA76ED395 ON time_track_calendar_rules (user_id);
ALTER TABLE time_track_calendar_connections ADD CONSTRAINT FK_12CE44F6A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE;
ALTER TABLE time_track_calendar_suggestions ADD CONSTRAINT FK_3C8E3EF7BA364942 FOREIGN KEY (entry_id) REFERENCES time_track_entries (id) ON DELETE SET NULL NOT DEFERRABLE;
ALTER TABLE time_track_calendar_suggestions ADD CONSTRAINT FK_3C8E3EF7DD03F01 FOREIGN KEY (connection_id) REFERENCES time_track_calendar_connections (id) ON DELETE CASCADE NOT DEFERRABLE;
ALTER TABLE time_track_calendar_suggestions ADD CONSTRAINT FK_3C8E3EF7A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE;
ALTER TABLE time_track_calendar_rules ADD CONSTRAINT FK_84F867CAA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE;
```

Rollback:

```sql
-- Rollback to 1.13 (PostgreSQL): drops the 1.14 calendar tables; 1.13 ignores them otherwise.
ALTER TABLE time_track_calendar_suggestions DROP CONSTRAINT FK_3C8E3EF7BA364942;
ALTER TABLE time_track_calendar_suggestions DROP CONSTRAINT FK_3C8E3EF7DD03F01;
ALTER TABLE time_track_calendar_suggestions DROP CONSTRAINT FK_3C8E3EF7A76ED395;
ALTER TABLE time_track_calendar_rules DROP CONSTRAINT FK_84F867CAA76ED395;
ALTER TABLE time_track_calendar_connections DROP CONSTRAINT FK_12CE44F6A76ED395;
DROP TABLE time_track_calendar_suggestions;
DROP TABLE time_track_calendar_rules;
DROP TABLE time_track_calendar_connections;
```

</details>

<details><summary>SQLite 3.35+ (docs/sql/1.14.0/sqlite-up.sql)</summary>

```sql
-- TimeTrackBundle 1.14.0, SQLite 3.35+ (default table prefix; replace `users` with your user table). Foreign keys are declared inline. Calendar suggestions (always mapped); the meetings API adds no table.
CREATE TABLE time_track_calendar_connections (id VARCHAR(36) NOT NULL, status VARCHAR(16) NOT NULL, consented_at DATETIME NOT NULL, last_sync_at DATETIME DEFAULT NULL, last_error_code VARCHAR(32) DEFAULT NULL, last_attempt_at DATETIME DEFAULT NULL, next_attempt_at DATETIME DEFAULT NULL, failure_count INTEGER DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, scope_key VARCHAR(64) NOT NULL, provider VARCHAR(16) NOT NULL, mode VARCHAR(8) NOT NULL, credential_ref VARCHAR(128) DEFAULT NULL, consent_version VARCHAR(16) NOT NULL, user_id INTEGER NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_12CE44F6A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE);
CREATE INDEX idx_time_track_calendar_connections_due ON time_track_calendar_connections (mode, status, next_attempt_at);
CREATE UNIQUE INDEX uniq_time_track_calendar_connections ON time_track_calendar_connections (user_id, scope_key, provider);
CREATE INDEX IDX_12CE44F6A76ED395 ON time_track_calendar_connections (user_id);
CREATE TABLE time_track_calendar_suggestions (id VARCHAR(36) NOT NULL, status VARCHAR(12) NOT NULL, created_at DATETIME NOT NULL, starts_at DATETIME NOT NULL, ends_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, event_ref CHAR(64) NOT NULL, series_ref CHAR(64) DEFAULT NULL, group_key VARCHAR(48) DEFAULT NULL, task_id VARCHAR(36) NOT NULL, match_kind VARCHAR(8) NOT NULL, entry_id VARCHAR(36) DEFAULT NULL, connection_id VARCHAR(36) NOT NULL, user_id INTEGER NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_3C8E3EF7BA364942 FOREIGN KEY (entry_id) REFERENCES time_track_entries (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3C8E3EF7DD03F01 FOREIGN KEY (connection_id) REFERENCES time_track_calendar_connections (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3C8E3EF7A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE);
CREATE INDEX idx_time_track_calendar_suggestions_user ON time_track_calendar_suggestions (user_id, status, starts_at);
CREATE UNIQUE INDEX uniq_time_track_calendar_suggestions_ref ON time_track_calendar_suggestions (connection_id, event_ref);
CREATE INDEX IDX_3C8E3EF7BA364942 ON time_track_calendar_suggestions (entry_id);
CREATE INDEX IDX_3C8E3EF7DD03F01 ON time_track_calendar_suggestions (connection_id);
CREATE INDEX IDX_3C8E3EF7A76ED395 ON time_track_calendar_suggestions (user_id);
CREATE TABLE time_track_calendar_rules (id VARCHAR(36) NOT NULL, created_at DATETIME NOT NULL, scope_key VARCHAR(64) NOT NULL, pattern VARCHAR(120) NOT NULL, task_id VARCHAR(36) NOT NULL, group_matches BOOLEAN DEFAULT 1 NOT NULL, user_id INTEGER NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_84F867CAA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE);
CREATE INDEX idx_time_track_calendar_rules_user ON time_track_calendar_rules (user_id, scope_key);
CREATE INDEX IDX_84F867CAA76ED395 ON time_track_calendar_rules (user_id);
```

Rollback:

```sql
-- Rollback to 1.13 (SQLite 3.35+): drops the 1.14 calendar tables; 1.13 ignores them otherwise.
DROP TABLE time_track_calendar_suggestions;
DROP TABLE time_track_calendar_rules;
DROP TABLE time_track_calendar_connections;
```

</details>

**Calendar suggestions are off by default** (`calendar.enabled: false`). To turn them on, implement and configure:

- `Calendar\TaskKeyResolverInterface` (`calendar.task_key_resolver`, required): which task keys a title carries and the
  tasks they mean in a tenant scope (Beacon: `#<first 8 hex of the task UUID>` and task URLs, Q-B8);
- `Calendar\CalendarCredentialProviderInterface` (`calendar.credential_provider`, required for `modes: [server]`): a
  short-lived access token per sync from your encrypted store, `revoke()` (delete the token, revoke the grant) and the
  *Connect* URL of your OAuth flow (Q-B9). The bundle never stores a token;
- record each user's consent with `Calendar\CalendarConnectionService::connect()` after your OAuth flow (and revoke it
  with `revoke()`, which calls your `revoke()` first);
- install `symfony/http-client` (now a `suggest`): the Google / Microsoft adapters use the framework `http_client`
  (without it a sync fails with `no_http_client`);
- schedule `nowo:time-track:calendar:sync` (e.g. every 15 minutes): it syncs due connections only inside their owner's
  working hours (`ClientUserProfileProviderInterface::getWorkingHours()`; unknown hours are never inside) and purges
  expired suggestions.

Your `TaskKeyResolverInterface::extractKeys()` receives titles of at most 1024 characters from anyone who can invite the
user: use linear-time matching (no backtracking regex). On account erasure call
`CalendarConnectionService::revokeAllFor($user)` before deleting the user (the cascade does not call your `revoke()`).
Rotating `kernel.secret` changes the event references: dismissed events are suggested once more.

OAuth scopes (least privilege): Google `https://www.googleapis.com/auth/calendar.events.readonly`, Microsoft
`Calendars.ReadBasic` + `offline_access`. Only the **primary** calendar is read, `calendar.lookback_days` (14) back, and
only events whose title carries a resolvable key or matches a user rule become suggestions; nothing else of an event is
stored (no title, description, attendees, location, link or provider id — only keyed HMAC references). Delete the user's
connections with their account (FK `ON DELETE CASCADE`) and call your own token revoke on account erasure.

**Personal data.** Add `Calendar\CalendarPersonalDataExporter::export($userId)` (connections without the credential
reference, rules, suggestions) to your personal-data export next to the timesheet exporter.

**New events**: `nowo_time_track.calendar.connection_changed` (`CalendarConnectionChangedEvent`),
`nowo_time_track.calendar.sync_finished` (`CalendarSyncFinishedEvent`) and
`nowo_time_track.calendar.suggestion_decided` (`CalendarSuggestionDecidedEvent`): ids, codes, counts and the consent
version only, for your audit rows (`time_track.calendar.*`). `nowo_time_track.time_entry.created`
(`TimeEntryCreatedEvent`) fires for entries created without a timer (`POST entries`, accepted suggestions): update the
TaskBoard bundle to the patch that listens to it so task totals include them.

**Manual entries are off by default** (`manual_entries.enabled: false`). With it on, `POST /api/time-track/entries`
creates `manual` / `calendar` entries (≤ 24 h, not in the future, at most `manual_entries.max_age_days` old, no overlap,
period lock). A host replacement of `TimeEntryRepositoryInterface` may implement
`OverlappingTimeEntryRepositoryInterface` for an exact overlap query; without it the overlap check reads the two days
before the new entry.

**Concurrency.** Entries without a timer and accepted suggestions are written under a per-user row lock
(`SELECT … FOR UPDATE` on your user row, inside a short transaction). A host replacement of `TimeEntryRepositoryInterface`
should implement `UserSerialisedTimeEntryRepositoryInterface` too (without it the overlap check can race). A user the
entity manager does not manage is locked by id through the mapped user class; when no row can be locked a warning is
logged. **Your callbacks that run inside that lock must stay quick and must not write to tables that reference the user
row from another connection:** `TimesheetScopeProviderInterface::scopeKeyForTask()`, `TaskAccessCheckerInterface` /
the task provider's `findForUser()` (before the lock), and listeners of `nowo_time_track.period_lock.refused` (on
PostgreSQL the lock also blocks concurrent inserts that reference the same user). Listeners of
`nowo_time_track.time_entry.created` and `nowo_time_track.calendar.suggestion_decided` run after the commit; their
exceptions are logged and do not undo anything. The `Idempotency-Key` answer of `POST entries` is stored after the
commit.

**Meetings API (spec 210) is off by default** (`meetings.enabled: false`) and adds no table. Implement
`Meeting\MeetingProviderInterface` over your meeting read model and set `meetings.provider` ([MEETINGS.md](MEETINGS.md));
optionally `meetings.links_calendars_route` for `GET me.links.calendars`. Clients need the new **`meetings:read`** scope
(explicit grant: existing desktops re-consent once in the device flow). `GET me` gains `features.meetings`, `meetings`
and `links`.

**Client API (additive).** New scopes `entries:write`, `suggestions:read`, `suggestions:write` are never granted by
default (as for the 1.13 scopes): request them in the device flow. New routes (404 while their feature is off):
`calendar/connections` (GET, and DELETE `/{id}`), `suggestions`, `suggestions/accept`, `suggestions/dismiss`,
`suggestions/resolve-keys` (keys of tasks the user cannot track are left out), `POST entries`, `me/meetings`. CORS
preflights now allow `DELETE` and the `Idempotency-Key` header. `GET me.features.calendar_suggestions` and
`features.manual_entries` become real. `timer/switch` and `timer/start` accept `metadata: {origin: "meeting"}` (*Track
the meeting*); `TimerService::switchTo()` has a new optional `$metadata` argument.

## To 1.13.0

From **1.12.0** (contract `timetrack-next` v1.0):

```bash
composer update nowo-tech/time-track-bundle
```

**Schema migration required (every host).** 1.13 adds one nullable column to `time_track_entries` and three tables
that are always mapped, whatever the configuration (empty until timesheets are enabled): `time_track_timesheets`,
`time_track_timesheet_events` (audit trail) and `time_track_balance_ledger`. Run `doctrine:migrations:diff` (the diff
is exactly the statements below for the default prefix) or apply the reference SQL of your platform. Replace `users`
and `INT` with your user table and its primary-key type (Beacon: `INT`). The names `IDX_…` / `FK_…` are the ones
Doctrine derives for the default `time_track_` prefix; with another prefix, let `doctrine:migrations:diff` name them.
No data is rewritten; the files are also in `docs/sql/1.13.0/`, and the demo ships the MySQL one as
`demo/symfony8/migrations/Version20261106000000.php`.

<details><summary>MySQL 8 / MariaDB (docs/sql/1.13.0/mysql-up.sql)</summary>

```sql
-- TimeTrackBundle 1.13.0, MySQL 8 / MariaDB 10.6+ (default table prefix; replace `users` and INT with your user table and PK type).
ALTER TABLE time_track_entries ADD scope_key VARCHAR(64) DEFAULT NULL;
CREATE INDEX idx_time_track_entries_user_scope_started ON time_track_entries (user_id, scope_key, started_at);
CREATE TABLE time_track_timesheets (id VARCHAR(36) NOT NULL, period_kind VARCHAR(8) NOT NULL, period_start DATE NOT NULL, period_end DATE NOT NULL, status VARCHAR(16) NOT NULL, locked_at DATETIME DEFAULT NULL, lock_reason VARCHAR(16) DEFAULT NULL, submitted_at DATETIME DEFAULT NULL, submitted_by_ref VARCHAR(64) DEFAULT NULL, decided_at DATETIME DEFAULT NULL, decided_by_ref VARCHAR(64) DEFAULT NULL, decision_comment VARCHAR(1000) DEFAULT NULL, totals JSON DEFAULT NULL, entries_hash CHAR(64) DEFAULT NULL, head_hash CHAR(64) DEFAULT NULL, version INT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, owner_ref VARCHAR(64) NOT NULL, scope_key VARCHAR(64) NOT NULL, timezone VARCHAR(64) NOT NULL, user_id INT DEFAULT NULL, INDEX idx_time_track_timesheets_queue (scope_key, status, period_start), INDEX idx_time_track_timesheets_lock (user_id, scope_key, locked_at, period_end), UNIQUE INDEX uniq_time_track_timesheets_period (user_id, scope_key, period_kind, period_start), INDEX IDX_B3267224A76ED395 (user_id), PRIMARY KEY (id));
CREATE TABLE time_track_timesheet_events (id VARCHAR(36) NOT NULL, occurred_at DATETIME NOT NULL, hash CHAR(64) NOT NULL, seq INT NOT NULL, action VARCHAR(24) NOT NULL, from_status VARCHAR(16) NOT NULL, to_status VARCHAR(16) NOT NULL, actor_ref VARCHAR(64) DEFAULT NULL, actor_role VARCHAR(16) NOT NULL, client VARCHAR(16) NOT NULL, prev_hash CHAR(64) DEFAULT NULL, comment VARCHAR(1000) DEFAULT NULL, entries_hash CHAR(64) DEFAULT NULL, totals JSON DEFAULT NULL, timesheet_id VARCHAR(36) NOT NULL, UNIQUE INDEX uniq_time_track_timesheet_events_seq (timesheet_id, seq), INDEX IDX_6F44086ABDD46BE (timesheet_id), PRIMARY KEY (id));
CREATE TABLE time_track_balance_ledger (id VARCHAR(36) NOT NULL, effective_date DATE NOT NULL, created_at DATETIME NOT NULL, hash CHAR(64) NOT NULL, purged_before DATE DEFAULT NULL, owner_ref VARCHAR(64) NOT NULL, scope_key VARCHAR(64) NOT NULL, kind VARCHAR(24) NOT NULL, seconds INT NOT NULL, prev_hash CHAR(64) DEFAULT NULL, reason VARCHAR(500) DEFAULT NULL, reverses_id VARCHAR(36) DEFAULT NULL, actor_ref VARCHAR(64) DEFAULT NULL, timesheet_id VARCHAR(36) DEFAULT NULL, user_id INT DEFAULT NULL, INDEX idx_time_track_balance_ledger_user (user_id, scope_key, effective_date), INDEX idx_time_track_balance_ledger_timesheet (timesheet_id), INDEX idx_time_track_balance_ledger_reverses (reverses_id), UNIQUE INDEX uniq_time_track_balance_ledger_chain (owner_ref, scope_key, prev_hash), INDEX IDX_24B61F4DA76ED395 (user_id), PRIMARY KEY (id));
ALTER TABLE time_track_timesheets ADD CONSTRAINT FK_B3267224A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL;
ALTER TABLE time_track_timesheet_events ADD CONSTRAINT FK_6F44086ABDD46BE FOREIGN KEY (timesheet_id) REFERENCES time_track_timesheets (id) ON DELETE CASCADE;
ALTER TABLE time_track_balance_ledger ADD CONSTRAINT FK_24B61F4DABDD46BE FOREIGN KEY (timesheet_id) REFERENCES time_track_timesheets (id) ON DELETE SET NULL;
ALTER TABLE time_track_balance_ledger ADD CONSTRAINT FK_24B61F4DA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL;
```

Rollback:

```sql
-- Rollback to 1.12 (MySQL / MariaDB): drops the 1.13 tables and the entry scope; 1.12 ignores them otherwise.
ALTER TABLE time_track_balance_ledger DROP FOREIGN KEY FK_24B61F4DABDD46BE;
ALTER TABLE time_track_balance_ledger DROP FOREIGN KEY FK_24B61F4DA76ED395;
ALTER TABLE time_track_timesheet_events DROP FOREIGN KEY FK_6F44086ABDD46BE;
ALTER TABLE time_track_timesheets DROP FOREIGN KEY FK_B3267224A76ED395;
DROP TABLE time_track_balance_ledger;
DROP TABLE time_track_timesheet_events;
DROP TABLE time_track_timesheets;
DROP INDEX idx_time_track_entries_user_scope_started ON time_track_entries;
ALTER TABLE time_track_entries DROP scope_key;
```

</details>

<details><summary>PostgreSQL 13+ (docs/sql/1.13.0/postgresql-up.sql)</summary>

```sql
-- TimeTrackBundle 1.13.0, PostgreSQL 13+ (default table prefix; replace `users` and INT with your user table and PK type).
ALTER TABLE time_track_entries ADD scope_key VARCHAR(64) DEFAULT NULL;
CREATE INDEX idx_time_track_entries_user_scope_started ON time_track_entries (user_id, scope_key, started_at);
CREATE TABLE time_track_timesheets (id VARCHAR(36) NOT NULL, period_kind VARCHAR(8) NOT NULL, period_start DATE NOT NULL, period_end DATE NOT NULL, status VARCHAR(16) NOT NULL, locked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, lock_reason VARCHAR(16) DEFAULT NULL, submitted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, submitted_by_ref VARCHAR(64) DEFAULT NULL, decided_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, decided_by_ref VARCHAR(64) DEFAULT NULL, decision_comment VARCHAR(1000) DEFAULT NULL, totals JSON DEFAULT NULL, entries_hash CHAR(64) DEFAULT NULL, head_hash CHAR(64) DEFAULT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_ref VARCHAR(64) NOT NULL, scope_key VARCHAR(64) NOT NULL, timezone VARCHAR(64) NOT NULL, user_id INT DEFAULT NULL, PRIMARY KEY (id));
CREATE INDEX idx_time_track_timesheets_queue ON time_track_timesheets (scope_key, status, period_start);
CREATE INDEX idx_time_track_timesheets_lock ON time_track_timesheets (user_id, scope_key, locked_at, period_end);
CREATE UNIQUE INDEX uniq_time_track_timesheets_period ON time_track_timesheets (user_id, scope_key, period_kind, period_start);
CREATE INDEX IDX_B3267224A76ED395 ON time_track_timesheets (user_id);
CREATE TABLE time_track_timesheet_events (id VARCHAR(36) NOT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, hash CHAR(64) NOT NULL, seq INT NOT NULL, action VARCHAR(24) NOT NULL, from_status VARCHAR(16) NOT NULL, to_status VARCHAR(16) NOT NULL, actor_ref VARCHAR(64) DEFAULT NULL, actor_role VARCHAR(16) NOT NULL, client VARCHAR(16) NOT NULL, prev_hash CHAR(64) DEFAULT NULL, comment VARCHAR(1000) DEFAULT NULL, entries_hash CHAR(64) DEFAULT NULL, totals JSON DEFAULT NULL, timesheet_id VARCHAR(36) NOT NULL, PRIMARY KEY (id));
CREATE UNIQUE INDEX uniq_time_track_timesheet_events_seq ON time_track_timesheet_events (timesheet_id, seq);
CREATE INDEX IDX_6F44086ABDD46BE ON time_track_timesheet_events (timesheet_id);
CREATE TABLE time_track_balance_ledger (id VARCHAR(36) NOT NULL, effective_date DATE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, hash CHAR(64) NOT NULL, purged_before DATE DEFAULT NULL, owner_ref VARCHAR(64) NOT NULL, scope_key VARCHAR(64) NOT NULL, kind VARCHAR(24) NOT NULL, seconds INT NOT NULL, prev_hash CHAR(64) DEFAULT NULL, reason VARCHAR(500) DEFAULT NULL, reverses_id VARCHAR(36) DEFAULT NULL, actor_ref VARCHAR(64) DEFAULT NULL, timesheet_id VARCHAR(36) DEFAULT NULL, user_id INT DEFAULT NULL, PRIMARY KEY (id));
CREATE INDEX idx_time_track_balance_ledger_user ON time_track_balance_ledger (user_id, scope_key, effective_date);
CREATE INDEX idx_time_track_balance_ledger_timesheet ON time_track_balance_ledger (timesheet_id);
CREATE INDEX idx_time_track_balance_ledger_reverses ON time_track_balance_ledger (reverses_id);
CREATE UNIQUE INDEX uniq_time_track_balance_ledger_chain ON time_track_balance_ledger (owner_ref, scope_key, prev_hash);
CREATE INDEX IDX_24B61F4DA76ED395 ON time_track_balance_ledger (user_id);
ALTER TABLE time_track_timesheets ADD CONSTRAINT FK_B3267224A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE;
ALTER TABLE time_track_timesheet_events ADD CONSTRAINT FK_6F44086ABDD46BE FOREIGN KEY (timesheet_id) REFERENCES time_track_timesheets (id) ON DELETE CASCADE NOT DEFERRABLE;
ALTER TABLE time_track_balance_ledger ADD CONSTRAINT FK_24B61F4DABDD46BE FOREIGN KEY (timesheet_id) REFERENCES time_track_timesheets (id) ON DELETE SET NULL NOT DEFERRABLE;
ALTER TABLE time_track_balance_ledger ADD CONSTRAINT FK_24B61F4DA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE;
```

Rollback:

```sql
-- Rollback to 1.12 (PostgreSQL): drops the 1.13 tables and the entry scope; 1.12 ignores them otherwise.
ALTER TABLE time_track_balance_ledger DROP CONSTRAINT FK_24B61F4DABDD46BE;
ALTER TABLE time_track_balance_ledger DROP CONSTRAINT FK_24B61F4DA76ED395;
ALTER TABLE time_track_timesheet_events DROP CONSTRAINT FK_6F44086ABDD46BE;
ALTER TABLE time_track_timesheets DROP CONSTRAINT FK_B3267224A76ED395;
DROP TABLE time_track_balance_ledger;
DROP TABLE time_track_timesheet_events;
DROP TABLE time_track_timesheets;
DROP INDEX idx_time_track_entries_user_scope_started;
ALTER TABLE time_track_entries DROP scope_key;
```

</details>

<details><summary>SQLite 3.35+ (docs/sql/1.13.0/sqlite-up.sql)</summary>

```sql
-- TimeTrackBundle 1.13.0, SQLite 3.35+ (default table prefix; replace `users` with your user table). Foreign keys are declared inline.
ALTER TABLE time_track_entries ADD COLUMN scope_key VARCHAR(64) DEFAULT NULL;
CREATE INDEX idx_time_track_entries_user_scope_started ON time_track_entries (user_id, scope_key, started_at);
CREATE TABLE time_track_timesheets (id VARCHAR(36) NOT NULL, period_kind VARCHAR(8) NOT NULL, period_start DATE NOT NULL, period_end DATE NOT NULL, status VARCHAR(16) NOT NULL, locked_at DATETIME DEFAULT NULL, lock_reason VARCHAR(16) DEFAULT NULL, submitted_at DATETIME DEFAULT NULL, submitted_by_ref VARCHAR(64) DEFAULT NULL, decided_at DATETIME DEFAULT NULL, decided_by_ref VARCHAR(64) DEFAULT NULL, decision_comment VARCHAR(1000) DEFAULT NULL, totals CLOB DEFAULT NULL, entries_hash CHAR(64) DEFAULT NULL, head_hash CHAR(64) DEFAULT NULL, version INTEGER DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, owner_ref VARCHAR(64) NOT NULL, scope_key VARCHAR(64) NOT NULL, timezone VARCHAR(64) NOT NULL, user_id INTEGER DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_B3267224A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE);
CREATE INDEX idx_time_track_timesheets_queue ON time_track_timesheets (scope_key, status, period_start);
CREATE INDEX idx_time_track_timesheets_lock ON time_track_timesheets (user_id, scope_key, locked_at, period_end);
CREATE UNIQUE INDEX uniq_time_track_timesheets_period ON time_track_timesheets (user_id, scope_key, period_kind, period_start);
CREATE INDEX IDX_B3267224A76ED395 ON time_track_timesheets (user_id);
CREATE TABLE time_track_timesheet_events (id VARCHAR(36) NOT NULL, occurred_at DATETIME NOT NULL, hash CHAR(64) NOT NULL, seq INTEGER NOT NULL, "action" VARCHAR(24) NOT NULL, from_status VARCHAR(16) NOT NULL, to_status VARCHAR(16) NOT NULL, actor_ref VARCHAR(64) DEFAULT NULL, actor_role VARCHAR(16) NOT NULL, client VARCHAR(16) NOT NULL, prev_hash CHAR(64) DEFAULT NULL, comment VARCHAR(1000) DEFAULT NULL, entries_hash CHAR(64) DEFAULT NULL, totals CLOB DEFAULT NULL, timesheet_id VARCHAR(36) NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_6F44086ABDD46BE FOREIGN KEY (timesheet_id) REFERENCES time_track_timesheets (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE);
CREATE UNIQUE INDEX uniq_time_track_timesheet_events_seq ON time_track_timesheet_events (timesheet_id, seq);
CREATE INDEX IDX_6F44086ABDD46BE ON time_track_timesheet_events (timesheet_id);
CREATE TABLE time_track_balance_ledger (id VARCHAR(36) NOT NULL, effective_date DATE NOT NULL, created_at DATETIME NOT NULL, hash CHAR(64) NOT NULL, purged_before DATE DEFAULT NULL, owner_ref VARCHAR(64) NOT NULL, scope_key VARCHAR(64) NOT NULL, kind VARCHAR(24) NOT NULL, seconds INTEGER NOT NULL, prev_hash CHAR(64) DEFAULT NULL, reason VARCHAR(500) DEFAULT NULL, reverses_id VARCHAR(36) DEFAULT NULL, actor_ref VARCHAR(64) DEFAULT NULL, timesheet_id VARCHAR(36) DEFAULT NULL, user_id INTEGER DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_24B61F4DABDD46BE FOREIGN KEY (timesheet_id) REFERENCES time_track_timesheets (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_24B61F4DA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE);
CREATE INDEX idx_time_track_balance_ledger_user ON time_track_balance_ledger (user_id, scope_key, effective_date);
CREATE INDEX idx_time_track_balance_ledger_timesheet ON time_track_balance_ledger (timesheet_id);
CREATE INDEX idx_time_track_balance_ledger_reverses ON time_track_balance_ledger (reverses_id);
CREATE UNIQUE INDEX uniq_time_track_balance_ledger_chain ON time_track_balance_ledger (owner_ref, scope_key, prev_hash);
CREATE INDEX IDX_24B61F4DA76ED395 ON time_track_balance_ledger (user_id);
```

Rollback:

```sql
-- Rollback to 1.12 (SQLite 3.35+): drops the 1.13 tables and the entry scope; 1.12 ignores them otherwise.
DROP TABLE time_track_balance_ledger;
DROP TABLE time_track_timesheet_events;
DROP TABLE time_track_timesheets;
DROP INDEX idx_time_track_entries_user_scope_started;
ALTER TABLE time_track_entries DROP COLUMN scope_key;
```

</details>

Rollback in general: drop the three tables and the column; 1.12 ignores them otherwise.

**Optional triggers (append-only audit).** The bundle never updates or deletes audit rows except through the retention
purge. Hosts that want the database to enforce it (spec `198` FR-017 pattern) MAY add triggers that refuse `UPDATE` on
`time_track_timesheet_events` / `time_track_balance_ledger` and `DELETE` for every database user but the one that runs
`nowo:time-track:timesheets:purge`. The bundle does not create them (privileges vary).

**`scope_key` back-fill (with timesheets on).** New entries get the tenant scope of their task at write time. Entries
recorded before 1.13 have none until you run (idempotent, resumable, keyset by id):

```bash
php bin/console nowo:time-track:entries:backfill-scope --dry-run
php bin/console nowo:time-track:entries:backfill-scope --batch=500
```

Entries the scope provider cannot place stay without a scope: they belong to no timesheet and are shown to their owner
only, as *unassigned*.

**Hosts that write entries themselves MUST call the period lock.** With timesheets on, every bundle write path refuses
time in a locked or submitted period (`PeriodLockedException` → 409 `period_locked` with `lockedThrough`,
`PeriodSubmittedException` → 409 `period_submitted`): backdated `timer/start`, `timer/switch`, idle decisions,
`ImportedTimeEntryWriter` (new outcome `locked`, counted in `ImportedTimeEntryBatchResult::$locked`), imported-entry
removals (single `remove()` throws; bulk `removeForTasks()` / `removeForBoard()` / `removeForSource()` keep the locked
entries; `removeForUser()` — account erasure — is not blocked), `TeamAccessGuard::canEditEntry()` /
`canDeleteImportedEntry()` (false whatever a `TimeEntryAccessCheckEvent` listener grants). Code that inserts or edits
`TimeEntry` rows directly must call `PeriodLockGuard::assertWritable($user, $scopeKey, $instant, WriteKind::…)` first.
Retention purges are never blocked. With timesheets off nothing changes and no query is made.

**Client API (additive).** New scopes `workday:read`, `workday:write`, `balance:read`, `timesheet:read`,
`timesheet:write` are never granted by default: a token without stored scopes (issued before 1.9) and a login whose
`ClientAuthResult` names none keep the four pre-1.13 scopes (before 1.13 "no scopes" meant "every scope"; with the new
scopes that would have widened old tokens). Grant them explicitly (device flow `scopes`, or your authenticator). `GET me`
gains `features.timesheets`, `balance`, `calendar_suggestions`, `manual_entries`, `task_effort` and `timesheet`;
`features.workday` is real. Entries gain `scopeKey` and `locked`, the CSV export gains `scope_key` and `locked` columns
(appended), tasks and timers gain `estimateSeconds`, `loggedSeconds`, `remainingSeconds`. Clients that compare whole
payloads must accept the new keys. Beacon: wire the new routes (`config/routes.yaml` imports the loader, nothing to do
there) and implement `Workday\WorkdayProviderInterface` for spec 198 (`client_api.workday_provider`).

**Task effort.** Let your task provider fill `TaskReference::$estimateSeconds` (TaskBoard `estimatedMinutes × 60`); the
bundle sums `loggedSeconds` from its entries unless the provider gives `TaskReference::$loggedSeconds`. It is the caller's
own time; the total of every user is shown only to users your listener on `nowo_time_track.task_effort.access_check`
grants (those who can manage the task; denied by default, see SECURITY). Tasks and timers carry `loggedSecondsScope`
(`own` / `all`) so clients can say whose time the figure counts; a provider that fills `loggedSeconds` is labelled by the
same rule. Ledger refusals (`LedgerRefusedException::errorCode()`) now have `timesheet.error.<code>` messages too.

**Timesheet web actions (CSRF).** `TimesheetFormHandler` verifies the CSRF token itself and refuses every post when no
CSRF token manager exists: keep `framework.csrf_protection` enabled (`symfony/security-csrf`). The decision forms now
carry hidden `scope` and `period` fields; owner submit / withdraw act on the actor's own period.

**Rate limits and personal data.** Put a per-actor `RateLimiter` on your ledger (and decision) routes: the bundle does not
limit web ledger writes in 1.13. Add `Timesheet\TimesheetPersonalDataExporter::export($ownerRef)` to your personal-data
export (timesheets, their audit rows and the ledger).

**Balance bounds.** `working_time.balance.start_date` is honoured for at most 3660 days before the end of a range; beyond
that no balance is computed (warning `balance_span_exceeded`) until a locked timesheet or a later start date shortens the
span. `GET timesheets?period=` answers 404 for keys after the next period or outside 2000–2099, and only the current and
previous periods get a stored row when viewed.

**UiKit 2.29.** `nowo-tech/ui-kit-bundle` is now `^2.29` (dialog anatomy of the components). Run
`composer update nowo-tech/ui-kit-bundle` if your lock is older.

**Components instead of overrides.** Build the *My timesheet*, *Approvals* and *Balance* pages from
`nowo_time_track_component()` and post their forms to your routes, handled by `Timesheet\TimesheetFormHandler`
([WORKING-TIME.md](WORKING-TIME.md#components-no-template-overrides)).

**`TimeEntrySource` has two new cases**, `manual` and `calendar` (live, editable; their write paths arrive in 1.14). A
host `match` over the enum without a default arm must add them.

**Bulk removals with timesheets on** need the entry store to implement `FilteredImportedTimeEntryRepositoryInterface`
(the bundle's Doctrine store does). A host replacement of `TimeEntryRepositoryInterface` that only implements
`ImportedTimeEntryRepositoryInterface` makes `removeForTasks()` / `removeForBoard()` / `removeForSource()` throw
`LogicException` (fail closed) while timesheets are on.

## To 1.12.0

From **1.11.0**:

```bash
composer update nowo-tech/time-track-bundle
```

No schema change, no configuration change.

**`GET me.workingHours` is normalised.** If your `ClientUserProfileProviderInterface::getWorkingHours()` returns the
documented 1.9 `days` shape, the clients now receive it as `slots` (before, they could not read it and treated it as
"outside working hours"). The `slots` shape is also accepted and is the recommended one. Check that your value is valid:
an unknown or non-canonical time zone (`europe/madrid`), a string day (`"1"`), a bad time (`" 9:00"`, `09:00:00`),
`from` = `to` (`00:00`–`00:00` does not mean "all day"), more than 100 ranges or a missing `slots` / `days` key is now
sent as `{timezone, slots: []}` (fail closed: never inside working hours, so the clients send no notifications limited
to working hours) and logged as a `warning` with the reason, instead of being passed through. `null` is sent only when
your provider returns `null` (unknown). Overnight ranges are split at
midnight and overlapping ones merged ([CLIENT-API](CLIENT-API.md#working-hours-normalised-since-112)).

**`GET me.daysOff` (new, optional).** Always present, `null` by default. To publish holidays and vacations, let your
profile provider also implement `ClientUserDaysOffProviderInterface`:

```php
public function getDaysOff(UserInterface $user, DateTimeImmutable $from, DateTimeImmutable $to): ?array
{
    // $from = start of today, $to = end of today + 60 days, in the user's working-hours time zone.
    return [
        ['date' => '2026-12-08', 'kind' => 'holiday', 'label' => 'Inmaculada Concepción'],
        ['date' => '2026-12-28', 'kind' => 'vacation'],
    ];
}
```

Dates are local `YYYY-MM-DD` in that time zone; `kind` is `holiday` or `vacation` (any case); `label` is optional plain
text (cleaned of control / bidi / zero-width characters, cut to 80 grapheme clusters; do not HTML-escape it). A
malformed entry is dropped alone and logged as a `warning`; a value that is not a list is sent as `null`. The method runs
on every `GET me`: cache it if it reads a calendar service.

**Privacy.** Labels reach the user's devices and are stored there. Send public holiday names only, `null` for
vacations, and never a leave reason. TimeTrack Desktop 0.5.0+ (unreleased) reads `daysOff`; the extension ignores it. A class implementing
`ClientUserProfileProviderInterface` keeps working unchanged.

**Imported entries (audit follow-ups).**

- `ImportedTimeEntryRepositoryInterface` is now the service `TimeEntryRepositoryInterface` resolves to (your
  replacement or decorator), no longer the raw Doctrine store. If you replace or decorate `TimeEntryRepositoryInterface`
  and read imported entries, implement `ImportedTimeEntryRepositoryInterface` on that service (a decorator delegates
  the methods) — getting the interface otherwise throws `LogicException` — or alias the interface yourself.
- A masked imported entry (`taskVisible: false`) now has `description: null` in the client API and an empty CSV
  `description` cell. Host lists that call `toArray(false, …)` get the same.
- The writer refuses `startedAt` before 1970 (`startedAt` / `too_old`) and `endedAt` more than one day in the future
  (`endedAt` / `future`). Skip or fix such rows before `upsertMany()`.
- After `remove*()` (DQL bulk deletes), entries of the removed rows that your entity manager had loaded are detached.
  Do not keep using `TimeEntry` objects of removed rows.
- The masked title is translated into the user's language only when the bundle ships it (`de`, `en`, `es`, `fr`, `it`,
  `nl`, `pt`); otherwise the translator's default locale is used.
- Optional: let your task provider implement `Integration\BatchTaskProviderInterface::filterTrackable()` so masking
  makes one call per entry owner (large exports).

## To 1.11.0

**Schema (every host).** Additive and nullable; existing rows are unchanged. Doctrine hosts run
`doctrine:migrations:diff`; the diff must be exactly this (table name without your `table_prefix`):

```sql
-- MySQL / MariaDB
ALTER TABLE time_track_entries
  ADD external_source VARCHAR(64) DEFAULT NULL, ADD external_id VARCHAR(128) DEFAULT NULL,
  ADD billable TINYINT(1) DEFAULT NULL, ADD description LONGTEXT DEFAULT NULL;
CREATE UNIQUE INDEX uniq_time_track_entries_external ON time_track_entries (external_source, external_id);
CREATE INDEX idx_time_track_entries_source ON time_track_entries (source);
CREATE INDEX time_track_entries_ended_idx ON time_track_entries (ended_at, id);
-- PostgreSQL: BOOLEAN / TEXT. SQLite: one ADD COLUMN per column, BOOLEAN / CLOB.
```

**`time_track_entries_ended_idx` already exists?** Beacon added it in its own migration (`Version20261121090000`)
with the same name and columns, so for Beacon the diff has no `CREATE INDEX` for it: keep that migration as it is.
If **your** host created an equivalent index under **another name**, the diff creates a second one. Either drop yours
in the same migration (`DROP INDEX <name> ON time_track_entries;` on MySQL, `DROP INDEX <name>;` on PostgreSQL /
SQLite), or rename it when it already has the columns `(ended_at, id)` (`ALTER TABLE time_track_entries RENAME INDEX
<name> TO time_track_entries_ended_idx` on MySQL 8, `ALTER INDEX <name> RENAME TO time_track_entries_ended_idx` on
PostgreSQL) and remove the `CREATE INDEX` line. A single-column `(ended_at)` index is covered by the new one: drop it.

A reference migration (MySQL) ships in the demo: `demo/symfony8/migrations/Version20261105000000.php`.

**Downgrade.** 1.10 cannot hydrate `source = 'import'` (`TimeEntrySource::from()` throws): delete the imported rows
(`DELETE FROM time_track_entries WHERE source = 'import'`) before going back. The columns and indexes can stay.

**Code.**

- `TimeEntrySource` has a new case `Import`: an exhaustive `match` on it must handle `import`.
- `TimeEntry::toArray()` has more keys (`billable`, `description`, `readOnly`, `taskVisible`); the CSV export has
  three more columns at the end. Positional CSV readers keep working.
- Live-time logic in the host (overlap checks, working-hours registers, correction windows, forgotten-timer stoppers,
  clock-in links) must read **live entries only**: `TimeEntrySource::isLive()` or
  `ImportedTimeEntryRepositoryInterface::findLiveByUserAndPeriod()`, or set
  `TimeEntryListQueryEvent::setIncludeImported(false)`.
- Retention purges (yours, or the bundle's later) must skip `TimeEntryRetentionPolicy::excludedSources()` (imported
  entries are kept as history unless `retention.include_imported: true`).
- **Deleting tasks or boards never deletes tracked time** (owner rule 2026-11-05). `removeForTasks()` /
  `removeForBoard()` / `remove()` are for an **explicit manager action** on imported time only; do not call them from
  a task or board deletion. Account erasure may call `removeForUser()`.
- `GET me` has a new key `locale` (string or null). To fill it, let your `client_api.profile_provider` service also
  implement `Client\ClientUserLocaleProviderInterface`; otherwise the user's `getLocale()` is used when it exists.
- `upsertMany()` refuses a batch with two rows for the same `(externalSource, externalId)` (`rows` / `duplicate`):
  send each identity once per call.
- Concurrent imports: a unique violation is retried once in the writer only with a `ManagerRegistry` and outside any
  host transaction; otherwise `UniqueConstraintViolationException` is rethrown (nothing written) and the host retries
  the slice. Do not call the writer inside your own transaction. A custom `ImportedTimeEntryRepositoryInterface`
  store implements `canRetryAfterFailedSave()` and `attachUser()`.
- **Task titles are masked per surface.** Overrides of `reports.html.twig` (including a surgical
  `nowo_ui_list_row` override) must keep the `hidden_task_entry_ids` check; host lists and exports of entries (e.g.
  *My time*, monthly summaries, inspection exports) must call `TimerService::hiddenTaskEntryIds()` and use
  `toArray(false, $translatedTitle)` / `reports.task_not_visible` for those ids. `taskTitleSnapshot` and `toArray()`
  without arguments return the real title.
- The client API's own `GET entries` and `GET export` always include the user's imported entries (GDPR art. 15 / 20)
  and ignore `setIncludeImported(false)`.
- A custom `TimeEntryRepositoryInterface` store keeps working; only `ImportedTimeEntryWriter` needs
  `ImportedTimeEntryRepositoryInterface` (it throws `LogicException` otherwise).
- Clients (Desktop, Extension): render `source: import` as *Imported*, never offer edit, and skip `readOnly` entries
  for *Resume* and recent tasks.

## To 1.10.3

From **1.10.2**: no schema change, no config change.

```bash
composer update nowo-tech/time-track-bundle
```

- Notes no longer keep U+061C, U+200E or U+200F (EF2-I7).
- Hosts calling `TimerService::start()` / `startAt()` with `['note' => …]` in the metadata get it cleaned like a client
  note; a non-string or blank note is dropped from the metadata (EF2-I9). Other metadata keys are unchanged.
- A timer note at 500 code points does not take later notes (they are dropped, earlier text wins), as documented in
  CLIENT-API (EF2-I8).

## To 1.10.2

From **1.10.1**: no schema change, no config change.

```bash
composer update nowo-tech/time-track-bundle
```

- **Stop and switch notes are merged, not replaced.** An entry's `metadata.note` is now the start note, then every
  idle `keep` note, then the stop / switch note, one per line, cut at 500 code points (earlier text first). Before,
  a stop note replaced the notes collected on the timer. Code that reads `metadata.note` and expected only the stop
  note should split on `\n` or take the last line.
- **Follow-up timers start without a note** (idle `split`, reassign `then: continue`); the note stays on the entry up
  to the idle start.
- **Notes are cleaned**: no bidi controls, no U+200B / U+FEFF, control characters folded to one space, trimmed. Hosts
  calling `TimerService` directly get the same cleaning; `ActiveTimer::appendNote()`'s `$maxLength` is now optional.
- **`IdleResolvedEvent::getNote()` is raw user text** (EF2-I5). It is cleaned but not escaped: escape it for HTML, CSV
  or any other output, and do not write it to logs.
- **Known race (EF2-I1, unchanged).** The keep note is appended with a read-modify-write of the timer row: two keeps
  sent at the same moment for the same timer can lose one note, and a keep that races a stop answers `200` although
  the entry does not get its note. It only affects the user's own annotation; clients send one decision at a time.

## To 1.10.1

From **1.10.0**: no schema change, no BC break.

```bash
composer update nowo-tech/time-track-bundle
```

- `IdleCheckSettings` has a fourth constructor argument, `userCanDisable` (default `false`): an
  `IdleCheckSettingsProviderInterface` written for 1.10.0 keeps working and reports `user_can_disable: false`. Pass
  `true` for users who may turn the idle check off (Beacon: personal accounts), or set
  `client_api.idle_check.user_can_disable` for the instance default.
- `timer/discard-idle` `keep` with a `note` now updates the running timer (`metadata.note`). Listeners of
  `nowo_time_track.idle.resolved` can read it with `IdleResolvedEvent::getNote()`; do not log the text.

## To 1.10.0

Client API contract v0.3. From **1.9.2**: no schema change, no config change needed.

```bash
composer update nowo-tech/time-track-bundle
```

- **Task provider (optional).** Fill the new `TaskReference` arguments to show the project and an "open in" link in
  the clients: `new TaskReference($id, $title, $boardId, $boardTitle, projectId: …, projectTitle: …, url: …)`. The
  `url` must be an absolute `https://` URL of the task page on the host's public origin; anything else is published as
  `null`. `TaskProviderInterface` is unchanged.
- **Behind a TLS-terminating proxy.** URLs are compared with the request origin; without `framework.trusted_proxies`
  the request looks like `http://…` and every URL is dropped. Either trust the proxy or set the origin explicitly from
  your public URL parameter: `nowo_time_track.client_api.task_url.origin: '%app.public_url%'` (exact
  `https://host[:port]`, no path, no trailing slash; validated when the container is built).
- **New client API behaviour** (additive): `startedAt` on `timer/start`, `endedAt` on `timer/stop`, `reassign` on
  `timer/discard-idle`, `features` / `limits` on `GET me`. Tune or disable with `client_api.backdate.*`,
  `client_api.idle.allow_reassign` and `client_api.idle_check.*` ([CONFIGURATION](CONFIGURATION.md#client-api-110)).
- **`IdleResolution` has a new case `Reassign`.** A host `match` over it without a `default` arm throws
  `UnhandledMatchError` for reassign decisions; add the case.
- **`TimerService::resolveIdle()`** returns a third key, `reassigned` (`null` except for reassign), and takes optional
  `targetTaskId`, `followUp`, `clientType`, `note` arguments. `start()` / `switchTo()` now throw
  `TaskNotTrackableException` (a subclass of `InvalidArgumentException`, so existing catches still work).
- **Own (non-Doctrine) time entry store:** implement `LatestTimeEntryRepositoryInterface::findLatestEndedAt()` for the
  backdate overlap check in one query. Without it the bundle reads the user's entries of the backdate window plus one
  day through `findByUserAndPeriod()`, so an entry that **started more than one day before the window and ended inside
  it** is missed and a backdated start may overlap it (EF-I6). The Doctrine store implements the interface.
- **Task URL origin and the `Host` header (EF-I1).** With `client_api.task_url.origin` unset, the expected origin is
  the request's, which comes from the caller's `Host` header: a caller sending `Host: evil.test` gets URLs on
  `https://evil.test` published in its own response (never in other users' responses; no shared caching). Set
  `client_api.task_url.origin` (Beacon does) or `framework.trusted_hosts`.
- **Client timestamps (1.10).** `startedAt`, `endedAt`, `from` and `to` are parsed strictly: a lower-case `z` is
  accepted, surrounding whitespace is no longer trimmed (`400`), and `from` / `to` are compared on whole seconds.
- **Listeners of `nowo_time_track.timer.stop`** now also receive the entry of a reassigned idle span (the time moved to
  another task), so per-task totals stay right. `IdleResolvedEvent` (`nowo_time_track.idle.resolved`) lets you audit
  idle decisions and `TimerStartEvent::getStartTime()` / `TimerStopEvent::getEndTime()` backdated starts and stops.
- **Overlap scope.** The backdate overlap check covers this bundle's entries only; time logged elsewhere (e.g.
  TaskBoardBundle's manual "Log time") is not considered.

## To 1.9.2

From **1.9.1**: no schema change, no BC break.

```bash
composer update nowo-tech/time-track-bundle
```

- `timer/discard-idle` now refuses `from` / `to` offsets outside `-12:00`..`+14:00` (`400 invalid_request`) and stores
  the instant the client sent (EF-M1). Entries recorded before the update with a non-UTC offset (or by a `Z` client on
  a server whose PHP time zone is not UTC) may be shifted; review them if you need exact history.
- Hosts calling `TimerService` directly should pass date-times in the server time zone (Doctrine stores the wall time
  without its offset).

## To 1.9.1

From **1.9.0**:

```bash
composer update nowo-tech/time-track-bundle
```

From **1.9.0**: no schema change, no BC break.

- `GET me` may now carry `minSupportedVersion`. To send it, set
  `client_distribution.min_supported_version.extension|desktop|web` (semver) or point
  `client_distribution.min_supported_version.provider` at your `ClientMinSupportedVersionProviderInterface` service.
  `ClientDistributionProviderInterface` is unchanged.
- **Own (non-Doctrine) active timer store:** it keeps working, but it **keeps the 1.x stop race** (two concurrent
  `timer/stop` / `timer/switch` calls can record the same entry twice) until it implements
  `ConditionalActiveTimerRepositoryInterface::removeIfPresent()` (conditional delete + the callback in one
  transaction). The bundled Doctrine store already does.
- `TimerService::stop()` / `resolveIdle()` may now return `null` when a concurrent call stopped the timer first
  (`timer/stop` / `timer/discard-idle` then answer `404 no_active_timer`). A `timer/switch` whose stop lost to a
  concurrent stop starts the new task (`201`) without recording a second entry.
- `resolveIdle()` may throw `ActiveTimerConflictException` (API: `409 timer_running`) when a concurrent start took the
  timer slot before a split continued.
- `minSupportedVersion` values from a host provider must be semver; others are omitted from `GET me`.

## To 1.9.0

From **1.8.0**:

```bash
composer update nowo-tech/time-track-bundle
```

### To 1.9.0

From **1.8.0**: `composer update nowo-tech/time-track-bundle`, then the schema migration below. Client API contract
v0.2 ([CLIENT-API](CLIENT-API.md)); no change to the manage pages.

**Schema** (MySQL; reference: `demo/symfony8/migrations/Version20261103000000.php`; use your `table_prefix`). Schema
only — no data migration, safe with `doctrine_migrations.transactional: false`:

```sql
ALTER TABLE time_track_client_tokens ADD credential_id VARCHAR(191) DEFAULT NULL, ADD scopes JSON DEFAULT NULL;
CREATE INDEX time_track_client_tokens_credential_idx ON time_track_client_tokens (credential_id);
CREATE TABLE time_track_device_codes (id VARCHAR(36) NOT NULL, status VARCHAR(16) NOT NULL, credential_id VARCHAR(191) DEFAULT NULL, last_polled_at DATETIME DEFAULT NULL, scopes JSON NOT NULL, device_code_hash VARCHAR(64) NOT NULL, user_code_hash VARCHAR(64) NOT NULL, client_type VARCHAR(16) NOT NULL, client_name VARCHAR(100) NOT NULL, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL, ip_prefix VARCHAR(64) DEFAULT NULL, user_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_4978FDD0DCB31CE8 (device_code_hash), INDEX time_track_device_codes_user_code_idx (user_code_hash), INDEX time_track_device_codes_expires_idx (expires_at), INDEX IDX_4978FDD0A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
ALTER TABLE time_track_device_codes ADD CONSTRAINT FK_4978FDD0A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE;
```

Or run `doctrine:migrations:diff` and review. Tokens issued before the upgrade keep working with every scope.

**Kept (BC)**

- `ClientAuthenticatorInterface` and `ClientAuthResult::success($user)`; the new `$credentialId` / `$scopes` arguments
  are optional (none = no credential, all scopes). A `$scopes` list must be non-empty and hold known scopes only
  (`timer:read`, `timer:write`, `tasks:read`, `entries:read`), otherwise `InvalidArgumentException`: pass `null` for
  every scope.
- `ClientTokenRepositoryInterface` is unchanged (host decorators keep compiling); bulk revocation uses the new
  `CredentialClientTokenRepositoryInterface`, implemented by the Doctrine repository.
- Login request, route names and paths; `ClientAuthService::login()` / `resolveUser()`; `TimerService::heartbeat()`.
- Without any `client_api.*` key the host interfaces fall back to 1.x behaviour (credential always active, task check
  through the task provider, opaque device credentials) and the device flow stays off.

**Behaviour changes of the client API (clients, not hosts)**

- Error bodies are `{"error": "<code>", "message": "..."}` (1.x: `{"error": "<message>"}`).
- `timer/start` on a missing or foreign task: `403 task_forbidden` (1.x: `400`). Free `metadata`: `400
  metadata_not_allowed` unless `client_api.allow_metadata: true`; send `source` and `note`.
- `heartbeat` is deprecated (removed in 2.0): `isIdle` is ignored and no longer stored in the timer metadata; use
  `timer/discard-idle`.
- `GET me` adds `displayName`, `scopes`, `workingHours`; `login` adds `scopes`; `GET entries` returns own entries by
  default (`limit` ≤ 100, `offset`) — `userId` still works through the team guard but is deprecated; `GET tasks`
  `limit` ≤ 50 (1.x: 100).
- Per-IP (300/min) and per-token (60/min) rate limits on the shared pool `client_api.rate_limit.cache_pool`
  (default `cache.app`; use Redis in production). Every per-IP limit, including the 1.x client-login throttle, now
  counts an IPv6 client by its /64.
- Client login: new per-username bucket `clients.login_rate_limit.max_attempts_per_username` (default 5 ×
  `max_attempts` = 50 failures per 15 min, any IP); a successful login clears it.
- Device flow: `device/code` answers `400 invalid_scope` for an empty or unknown scope list (omit `scopes` for every
  scope) and `429` above `client_api.device_flow.max_pending_per_prefix` (20 per /24 or /64 within `code_ttl`) or
  `max_pending` (10 000 unexpired requests).
- CORS: exact origins only. The 1.x `tauri://` prefix rule became the exact Tauri webview origins
  (`client_api.allow_tauri_webview_origins`, deprecated, true until 2.0); `clients.cors_allow_any_extension_origin`
  and `*` are deprecated.

**Deprecations (removed in 2.0)**: `POST heartbeat`, `entries?userId=`, `clients.cors_allow_any_extension_origin`,
`*` in `clients.cors_allowed_origins`, `client_api.allow_tauri_webview_origins: true`.

**Beacon-style hosts**: implement `ClientCredentialStateInterface`, `ClientCredentialIssuerInterface` and
`TaskAccessCheckerInterface`; call `ClientTokenRevoker::revokeByCredential()` when an app password is deleted; set
`device_flow.default_page: false` + `verification_uri` for your own page ([USAGE](USAGE.md#host-integration-client-api-19)),
sending `X-Frame-Options: DENY` and `Content-Security-Policy: frame-ancestors 'none'` on it.

## To 1.8.0

From **1.7.0**:

```bash
composer update nowo-tech/time-track-bundle
```

Route names, paths, controllers, form field names / CSRF id and the client API are unchanged. The manage pages now use
the UiKit page contract (REQ-UI-003); hosts that render them should check the points below.

- **Dependency:** `nowo-tech/ui-kit-bundle` `^2.20` (UiKit 1.x is no longer supported).
- **Layout:** the pages render inside `nowo_ui_kit.layouts.admin` (UiKit default: its own admin layout). Point it at
  your layout or at a one-file bridge (see [CONFIGURATION](CONFIGURATION.md#web-ui-and-templates-req-ui-001--req-ui-003)),
  or set `nowo_time_track.web_ui.layout_template`. With the 1.x default (`templates.layout` unset) the bundle demo
  shell (dark nav bar with Board / Timer / Reports links) is gone: add the navigation to your layout.
- **Deprecated config (removed in 2.0):** `templates.layout` → `web_ui.layout_template`; `templates.css_framework` →
  `nowo_ui_kit.css_framework`; `templates.index` / `templates.reports` → `web_ui.templates.index` / `.reports`. The old
  keys keep working in 1.x and trigger a deprecation. A layout set through them that only has the 1.x blocks
  (`title`, `stylesheets`, `body`, `javascripts`, optionally `nowo_ui_content` inside `body`) is wrapped in
  `_legacy_layout.html.twig` (runtime deprecation): the page renders once inside `body`, host CSS / JS keep loading.
- **Template overrides:** a full-file override of `time_track/index.html.twig` / `reports.html.twig` written for 1.x
  keeps rendering: it still receives `active_timer`, `tasks`, `stop_form`, `start_forms` / `entries`, `entry_page`,
  `from`, `to`, and `{% extends nowo_time_track_layout %}` renders inside the UiKit admin layout through the
  `layout.html.twig` shim. New overrides should extend `@!NowoTimeTrackBundle/time_track/<page>.html.twig` and fill
  a data block instead (see [TEMPLATES](TEMPLATES.md)).
- **Markup / CSS hooks:** host CSS or tests targeting the 1.x classes (`.card`, `.flash-success`, `.btn-primary` in
  the bundle pages, `[data-test-layout]`) must move to the UiKit classes / `data-testid="time-track-timer"`,
  `time-track-reports`, `time-track-stop`, `time-track-start-<task id>`.

- **Reports pagination:** the reports page now shows `reports.page_size` entries (default **50**) per page with
  previous / next links; set `nowo_time_track.reports.page_size` (max 500) to change it. A host override of
  `time_track/reports.html.twig` keeps receiving `entries` (now the entries of the current page) and can render
  `entry_page` (`Nowo\TimeTrackBundle\Dto\TimeEntryPage`) for the links.
- **Custom repositories:** a host `TimeEntryRepositoryInterface` implementation keeps working (the page is sliced in
  memory); implement `PaginatedTimeEntryRepositoryInterface` to page in the database.
- **Logging:** records go to the Monolog channel `time_track`; exclude it in your handlers (`channels: ['!time_track']`)
  if you do not want them.
- **Subclasses / manual construction:** the new constructor arguments (clock, logger, reports page size) are optional
  and trailing; code that builds `TimerService`, `ClientAuthService` or the controllers by hand needs no change.

## To 1.7.0

`nowo-tech/form-kit-bundle` (`^2.14`) is now required. Register `Nowo\FormKitBundle\NowoFormKitBundle` in
`config/bundles.php` if Flex did not (the form still renders without it, but the profile is only applied with it).
Templates, field names and ids are unchanged: no override needs an update. To theme the bundle forms, configure
`nowo_form_kit.profiles.time_track` (see [CONFIGURATION](CONFIGURATION.md#formkit-profile-req-twig-005)).

## To 1.6.0

From **1.5.2**:

```bash
composer update nowo-tech/time-track-bundle
```

- **Template override (REQ-TWIG-005):** `time_track/index.html.twig` no longer emits a hand-rolled `<form>` with `_token` / `action` / `task_id` hidden inputs. `TimeTrackManageController::index()` now always passes `stop_form` (FormView) and `start_forms` (FormView per task id). If you override `@NowoTimeTrackBundle/time_track/index.html.twig`, render those with `form_start` / child loop / `form_end` (see [USAGE](USAGE.md)). The posted field names and CSRF id are unchanged, so old overrides keep working, but they should be migrated.
- **Dependency:** the bundle now requires `symfony/form` (`^7.4 || ^8.0`); FrameworkBundle enables `framework.form` automatically when it is installed.

## To 1.5.2

From **1.5.1**:

```bash
composer update nowo-tech/time-track-bundle
```

## From 1.5.0 to 1.5.1

From **1.5.0** — Bridge defaults no longer re-registered by the resource scan

```bash
composer update nowo-tech/time-track-bundle
```

- No breaking changes. No application upgrade steps.

## From 1.4.0 to 1.5.0

From **1.4.0** — security - per-IP login throttle, credential-less CORS with explicit extension origins; full coverage.

```bash
composer update nowo-tech/time-track-bundle
```

- **CORS for browser extensions:** any `chrome-extension://` / `moz-extension://` origin is no longer accepted by default. The bundled Chrome extension calls the API from extension pages with `host_permissions`, which Chrome exempts from CORS, so it keeps working. If another client depends on CORS, add its exact origin (e.g. `chrome-extension://<id>`) to `clients.cors_allowed_origins`, or set `clients.cors_allow_any_extension_origin: true` to restore the previous behaviour.
- `Access-Control-Allow-Credentials` is no longer sent. Clients must authenticate with the `Authorization: Bearer` header (as the bundled clients do), not cookies.
- **Login throttle:** an IP is now also blocked after `max_attempts_per_ip` failed logins across all usernames (default 5 × `max_attempts` = 50 per 15 min). Raise `clients.login_rate_limit.max_attempts_per_ip` for many users behind one NAT.
- `security.manager_can_edit_entries` is deprecated (no bundle route uses it). Remove it from your config; if your own code calls `TeamAccessGuard::canEditEntry()`, move the manager rule to a `nowo_time_track.time_entry.access_check` listener.

## From 1.3.7 to 1.4.0

From **1.3.7** — security - strict report dates, bounded API queries, 409 on concurrent timer start, login timing.

```bash
composer update nowo-tech/time-track-bundle
```

- Report and entries dates must be ATOM or `Y-m-d`; other formats (e.g. "yesterday") fall back to the defaults instead of causing HTTP 500.
- API `limit` above 100 is capped, and date ranges are limited to 366 days.
- A concurrent timer start now returns 409 instead of 500.

## From 1.3.6 to 1.3.7

From **1.3.6** — dependency refresh and repository maintenance.

```bash
composer update nowo-tech/time-track-bundle
```

- No breaking changes. No application upgrade steps.

## From 1.3.5 to 1.3.6

Dependency refresh only. No breaking changes. No application upgrade steps.

```bash
composer update nowo-tech/time-track-bundle
```

## From 1.3.4 to 1.3.5

From **1.3.4** — `access_roles` fail-closed; Doctrine `SortDirection`.

```bash
composer update nowo-tech/time-track-bundle
php bin/console cache:clear
```

- Empty `security.access_roles` denies the manage UI. Set roles / custom checker, or demo-only `allow_unauthenticated`.
- Ensure `doctrine/orm` is `^3.7` (SortDirection).

## From 1.3.2 to 1.3.3

No breaking changes. **No application upgrade steps.**

```bash
composer update nowo-tech/time-track-bundle
```

# Upgrading

This document describes how to upgrade between versions of TimeTrack Bundle.

## 1.2.0 (2026-08-03)

Minor release: REQ-UI-002 manage-UI access checker (`access_roles` / `access_checker` / `allow_unauthenticated`) plus SecurityBundle compile-time guard. Demo no longer path-mounts sibling optional bundles.

### Install / update

```bash
composer require nowo-tech/time-track-bundle:^1.2
php bin/console cache:clear
```

### Behaviour change (manage roles)

| Topic | Before | 1.2.0 |
| --- | --- | --- |
| Default manage gate | `#[IsGranted('ROLE_USER')]` on controller | `TimeTrackAccessCheckerInterface` driven by `security.access_roles` (default `[ROLE_USER]`) |
| Apps without SecurityBundle | Could boot | Boot fails with `LogicException` unless `allow_unauthenticated: true` |

**Demos / trusted local kernels** without SecurityBundle:

```yaml
nowo_time_track:
    security:
        allow_unauthenticated: true   # never in production
```

**Production** (recommended): keep `allow_unauthenticated: false`, ensure SecurityBundle is installed, and grant at least one of `access_roles` (or provide a custom `access_checker`).

### New optional config

```yaml
nowo_time_track:
    security:
        access_roles: [ROLE_USER]
        access_checker: null
        allow_unauthenticated: false
```

### Breaking changes

Apps that load the manage UI without SecurityBundle must either install/configure SecurityBundle or set `allow_unauthenticated: true` for non-production use. Hosts that relied only on firewall `access_control` without matching `access_roles` should align roles.

## 1.1.0 (2026-07-30)

Host layout / CSS stack integration without forking timer pages (REQ-UI-001). Aligned with TaskBoardBundle **1.3.0**.

```bash
composer require nowo-tech/time-track-bundle:^1.1.0
php bin/console cache:clear
```

### Layout integration (REQ-UI-001)

- Timer/report pages now `{% extends nowo_time_track_layout %}` (Twig global from `nowo_time_track.templates.layout`).
- **Hosts:** set `templates.layout` to the project layout (or a one-file bridge). Do not fork `index.html.twig` / `reports.html.twig` only to change chrome.
- Custom Twig overrides that still hard-code `@NowoTimeTrackBundle/layout.html.twig` should switch to the global so config takes effect.
- Styles/scripts blocks on pages call `{{ parent() }}` so host assets remain when using the project layout.

### CSS framework (REQ-UI-001)

- New config `templates.css_framework` (default **`tabler`**) exposed as Twig global `nowo_time_track_css_framework`.
- Accepted values: `bootstrap`, `bootstrap4`, `bootstrap5`, `tabler`, `tailwind`, `foundation`, `custom`, `none`.
- Align with TaskBoardBundle (`templates.css_framework`). Set the same value as your host admin stack; with `custom`, keep Bootstrap/Tabler-compatible class names styled by the host.

```yaml
nowo_time_track:
    templates:
        layout: 'base.html.twig'
        css_framework: tabler   # or bootstrap5 | custom
```

## 1.0.x patch releases

### 1.0.6 (2026-07-29)

- **Security** — Manage UI start/stop POSTs require CSRF token `nowo_time_track_manage`. Update custom Twig overrides of the timer UI to include the token (see demo/`index.html.twig`).
- FrankenPHP banner / demo PHP **8.5**; CI matrix Symfony **7.4 / 8.0 / 8.1** (Symfony **7.0** dropped from CI — package constraints unchanged unless you relied on CI-only coverage).
- Contributors: PHPStan baseline removed; `phpstan-frankenphp` rulesets after `composer install`.

```bash
composer update nowo-tech/time-track-bundle
php bin/console cache:clear
```

### 1.0.5 (2026-07-16)

- **Translations** — New locale files for **de**, **fr**, **it**, **nl**, **pt**. No config changes required; Symfony picks them up when the app locale matches.
- **Repository-only** — Code of Conduct, GitHub CI docs, and git hygiene tooling (REQ-GIT-001). No bundle API, config key, or migration changes.

```bash
composer update nowo-tech/time-track-bundle
```

### 1.0.4 (2026-07-13)

Repository-only: security documentation, coverage reporting script, Cursor rules, and dev lockfile updates. No bundle API, config key, or migration changes for consumers.

```bash
composer update nowo-tech/time-track-bundle
```

### 1.0.3 (2026-07-08)

Repository-only: GitHub Spec Kit scaffolding, baseline specs, and documentation. No bundle API, config key, or migration changes for consumers.

```bash
composer update nowo-tech/time-track-bundle
```

### 1.0.2 (2026-07-07)

Repository-only: demo `reference.php` strict-types alignment. No bundle API, config key, or migration changes.

```bash
composer update nowo-tech/time-track-bundle
```

### 1.0.1 (2026-07-06)

Repository-only: CI Code Style job fix and demo `reference.php` strict-types. No bundle API, config key, or migration changes for consumers.

```bash
composer update nowo-tech/time-track-bundle
```

## 1.0.0 (2026-07-06)

First stable release. No upgrade steps required.

### Requirements

- **PHP** >= 8.2 (Symfony 8.x requires PHP 8.4+).
- **Symfony** ^7.4 || ^8.0.
- **Doctrine ORM** ^2.15 || ^3.0 with `doctrine/doctrine-bundle` ^2.10 || ^3.0.

### Installation checklist

1. Install the package:

   ```bash
   composer require nowo-tech/time-track-bundle
   ```

2. Configure `user_class` and enable clients if you use the extension or desktop agent:

   ```yaml
   # config/packages/nowo_time_track.yaml
   nowo_time_track:
       user_class: App\Entity\User
       clients:
           enabled: true
   ```

3. Run Doctrine migrations (see demo migration `demo/symfony8/migrations/Version20250706120000.php` for reference schema).

4. Add security rules — Bearer auth for `/api/time-track/*`, session auth for `/tools/time-track`:

   ```yaml
   # config/packages/security.yaml
   security:
       access_control:
           - { path: ^/api/time-track, roles: PUBLIC_ACCESS }
           - { path: ^/tools/time-track, roles: ROLE_USER }
   ```

5. **Optional:** wire **TaskBoardBundle** providers — see [TaskBoard integration](TASK-BOARD-INTEGRATION.md).

### Client apps (repository only)

- **Browser extension** — load unpacked from `extension/chrome/`; enable `clients.enabled` and CORS on the host app.
- **Desktop agent** — build from `desktop/` with Tauri 2; default demo API URL is `http://localhost:8024`.

Neither client is part of the Packagist archive (`demo/` and client folders are for development and integration testing).

## To 1.3.5

From **1.3.4** — `access_roles` fail-closed; Doctrine `SortDirection`.

```bash
composer update nowo-tech/time-track-bundle
php bin/console cache:clear
```

- Empty `security.access_roles` denies the manage UI. Set roles / custom checker, or demo-only `allow_unauthenticated`.
- Ensure `doctrine/orm` is `^3.7` (SortDirection).

## To 1.3.4

From **1.3.3** — REQ-CS-008 Igor FrankenPHP worker audit (igor-php require-dev, igor.json, make igor).

```bash
composer update nowo-tech/time-track-bundle
php bin/console cache:clear
```

No breaking changes and no host migration. `TeamAccessGuard` and `ClientAuthService` now reload the user entity (`EntityManager::refresh()`) before role checks, which adds one `SELECT` when an admin-role check runs and one per client API request. Code that instantiates these services or the `DoctrineOrm*Repository` classes manually keeps working: the new `?ManagerRegistry` argument is optional (without it, no refresh or closed-manager reset happens). For `clients.login_rate_limit.cache_pool`, keep using a pool shared by all workers (Redis, filesystem, APCu); an array pool is per worker and unbounded.
- No application upgrade steps for require-dev Igor tooling (REQ-CS-008). Consumers do not pull `igor-php/igor-php` transitively.

## To 1.3.2

No application upgrade steps.

```bash
composer update nowo-tech/time-track-bundle
php bin/console cache:clear
```

## To 1.3.1

No application upgrade steps. **Demos only:** Hot Reload Bundle `^1.4` (FrankenPHP Mercure/`hot_reload`, `dev`/`test`).

```bash
composer update nowo-tech/time-track-bundle
php bin/console cache:clear
```

## To 1.3.0

From **1.2.0** — Adds FormKit and/or UiKit where applicable, Twig Extra (REQ-TWIG-004), and Twig-CS-Fixer. Register TwigExtraBundle, NowoFormKitBundle, and NowoUiKitBundle if Flex did not. See CHANGELOG.

```bash
composer update nowo-tech/time-track-bundle
php bin/console cache:clear
```

### Twig Extra Bundle (REQ-TWIG-004)

Hosts that render this bundle's Twig templates must install:

```bash
composer require twig/extra-bundle twig/string-extra
```

and enable `Twig\Extra\TwigExtraBundle\TwigExtraBundle`. Flex recipes usually register it automatically.

### Twig-CS-Fixer (maintainers)

Package maintainers: `composer twig:lint` / `composer twig:fix` use `.twig-cs-fixer.php` over `src/` (and `templates/` when present).

