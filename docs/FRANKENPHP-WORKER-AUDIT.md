# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/time-track-bundle` (`symfony-bundle`) |
| Current audit | **1.27.0** (Unreleased, `feat/load-readiness`), 2026-11-15, load readiness (Beacon spec 239) — see [Re-audit 1.27](#re-audit-127-load-readiness-beacon-spec-239); **1.25.0** (Unreleased, `feat/host-timer-api`), 2026-11-13, host timer API and service accounts — see [Re-audit 1.25](#re-audit-125-host-timer-api-and-service-accounts); **1.23.0** (`feat/arch-001`), 2026-11-12, framework-free ports and the private entity manager — see [Re-audit 1.23](#re-audit-123-req-arch-001-ports-and-private-entity-manager); **1.22.0** (`feat/leave-spec-204`), 2026-11-12, client leave (spec 204) — see [Re-audit 1.22](#re-audit-122-client-leave-spec-204); **1.20.0** (`feat/calendar-oauth-app-provider`), 2026-11-11, runtime OAuth apps — see [Re-audit 1.20](#re-audit-120-runtime-calendar-oauth-apps); **1.19.0** (`feat/1.19-block1`), 2026-11-11, against REQ-RUNTIME-002 (kernel reset false) — see [Re-audit 1.19](#re-audit-119-req-runtime-002-kernel-reset-false) |
| First audit | `v1.3.3` / `d90eed2`, 2026-09-23 (findings W-01 … W-03 below) |
| Method | Manual review of every service, listener, command and controller under `src/` (grep for `static`, mutable properties, `ResetInterface`, `exit` / `die`), Igor (`composer igor`), PHPStan with `nowo-tech/phpstan-frankenphp` `ruleset-classic` + `ruleset-worker`, and the many-request worker test |
| **Verdict** | ✅ **Works with `FRANKENPHP_RESET_KERNEL=false`** — no service keeps request, user or tenant data; the one per-flush state is cleared by the bundle at every request, message and command boundary; the worker test passes with one kernel and two people in turn |

## Table of contents

- [Re-audit 2.2 (device-flow scope families, Beacon Desktop BD-2)](#re-audit-22-device-flow-scope-families-beacon-desktop-bd-2)
- [Re-audit 1.37 (entry access by id)](#re-audit-137-entry-access-by-id)
- [Re-audit 1.36 (entries without a task, Beacon spec 250)](#re-audit-136-entries-without-a-task-beacon-spec-250)
- [Re-audit 1.35 (read and personal-data ports)](#re-audit-135-read-and-personal-data-ports)
- [Re-audit 2.0 (private entity manager only)](#re-audit-20-private-entity-manager-only)
- [Re-audit 1.33 (host-flush probe, removed in 2.0)](#re-audit-133-host-flush-probe-removed-in-20)
- [Re-audit 1.27 (load readiness, Beacon spec 239)](#re-audit-127-load-readiness-beacon-spec-239)
- [Re-audit 1.26 (activity monitoring, spec 199)](#re-audit-126-activity-monitoring-spec-199)
- [Re-audit 1.25 (host timer API and service accounts)](#re-audit-125-host-timer-api-and-service-accounts)
- [Re-audit 1.23 (REQ-ARCH-001 ports and private entity manager)](#re-audit-123-req-arch-001-ports-and-private-entity-manager)
- [Re-audit 1.22 (client leave, spec 204)](#re-audit-122-client-leave-spec-204)
- [Re-audit 1.20 (runtime calendar OAuth apps)](#re-audit-120-runtime-calendar-oauth-apps)
- [Re-audit 1.19 (REQ-RUNTIME-002, kernel reset false)](#re-audit-119-req-runtime-002-kernel-reset-false)
- [Execution model assumed](#execution-model-assumed)
- [Summary](#summary)
- [Services reviewed](#services-reviewed)
- [Findings](#findings)
- [Usage recommendations in worker mode](#usage-recommendations-in-worker-mode)
- [Re-audit triggers](#re-audit-triggers)

## Re-audit 2.2 (device-flow scope families, Beacon Desktop BD-2)

- `DeviceScopeFamilyRegistry`, `ConfigDeviceScopeFamilyProvider`, `ClientContextResolver`, `ClientFamilyTokenAuthenticator`
  and `CurrentClientContext` are `final readonly` with only collaborators and configuration: the families are read from
  the providers on every call, the context policy is asked on every request, and the principal is built per call.
  Nothing about a request, user, token, family or tenant is kept in a service, a static or a memo.
- The allowed context of a time API request lives in a request attribute (`CurrentClientContext::ATTRIBUTE`), dropped
  with the request; the guard never writes it to a service.
- Test: `ScopeFamilyWorkerModeTest` (kernel not reset, debug off): two people, two contexts and two families in turn on
  one kernel for 250 rounds with the chat token revoked mid-run (refused at its next use), and the family paths keep a
  flat memory (under 64 KB) over 1,000 steps.

## Re-audit 1.37 (entry access by id)

- `Access\TimeEntryAccess` (`TimeEntryAccessInterface`) is `final readonly` with only its store, the guard and the
  configured user class: it reads the entry by its exact id and asks `TeamAccessGuard` at every call, and keeps no
  answer. `Access\IdActor` and `TimeEntryIdAccessCheckEvent` are built per call and dropped with it.
- `TeamAccessGuard::decide()` reads the actor's tenant scopes once per call (never kept), so a worker serving two
  tenants in turn never answers one with the other's scopes; roles come from the reference given (an id actor) or are
  refreshed from the database as before (a managed user).
- Test: `EntryAccessByIdTestCase::testNothingIsKeptBetweenCalls` (two people in turn on one kernel, three rounds; with
  the private entity manager no bundle entity enters the host's manager).

## Re-audit 1.36 (entries without a task, Beacon spec 250)

- `entries.allow_without_task` is a constructor `bool` of `TimerService` (container parameter): no per-request state.
- The user's tenant scopes (a new start or entry without a task, `TeamAccessGuard::isWithinTenant()`, the listing rule
  of `TimerService::listEntries*()`) are asked from the scope provider per call and never kept: a worker serving two
  tenants in turn never answers one with the other's scopes. `TimeEntryListing` is a value built per call.
- `DoctrineOrmTimeEntryRepository::findListingPage()` / `countListing()` build a new query per batch, as the 1.24.1
  exact listings do (REQ-RUNTIME-002); `ScopeKeys` stays stateless.
- Concurrency (REQ-RUNTIME-003): a start without a task takes the same owner lock and the same unique `user_id` slot as
  any start, so two concurrent starts leave one running timer (`LoadReadinessDatabaseTest`, two processes).
- Verdict unchanged: works with `FRANKENPHP_RESET_KERNEL=false`.

## Re-audit 1.35 (read and personal-data ports)

Scope: `Query\DoctrineTimeTrackQuery` (`TimeEntryQueryInterface`, `RunningTimerQueryInterface`,
`ClientAccessQueryInterface`), `PersonalData\DoctrineTimeTrackPersonalData` and `ClientTokenRevoker::revokeAllForUser()`.

| Service | State | Cleared |
|---|---|---|
| `Query\DoctrineTimeTrackQuery` | none (readonly: the bundle's entity manager and an optional clock) | nothing to clear; every call builds its own query builders and selects scalars, so no entity enters any manager |
| `PersonalData\DoctrineTimeTrackPersonalData` | none (readonly collaborators) | nothing to clear; the erasure is one unit of the bundle's runner, opened and closed within the call; rows of the user that the manager had loaded are detached, the manager is never flushed, cleared or reset |

Test: `HostReadPortsTestCase::testRepeatedCallsForTwoUsersInOneKernelKeepNoState` (350 alternating calls for two
users in one kernel, on the standard and the id / XML / private-manager host: results per user, memory growth under
256 KB, empty identity map).

## Re-audit 2.0 (private entity manager only)

Removed in 2.0: the 1.33 host-flush probe (`Doctrine\TimeTrackHostFlushProbe`) and the shared-manager paths of the
transaction runner. New state: `TimeTrackEntityManager::$loaded`, a `WeakMap` of the TimeTrack rows any manager
loaded (keyed by the object itself, so an entry disappears with its object; kept across boundaries on purpose: an
instance kept from an earlier request is refused by `persist()` instead of being inserted again). No request data,
user or tenant is kept by it.

## Re-audit 1.33 (host-flush probe, removed in 2.0)

Scope: `Doctrine\TimeTrackHostFlushProbe` (registered only with `database.private_entity_manager: false`).

| Service | State | Cleared |
|---|---|---|
| `Doctrine\TimeTrackHostFlushProbe` | `$reported` (notices already triggered in this unit of work), `$bundleFlushes` (TimeTrack flushes running, back to its value when each ends), and, without nowo-tech/transaction-doctrine's bundle, its own `HostFlushProbe` (classes already reported) | `onBoundary()` on `kernel.terminate`, `console.terminate`, Messenger `WorkerMessageHandledEvent` / `WorkerMessageFailedEvent` (priority -1024), and `kernel.reset`; the shared probe is also reset by transaction-doctrine's boundary listener when its bundle is registered. Test: `HostFlushProbeTest::testEachRequestReportsTheApplicationsPendingChangeAgain` (five requests through `http_kernel` without the services resetter, five notices) |

The probe is read-only: it never flushes, clears, closes, resets or detaches the application's manager.

## Re-audit 1.27 (load readiness, Beacon spec 239)

Scope: `Transaction\TimeTrackTransactionRunner`, `EventSubscriber\StorageFailureSubscriber`, `Doctrine\TimeTrackRows`,
`Doctrine\RecoveringFlusher` and the write paths that now run on the runner. PHPStan with nowo-tech/phpstan-frankenphp
1.4 and its four load-readiness flags on (no hand-written transaction, no rollback after a nested begin, no flush in a
foreign transaction, no flush without closed-manager recovery).

| Service | State | Verdict |
|---------|-------|---------|
| `Transaction\TimeTrackTransactionRunner` | `final readonly`: the manager, the registry, the manager name, the Retry-After hint, the backoff base, the logger | Stateless: every unit's bookkeeping (rows before the unit, observer of transaction-doctrine) is local to the call. `reset()` (`kernel.reset`, and the boundary subscriber at every main request, Messenger message and outermost command) replaces only a **shared** manager a failed TimeTrack write closed; an open manager and the private one are left alone |
| `EventSubscriber\StorageFailureSubscriber` | `final readonly`: the client API response factory | Stateless |
| `Doctrine\TimeTrackRows`, `Doctrine\RecoveringFlusher` | none (static helpers) | Stateless; `RecoveringFlusher` no longer resets a manager in the middle of a request |
| `Doctrine\PrivateEntityManagerSubscriber`, `Doctrine\UnitOfWorkBoundaries` | as in 1.23 | Registered in every mode since 1.27.0 (they drive the runner's boundary reset); with the shared manager they hold no TimeTrack state |
| Connection after a failure | the shared DBAL connection | transaction-doctrine rolls back the levels the unit opened and closes the connection only when that rollback fails: the worker's next request never inherits a nested transaction (tests: `TransactionRunnerTest`, `tests/Database`) |

## Re-audit 1.26 (activity monitoring, spec 199)

Scope: `Controller\TimeTrackMonitoringApiController`, `Monitoring\*`, the two monitoring repositories and the purge.

| Service | State | Verdict |
|---------|-------|---------|
| `Controller\TimeTrackMonitoringApiController` | `final readonly`: collaborators, clock, logger | Stateless; the image bytes pass through local variables of the PUT only (streamed in 64 KiB chunks to a temporary file deleted in `finally`, handles closed in `finally`) |
| `Monitoring\MonitoringContext`, `MonitoringGate` | `final readonly`: the host ports and the bundle stores | No memo: the policy, the acknowledgement, the timer, the entry, the calendar and the working hours are read on every call, so no `ResetInterface` is needed |
| `Monitoring\HmacMonitoringUploadSigner` | the configured secret (container parameter) | The key is derived on every call; no static, no cache |
| `Monitoring\MonitoringSettings`, `MonitoringImageInspector` | constants / config scalars | Pure |
| `Repository\DoctrineOrmMonitoringUploadIntentRepository`, `DoctrineOrmMonitoringAcknowledgementStore` | the bundle entity manager | Reads use `HINT_REFRESH` and detach the rows they return; state changes are single conditional DQL statements (`TimeTrackSavepoint`), so no managed row survives a request and two workers never both consume a URL |
| Replays | intent rows (bundle EM); acknowledgement and activity answers in `client_api.rate_limit.cache_pool` | Shared by every worker; never in process memory |
| `Monitoring\MonitoringActivityBudget` | the shared cache pool | App seconds per (user, slot) live in `client_api.rate_limit.cache_pool` (25 h), never in process memory |
| `Monitoring\MonitoringSpanDiscardListener` | the event dispatcher | Stateless: re-dispatches each discarded idle span as `MonitoringSpanDiscardedEvent` |
| `Monitoring\MonitoringTeamViewGuard` (1.41) | the host policy source, the event dispatcher, clock, logger | Stateless: the worker's policy is read on every call; the view record is an event, nothing is kept |
| `MonitoringGate` workday check (1.41) | the host workday provider (slot) | The register's day is read on every call (`todayFor` / `dayFor`); no memo |

Worker test: `MonitoringWorkerModeTest` serves two people with different policies in turn through `http_kernel` handle /
terminate with the services resetter never run: each sees only their own policy and acknowledgement, the same
`Idempotency-Key` of the other person is not a replay, one cannot use the other's upload URL, and 500 `GET me` steps with
the monitoring block keep a flat memory. Since 1.41 `MonitoringPrivacyWorkerModeTest` alternates a worker on a register
break and a working one (activity and the team-view guard) for 375 rounds: no break or policy leaks between them and the
memory stays flat.

## Re-audit 1.25 (host timer API and service accounts)

Scope: `Timer\HostTimerApi`, its value objects, `TimerService::startReplacing()`, the service-account policy port and the
origin filter (Beacon spec 234).

| Service | State | Verdict |
|---------|-------|---------|
| `Timer\HostTimerApi` | `final readonly`: the timer service | Stateless; the actor arrives with every call and the results are built per call |
| `Timer\TimerSnapshot`, `TimerEntrySnapshot`, `HostTimerStart`, `Repository\TimeEntryOriginFilter` | `final readonly` value objects | Built per call, never stored by the bundle |
| `Timer\TimerChannel` | static helper, a constant pattern | Pure |
| `Integration\NoServiceAccountPolicy` / the host's `ServiceAccountPolicyInterface` | none in the default | Asked on every write (timer stop, idle reassign, manual entry, backdated start); the answer lives in a local variable only |
| `TimerService::startReplacing()` | locals only; the retry counter is a loop variable | A failed flush resets the closed manager (`RecoveringFlusher`) before the next attempt |

Audit PA (Info): the `catch (Throwable)` around the service-account policy keeps a failing policy from losing a timer
stop, but it cannot repair an entity manager the policy closed itself — `RecoveringFlusher::flush()` resets a closed
manager and rethrows, and a policy that runs its own failing flush can still break the stop it was called from. The port
documentation forbids that shape (read-only, no flush), which is the only defence available.

Worker test: `HostTimerWorkerModeTest` serves a person and two agents in turn through `http_kernel` handle / terminate
with the services resetter never run; every answer holds only the caller's timer, the person's timer is never stopped by
an agent, a policy answer changed between two requests applies to the next write, and 300 write steps keep a flat
memory.

## Re-audit 1.23 (REQ-ARCH-001 ports and private entity manager)

Scope: the 1.23 port twins and gates (`src/Port/`), `TimeTrackUserRef` and `HiddenValues`, the compiler passes, and the
opt-in private entity manager (`database.private_entity_manager: true`).

| Service | State | Verdict |
|---------|-------|---------|
| `Port\*Gate`, `Port\Ports`, `Port\TimeTrackUserRefs`, `Port\TimeTrackUserRefFactory` | `final readonly` gates over the host service; static helpers without static properties | Stateless; a reference is built per call |
| `ValueObject\TimeTrackUserRef` + `Internal\HiddenValues` | the user object kept in a `WeakMap` keyed by the VO | The entry dies with its per-call VO: nothing outlives the request |
| `Doctrine\TimeTrackEntityManager` (private mode) | the current private manager, a stale flag and the uncommitted transaction levels | Bundle-owned unit of work, dropped by the bundle itself at every outermost boundary (`PrivateEntityManagerSubscriber`: main `kernel.request` start, `kernel.terminate`, Messenger received / handled / failed, outermost `console.command` / `console.terminate`) and at `kernel.reset`; replaced when closed, on another connection or stale after a rollback; pending changes at a boundary are logged (classes and counts) and dropped. Its first private manager is created lazily, the subscriber never builds it |
| `Doctrine\UnitOfWorkBoundaries` | which units of work are running in the process | Describes the running units (deliberately not reset by `kernel.reset`); copied from AuthKitBundle 1.46.0 / PageBuilderKitBundle 1.15.1 |
| `Doctrine\TimeTrackRepositoryFactory` | repositories memoised in a `WeakMap` keyed by the private manager | Gone with the manager at the next boundary |
| `Doctrine\FormerPersonSnapshotListener` | unchanged per-flush state; in private mode it drops the private manager after settling a deleted user's entries | Cleared at the boundaries as in 1.19 |
| `UserRef\HostUserRows`, `UserRef\ExactUserIds`, `DoctrineUserReferenceResolver` (`user_mapping: id`) | `final readonly` / static helpers; the host user row is read or created per call | Stateless; no user is memoised |

- **Entity manager (rule 4 of REQ-RUNTIME-002)**: with the option on, the bundle clears its own manager at every boundary
  and recreates it when a failed write closed it; it never touches the application's managers (the host keeps rule 7 for
  its own). Without the option, 1.22 applies (see *Entity manager* below).
- **Worker test**: `PrivateEntityManagerWorkerTest` runs every `WorkerModeNoResetTest` scenario with the option on (1,300
  requests across two people and the 1,000-step memory check, growth under 64 KB) plus 100 rounds × 2 people of
  start / read / stop, all through the container's `http_kernel->handle()` and `terminate()` (never `Kernel::handle()`,
  whose `boot()` would run the services resetter): after every request the private manager is gone, and a row changed
  between two requests is read fresh.
- **Static checks**: Igor clean, PHPStan with the `phpstan-frankenphp` classic and worker rulesets clean.

## Re-audit 1.22 (client leave, spec 204)

Scope: `client_api.leave_provider` — `ClientUserLeaveProviderInterface`, `ClientUserLeave`, the leave methods of
`ClientWorkingTimeContext`, `GET workday` / `GET me` in the two client controllers, and the `leave` kind of
`ClientDaysOffNormalizer`.

| Service | State | Verdict |
|---------|-------|---------|
| `ClientWorkingTimeContext` | `final readonly`: services, the host leave provider reference, two route names, the logger | Asks the provider on every `GET workday` with the caller's id; the answer lives in local variables only |
| `ClientUserLeave` | `final readonly` value object, one per call | Never stored by the bundle |
| `ClientDaysOffNormalizer` | static helpers, no static properties | Pure |

- **Never memoised**: the leave of one user is never answered to another, and a leave approved or cancelled meanwhile
  applies to the next request. The host adapter must not memoise per user across requests either.
- **Not in the idempotency store**: workday action answers carry no `leave`, so no leave data is cached for 24 h.
- Test: `tests/Functional/LeaveWorkerModeNoResetTest.php` — one kernel (debug off), services resetter never run, 300
  rounds × three people (medical leave, vacation, at work) × `GET workday` + `GET me` = 1,800 requests through
  `http_kernel`; every answer holds only the caller's leave (the medical one always *Absent*), the reminder is dropped
  only for the two on leave, and the port is asked on every request with the caller's id. Igor clean.

## Re-audit 1.20 (runtime calendar OAuth apps)

Scope: `calendar.oauth.app_provider` (Beacon request 2026-11-11) — `CalendarOAuthAppProviderInterface`,
`CalendarOAuthApp`, `CalendarOAuthAppSource` (internal), `CalendarOAuthClients` and the changed Google / Microsoft OAuth
helpers.

| Service | State | Verdict |
|---------|-------|---------|
| `GoogleCalendarOAuthClient`, `MicrosoftCalendarOAuthClient` | `final readonly`: the token endpoint, the scope, the static app of 1.19 (a redacting `CalendarOAuthApp`) and a reference to the host app provider | No per-request state: with a provider, every call (consent URL, code exchange, refresh, revoke, `isConfigured()`) asks it once and keeps the answer in a local variable only |
| `CalendarOAuthAppSource` (internal, one per helper) | `final readonly`: provider enum, static app, host provider reference | Never stores what the provider returned |
| `CalendarOAuthClients` | `final readonly`: the two helpers | Asks them live on each call |

- **Never memoised** across requests or messages: a rotated or removed app applies to the next call. The host
  provider should not memoise either; InstanceSettings' memo lasts one request or one Messenger message.
- **Fail closed:** a null app, an empty client id or secret, or an exception from the provider means "not configured"
  for that call only; nothing is cached, so the provider comes back as soon as the app is restored.
- **No secret in memory between calls** beyond the static configuration of 1.19 (which is not compiled in when a
  provider is set): `CalendarOAuthApp` instances live for one call.
- Test: `tests/Functional/CalendarOAuthAppWorkerTest.php` — one kernel (debug off), services resetter never run, 150
  rounds alternating apps one, apps two and no app (Google and Microsoft, the Microsoft tenant alternating), a request
  through `http_kernel` between rounds; every consent URL, token URL and token request carries the app stored at that
  moment, a removed app hides the provider and sends no token call (Google still revokes by the token alone), and no
  secret reaches the test logger. Igor and PHPStan (`ruleset-worker`) clean.

## Re-audit 1.19 (REQ-RUNTIME-002, kernel reset false)

Scope: everything added since 1.3.3 (client API 1.9 – 1.14, working time and timesheets, imports, former people, task
moves, calendar suggestions and readers, OAuth helpers, the meetings API, the user reference port).

### Stateful services and how each is cleared

| Service | State | Cleared by |
|---------|-------|------------|
| `Doctrine\FormerPersonSnapshotListener` | `$pending`: what one flush of one entity manager settles after its commit (entries loaded for a user being deleted) | Its next `onFlush` of the same entity manager; since 1.19 also `onBoundary()` on `kernel.terminate`, `console.terminate`, Messenger `WorkerMessageHandledEvent` / `WorkerMessageFailedEvent` (registered by the bundle, priority -1024); `kernel.reset` kept as a second line. Tests: `FormerPeopleTest::testTheRequestBoundaryForgetsAFailedFlushWithoutTheServicesResetter`, `::testResetForgetsAFailedFlush`, `TimeTrackExtensionTest::testTheFormerPersonListenerCanBeLeftOffTheHostEntityManager` |
| Every other service, controller, repository, reader and OAuth helper | none: `final readonly` or constructor configuration only (`Bridge\StubTaskProvider` keeps a fixed demo array) | — |

`grep -rnE "static \$|private static|protected static|public static \$" src/` hits only pure `private static`
helper methods (no static properties, no `static` locals). `grep -rnE "\b(exit|die)\s*\(" src/` returns nothing.

### Request data

- The client API resolves the Bearer token, the user (reloaded before role checks) and the scopes per request; nothing
  is kept in a property. `GET me` links, features and limits are computed per call.
- The calendar readers (`CalendarReaderInterface`, 1.19) take the credential as an argument, return in-memory views that
  refuse serialization, and keep no page token, credential or event between calls. The OAuth helpers keep no token,
  state or verifier (the host stores them).
- Idempotency replays and rate-limit counters live in the configured PSR-6 pool (shared between workers), keyed by user
  and token, never in PHP memory (W-02 below).

### Entity manager

The bundle writes through the configured `database.entity_manager` (default `default`, i.e. the host's manager).
`RecoveringFlusher` resets a closed manager
after a failed flush, `ManagedEntityRefresher` reloads users before role checks, and the token owner is refreshed once
per client API request. Since 1.19 `DoctrineUserReferenceResolver` loads a wrapper user's entity with `find()`, which
answers from the identity map first. Since 1.22.1 (audit NL) the timesheet retention purge runs its statements in a
DBAL transaction (a savepoint inside a host transaction) and neither flushes nor clears that manager; it detaches only
the timesheets, audit rows and ledger lines it deleted or marked (`TimesheetPurgeHostManagerTest`).

**Host duty with kernel reset false (audit LZ-I4):** clear the entity manager the bundle uses at the end of each request
(for example `EntityManager::clear()` on `kernel.terminate`), or keep the services resetter. Otherwise a user loaded in
an earlier request and deleted out of band since then still counts as known to the resolver until the manager is
cleared; databases that enforce the foreign keys still refuse the write at flush. Memory is not the issue: the worker
test below shows the hot path keeps no growth with the same people. Since 2.0 the bundle always uses its own private
manager (opt-in since 1.23.0), which it drops itself at every boundary (see [Re-audit 1.23](#re-audit-123-req-arch-001-ports-and-private-entity-manager)):
the host duty then covers only the host's own managers.

### Locks, handles, HTTP

No file handles or locks are held. HTTP calls (calendar readers, OAuth token endpoint) are per call, with timeouts and
no redirects; responses are read in full or cancelled.

### Worker test

`tests/Functional/WorkerModeNoResetTest.php`, run by `make test` and CI:

- one kernel (debug off, like production), requests sent straight through the container's `http_kernel`, so the
  services resetter never runs (the test browser would run it before each request);
- `testNothingLeaksFromOnePersonToTheNextWithoutTheServicesResetter`: two people in turn, 250 rounds of `GET me` and
  `GET timer` each (1,000 requests), then 50 rounds of start / read / stop each (300 requests); every answer holds only
  the caller's id, identifier and timer note;
- `testTheHotReadPathKeepsAFlatMemoryWithoutTheServicesResetter`: 100 warm-up steps, then 1,000 steps (`GET me` +
  `GET timer`, people alternating, 2,000 requests); memory growth must stay under **64 KB** (measured: flat).

In debug mode the framework's own collectors (Doctrine query log, traced event dispatcher) keep every request until
`kernel.reset`; that is why the memory check runs with debug off.

### Static checks

- `composer igor` (REQ-CS-008): runs in CI (`igor` job) and in `make release-check`.
- PHPStan with `nowo-tech/phpstan-frankenphp` `ruleset-classic.neon` + `ruleset-worker.neon` (`phpstan.neon.dist`):
  no errors.

## Execution model assumed

FrankenPHP worker mode boots the Symfony kernel once per worker and serves many requests with the same container. This audit assumes the **strict** variant: the kernel is **not** rebooted between requests, so every shared service, static property and PHP global survives from one request to the next. Two scenarios are evaluated:

- **A — kernel not rebooted, `services_resetter` still runs:** services tagged `kernel.reset` (or implementing `ResetInterface`) are reset between requests.
- **B — no reset at all:** nothing is reset; any per-request state kept in a service leaks into the next request.

A bundle that is safe under **B** is safe under **A** and under classic mode / PHP-FPM.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | All runtime services are `final readonly` or have only constructor config; `StubTaskProvider::$tasks` is fixed demo data set in the constructor |
| Static properties / `static` locals | ✅ | None; only pure static helpers (`UserIdResolver::getId()`, `ClientAuthService::hashToken()`, `Uuid::generate()`) |
| `ResetInterface` / `kernel.reset` coverage | ✅ N/A | Nothing in the bundle needs a reset; security decisions and closed-manager recovery no longer depend on Doctrine's reset (W-01) |
| Request / user / locale captured in services | ✅ | Bearer token resolved per request in `TimeTrackClientApiController`; manage UI uses `getUser()` per request; no user stored in a property |
| Superglobals, `$_ENV`, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None used; config is compiled into container parameters |
| Doctrine / EntityManager | ✅ Resolved / host | Failed flushes reset the closed manager; users are refreshed before role checks; identity-map clearing between requests stays with the host |
| Output, headers, `exit`, shutdown functions | ✅ | None; CORS headers are set on the `Response` object in `ClientResponseFactory` |
| Resources (files, sockets, cURL) held open | ✅ | None |
| Memory growth across requests | ⚠️ Low (host) | Only through the Doctrine identity map under B if the host never clears it, or an in-memory cache pool chosen for the login rate limiter (W-02, accepted) |
| Blocking I/O and timeouts | ✅ | Only Doctrine queries and the cache pool; no HTTP or process calls |
| Third-party static state | ✅ | None |
| PHPStan FrankenPHP rulesets | ✅ | `ruleset-classic.neon` + `ruleset-worker.neon` included in `phpstan.neon.dist` |

## Services reviewed

| Service | Shared | Mutable state | Scenario A | Scenario B |
|---------|--------|---------------|------------|------------|
| `Service\TimerService` | yes | none (`final readonly`) | ✅ | ✅ (repositories recover a closed EM) |
| `Service\ClientAuthService` | yes | none (`final readonly`, TTL from config); refreshes the token owner | ✅ | ✅ |
| `Service\TeamAccessGuard` | yes | none (`final readonly`, roles/flags from config); refreshes the user before admin-role checks | ✅ | ✅ |
| `Client\ClientLoginRateLimiter` | yes | none in PHP; counters live in the configured PSR-6 pool | ✅ | ✅ (see W-02) |
| `Client\ClientResponseFactory` | yes | none (`final readonly`, allowed origins from config) | ✅ | ✅ |
| `Client\DefaultClientAuthenticator` | yes | none (`final readonly`) | ✅ | ✅ |
| `Security\ConfigurableTimeTrackAccessChecker` / `Security\AllowAllTimeTrackAccessChecker` | yes | none | ✅ | ✅ |
| `Bridge\StubTaskProvider` / `Bridge\NullTeamContextProvider` | yes | fixed array built in constructor / none | ✅ | ✅ |
| 3 Doctrine repositories (`DoctrineOrm*Repository`) | yes | none (`final readonly`, EntityManager + optional `ManagerRegistry` injected) | ✅ | ✅ (identity map: host) |
| `Doctrine\RecoveringFlusher`, `Doctrine\ManagedEntityRefresher` | static helpers | none | ✅ | ✅ |
| `Doctrine\TimeTrackMetadataListener` | yes | none; runs only on `loadClassMetadata` | ✅ | ✅ |
| `Routing\TimeTrackRouteLoader` | yes | none (`$loaded` guard removed, W-03) | ✅ | ✅ |
| `Twig\TimeTrackTwigExtension` | yes | none; globals are config values | ✅ | ✅ |
| `Controller\TimeTrackClientApiController`, `Controller\TimeTrackManageController` | yes | none (`readonly` deps) | ✅ | ✅ |
| `Command\PurgeExpiredClientTokensCommand` | CLI only | none | N/A | N/A |

Entities (`ActiveTimer`, `ClientToken`, `TimeEntry`) create `new DateTimeImmutable()` in their own constructors or methods, which is per call and correct. `ClientToken::isExpired()` (`src/Entity/ClientToken.php:79-82`) compares with the current time on every call, so a token cached in the identity map still expires on time.

## Findings

### W-01 — Relies on Doctrine's reset to clear the EntityManager between requests (Medium, scenario B only)

- **Where:** repositories call `persist()` + `flush()` directly (`src/Repository/DoctrineOrmActiveTimerRepository.php:17-27`, `src/Repository/DoctrineOrmClientTokenRepository.php:18-28`, `src/Repository/DoctrineOrmTimeEntryRepository.php:18-22`); the user comes from `ClientToken::getUser()` (`src/Service/ClientAuthService.php:48-60`) and its roles decide admin access in `TeamAccessGuard::hasAdminRole()` (`src/Service/TeamAccessGuard.php:69-72`), used by `canViewUserEntries()` and `canEditEntry()`.
- **Worker impact:** under A, DoctrineBundle's `doctrine` registry (tagged `kernel.reset`) clears the EntityManager after each request, so every request reloads the user and token from the database. Under B nothing is cleared: Doctrine returns the already-managed `User` from the identity map without refreshing its columns, so a user whose admin role was removed (in another worker or by an admin) keeps `admin_roles` rights in `TeamAccessGuard` in that worker, and can still read other users' entries via `GET entries?userId=`. The identity map also grows with every request, and a failed `flush()` (for example the unique constraint on `time_track_active_timers.user_id` hit by two concurrent `timer/start` calls) closes the EntityManager for all later requests in the worker.
- **Recommendation:** keep `services_resetter` enabled (scenario A). If the bundle must support B, clear the EntityManager at the end of each client API call (or on `kernel.terminate`) and reload the user (`refresh()`) before role checks.
- **Status:** Resolved — `src/Service/TeamAccessGuard.php` (new optional `?ManagerRegistry`, injected by `TimeTrackExtension`) calls `Doctrine\ManagedEntityRefresher::refresh()` on the user before reading roles in `hasAdminRole()` (skipped when `admin_roles` is empty); `src/Service/ClientAuthService.php::resolveUser()` refreshes the token owner once per client API request. Token revocation/expiry were already fresh (the token lookup runs in SQL and `isExpired()` uses the current time). New `src/Doctrine/RecoveringFlusher.php` wraps `flush()` in the three repositories and resets the closed manager through `ManagerRegistry::resetManager()` before rethrowing. The bundle does not call `EntityManager::clear()` on the application's manager (since 1.22.1 also not in the timesheet retention purge, audit NL): identity-map growth and freshness of rendered data under B remain the host's responsibility (clear on `kernel.terminate` or keep `services_resetter`). Residual framework responsibility: the manage UI's access checker uses `AuthorizationChecker`, i.e. the roles stored in the security token, which the framework owns. Tests: `TeamAccessGuardTest::testSecondRequestWithoutResetSeesAdminRoleRevokedByAnotherWorker`, `ClientAuthServiceTest::testResolveUserReloadsUserSoSecondRequestSeesChangedRoles`, `tests/Unit/Doctrine/WorkerSafeDoctrineTest.php` (incl. concurrent `timer/start` then a second save on the same repository instance), `TimeTrackExtensionTest::testLoadInjectsManagerRegistryForWorkerSafeDoctrineAccess`.

### W-02 — Login rate limiter depends on a shared, bounded cache pool (Low)

- **Where:** `src/Client/ClientLoginRateLimiter.php:30-62`, pool chosen by `clients.login_rate_limit.cache_pool` (default `cache.app`, `src/DependencyInjection/Configuration.php:169-171`), wired in `src/DependencyInjection/TimeTrackExtension.php:123-129`.
- **Worker impact:** with the default filesystem or Redis pool, counters are shared by all workers and expire after `interval_seconds`, so behaviour is correct. If the host points `cache_pool` to an in-memory pool (`cache.adapter.array`), each worker keeps its own counters (an attacker gets `max_attempts` × number of workers tries) and the array grows for the whole life of the worker.
- **Recommendation:** use a pool shared between workers (Redis, filesystem, or APCu inside one FrankenPHP process). Do not use an array pool in production.
- **Status:** Accepted — the limiter keeps no state in PHP and the default `cache.app` pool is shared; the pool is a host decision. Documented in `docs/UPGRADING.md` (Unreleased) and in the usage recommendations below.

### W-03 — Route loader keeps a `$loaded` flag (Info)

- **Where:** `src/Routing/TimeTrackRouteLoader.php:16`, `:30-36`.
- **Worker impact:** the flag is only used when routes are compiled into the router cache (warmup or first request). The compiled matcher is reused afterwards, so the flag is not reached again in a normal worker. It is never reset, so a second route load in the same process would throw `TimeTrack routes already loaded.`; this can only happen in debug setups that rebuild routes without restarting the worker.
- **Recommendation:** none required; restart workers after route changes in development.
- **Status:** Resolved — guard removed from `src/Routing/TimeTrackRouteLoader.php` (cheap and safe); `load()` builds a fresh collection each call. Test: `TimeTrackRouteLoaderTest::testCanLoadRoutesAgainInTheSameProcess`.

No cross-request data leak was found in the bundle code under scenario A: no service stores the current user, token, request or timer.

## Usage recommendations in worker mode

- Under scenario B the bundle reloads users before role checks and reopens a closed EntityManager itself, but the host must still clear the identity map between requests (e.g. `EntityManager::clear()` on `kernel.terminate`) to bound memory; keeping `services_resetter` (scenario A) does this.
- Use a shared cache pool for `clients.login_rate_limit.cache_pool`.
- Custom `TaskDirectoryInterface`, `TeamDirectoryInterface`, `ClientAuthenticatorInterface` or `TimeTrackAccessCheckerInterface` implementations must stay stateless (or implement `ResetInterface`); never cache the current user, the managed user ids or task lists per user in a property without keying and bounding them.
- Listeners on `TimeTrackEvents::TIME_ENTRY_LIST_QUERY` / `TIME_ENTRY_ACCESS_CHECK` receive the viewer in the event; they must not keep it after the event.
- The demo (`demo/symfony8`) runs in worker mode by default (`FRANKENPHP_MODE=worker`, `Caddyfile` has a `worker` block).

## Re-audit triggers

Re-run this audit when a change adds: properties to services, controllers or repositories, a cache of users, tokens, timers or tasks, a real task provider bridge with in-memory state, HTTP calls to an external task board, or any use of `$_SERVER` / `$_ENV` at runtime.
