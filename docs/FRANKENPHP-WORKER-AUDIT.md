# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/time-track-bundle` (`symfony-bundle`) |
| Audited revision | `v1.3.3` / `d90eed2` |
| Audit date | 2026-09-23 |
| Method | Manual review of every file under `src/` (services, controllers, client API helpers, repositories, Doctrine listener, route loader, Twig extension, command, DI extension, compiler pass, `Resources/config/services.yaml`); entities, DTOs, events and value objects skimmed |
| Remediation (2026-09-23) | W-01 resolved (user refreshed before role checks, closed EntityManager reset after failed flushes via `Doctrine\ManagedEntityRefresher` / `Doctrine\RecoveringFlusher`); W-02 accepted (host-chosen pool, documented); W-03 resolved (route loader guard removed); regression tests simulate consecutive requests without `reset()` |
| **Verdict** | ✅ **Viable under scenario B** — no bundle service keeps per-request state, role decisions reload the user from the database, and a failed flush no longer leaves the worker on a closed EntityManager. Clearing the identity map between requests (memory, freshness of rendered data) remains the application's responsibility |

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
- **Status:** Resolved — `src/Service/TeamAccessGuard.php` (new optional `?ManagerRegistry`, injected by `TimeTrackExtension`) calls `Doctrine\ManagedEntityRefresher::refresh()` on the user before reading roles in `hasAdminRole()` (skipped when `admin_roles` is empty); `src/Service/ClientAuthService.php::resolveUser()` refreshes the token owner once per client API request. Token revocation/expiry were already fresh (the token lookup runs in SQL and `isExpired()` uses the current time). New `src/Doctrine/RecoveringFlusher.php` wraps `flush()` in the three repositories and resets the closed manager through `ManagerRegistry::resetManager()` before rethrowing. The bundle does not call `EntityManager::clear()` on the application's manager: identity-map growth and freshness of rendered data under B remain the host's responsibility (clear on `kernel.terminate` or keep `services_resetter`). Residual framework responsibility: the manage UI's access checker uses `AuthorizationChecker`, i.e. the roles stored in the security token, which the framework owns. Tests: `TeamAccessGuardTest::testSecondRequestWithoutResetSeesAdminRoleRevokedByAnotherWorker`, `ClientAuthServiceTest::testResolveUserReloadsUserSoSecondRequestSeesChangedRoles`, `tests/Unit/Doctrine/WorkerSafeDoctrineTest.php` (incl. concurrent `timer/start` then a second save on the same repository instance), `TimeTrackExtensionTest::testLoadInjectsManagerRegistryForWorkerSafeDoctrineAccess`.

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
