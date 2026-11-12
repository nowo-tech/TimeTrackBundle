# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/time-track-bundle` (`symfony-bundle`) |
| Current audit | **1.22.0** (`feat/leave-spec-204`), 2026-11-12, client leave (spec 204) — see [Re-audit 1.22](#re-audit-122-client-leave-spec-204); **1.20.0** (`feat/calendar-oauth-app-provider`), 2026-11-11, runtime OAuth apps — see [Re-audit 1.20](#re-audit-120-runtime-calendar-oauth-apps); **1.19.0** (`feat/1.19-block1`), 2026-11-11, against REQ-RUNTIME-002 (kernel reset false) — see [Re-audit 1.19](#re-audit-119-req-runtime-002-kernel-reset-false) |
| First audit | `v1.3.3` / `d90eed2`, 2026-09-23 (findings W-01 … W-03 below) |
| Method | Manual review of every service, listener, command and controller under `src/` (grep for `static`, mutable properties, `ResetInterface`, `exit` / `die`), Igor (`composer igor`), PHPStan with `nowo-tech/phpstan-frankenphp` `ruleset-classic` + `ruleset-worker`, and the many-request worker test |
| **Verdict** | ✅ **Works with `FRANKENPHP_RESET_KERNEL=false`** — no service keeps request, user or tenant data; the one per-flush state is cleared by the bundle at every request, message and command boundary; the worker test passes with one kernel and two people in turn |

## Table of contents

- [Re-audit 1.22 (client leave, spec 204)](#re-audit-122-client-leave-spec-204)
- [Re-audit 1.20 (runtime calendar OAuth apps)](#re-audit-120-runtime-calendar-oauth-apps)
- [Re-audit 1.19 (REQ-RUNTIME-002, kernel reset false)](#re-audit-119-req-runtime-002-kernel-reset-false)
- [Execution model assumed](#execution-model-assumed)
- [Summary](#summary)
- [Services reviewed](#services-reviewed)
- [Findings](#findings)
  - [W-01 — Relies on Doctrine's reset to clear the EntityManager between requests (Medium, scenario B only)](#w-01--relies-on-doctrines-reset-to-clear-the-entitymanager-between-requests-medium-scenario-b-only)
  - [W-02 — Login rate limiter depends on a shared, bounded cache pool (Low)](#w-02--login-rate-limiter-depends-on-a-shared-bounded-cache-pool-low)
  - [W-03 — Route loader keeps a `$loaded` flag (Info)](#w-03--route-loader-keeps-a-loaded-flag-info)
- [Usage recommendations in worker mode](#usage-recommendations-in-worker-mode)
- [Re-audit triggers](#re-audit-triggers)

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
test below shows the hot path keeps no growth with the same people. 1.22.0 still writes through the host's `default`
manager. A bundle-owned entity manager (REQ-ARCH-001 rule 6), which would let the bundle clear its own manager itself,
is part of the XL REQ-ARCH-001 track (with the `UserInterface` ports and the XML mappings) and has no target release
yet; until then this host duty applies (REQ-RUNTIME-002 rule 7).

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
- Custom `TaskProviderInterface`, `TeamContextProviderInterface`, `ClientAuthenticatorInterface` or `TimeTrackAccessCheckerInterface` implementations must stay stateless (or implement `ResetInterface`); never cache the current user, the managed user ids or task lists per user in a property without keying and bounding them.
- Listeners on `TimeTrackEvents::TIME_ENTRY_LIST_QUERY` / `TIME_ENTRY_ACCESS_CHECK` receive the viewer in the event; they must not keep it after the event.
- The demo (`demo/symfony8`) runs in worker mode by default (`FRANKENPHP_MODE=worker`, `Caddyfile` has a `worker` block).

## Re-audit triggers

Re-run this audit when a change adds: properties to services, controllers or repositories, a cache of users, tokens, timers or tasks, a real task provider bridge with in-memory state, HTTP calls to an external task board, or any use of `$_SERVER` / `$_ENV` at runtime.
