# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.9.0] - 2026-11-02](#190---2026-11-02)
- [[1.8.0] - 2026-11-02](#180---2026-11-02)
- [[1.7.0] - 2026-10-30](#170---2026-10-30)
- [[1.6.0] - 2026-10-26](#160---2026-10-26)
- [[1.5.2] - 2026-10-18](#152---2026-10-18)
- [[1.5.1] - 2026-10-13](#151---2026-10-13)
- [[1.5.0] - 2026-10-13](#150---2026-10-13)
- [[1.4.0] - 2026-10-13](#140---2026-10-13)
- [[1.3.7] - 2026-10-13](#137---2026-10-13)
- [[1.3.6] - 2026-10-09](#136---2026-10-09)
- [[1.3.5] - 2026-09-28](#135---2026-09-28)
- [[1.3.4] - 2026-09-27](#134---2026-09-27)
- [[1.3.3] - 2026-08-24](#133---2026-08-24)
- [[1.3.2] - 2026-08-19](#132---2026-08-19)
- [[1.3.1] - 2026-08-18](#131---2026-08-18)
- [[1.3.0] - 2026-08-04](#130---2026-08-04)
- [[1.2.0] - 2026-08-03](#120---2026-08-03)
- [[1.1.0] - 2026-07-30](#110---2026-07-30)
- [[1.0.6] - 2026-07-29](#106---2026-07-29)
- [[1.0.5] - 2026-07-16](#105---2026-07-16)
- [[1.0.4] - 2026-07-13](#104---2026-07-13)
- [[1.0.3] - 2026-07-08](#103---2026-07-08)
- [[1.0.2] - 2026-07-07](#102---2026-07-07)
- [[1.0.1] - 2026-07-06](#101---2026-07-06)
- [[1.0.0] - 2026-07-06](#100---2026-07-06)

## [Unreleased]

## [1.9.0] - 2026-11-02

Client API for the browser extension and desktop app: scoped tokens linked to host credentials, device flow, per-request credential and task checks, stateless Bearer endpoints with exact-origin CORS, rate limits per token / IP (/64) / username, timer switch / discard-idle / export, audit events. See [UPGRADING](https://github.com/nowo-tech/TimeTrackBundle/blob/v1.9.0/docs/UPGRADING.md#to-190).

Client API 1.9 (contract v0.2) for the extension and the desktop app. Schema change: see
[UPGRADING](UPGRADING.md#to-190).

### Added

- Session tokens linked to a host credential: `ClientToken::credentialId`, `scopes`, `getIssuedAt()`;
  `ClientAuthResult::success($user, ?$credentialId, $scopes)` (new arguments optional).
- Scopes `timer:read`, `timer:write`, `tasks:read`, `entries:read` (`Enum\ClientScope`), checked per endpoint
  (`403 insufficient_scope`).
- Host interfaces: `ClientCredentialStateInterface::isActive(ClientToken)` (per request),
  `ClientCredentialIssuerInterface::issueForDevice()`, `TaskAccessCheckerInterface::canTrack()` (per start / switch, deny
  by default, `403 task_forbidden` for missing and foreign tasks), `ClientUserProfileProviderInterface`,
  `ClientApiSettingsProviderInterface`, `ClientDistributionProviderInterface` — each with a config default
  (`client_api.*`, `client_distribution.*`).
- `ClientTokenRevoker::revokeByCredential()` (public service) and `CredentialClientTokenRepositoryInterface`.
- Endpoints `timer/switch`, `timer/discard-idle` (keep / discard / split), `export` (CSV / JSON, own data), and the
  device flow `device/code`, `device/token` (opt-in `client_api.device_flow.enabled`) with `DeviceAuthorizationService`,
  hashed single-use codes (`DeviceAuthorization`, table `{prefix}_device_codes`), and an optional default verification
  page (`routes.device_verify`, UiKit form / show scaffolds, CSRF bound to action + request).
- Per-IP and per-token rate limits (`client_api.rate_limit.*`, host cache pool), `429 rate_limited` + `Retry-After`.
- Audit event `nowo_time_track.client_api` (`ClientApiEvent`: user id, credential id, client type, IP /24 or /64, reason;
  never the token), denials throttled per credential.
- `GET me` adds `displayName`, `scopes`, `workingHours`; `login` adds `scopes`; `entries` adds `limit` (≤ 100) / `offset`.
- Downloads partial `@NowoTimeTrackBundle/client_distribution/_downloads.html.twig` and Twig functions
  `nowo_time_track_client_distribution()`, `nowo_time_track_client_download_items()`, `nowo_time_track_label()`.
- Docs: [CLIENT-API](CLIENT-API.md) reference, host integration in USAGE, API threat model in SECURITY. Spec
  `specs/002-client-api`. Translations (7 locales) for the device page and the downloads partial.

### Changed

- Client API errors are `{error: code, message}`; stateless on every answer (kernel test asserts no `Set-Cookie` and no
  session start, also on `401` / `429`).
- CORS: exact origins only (`client_api.allowed_origins` + `clients.cors_allowed_origins`); the 1.x `tauri://` prefix
  rule became the exact Tauri webview origins.
- `GET tasks` `limit` capped at 50; `GET entries` returns own entries unless the deprecated `userId` is given.
- `PurgeExpiredClientTokensCommand` also purges expired device requests.

### Deprecated

- `POST heartbeat` (`isIdle` ignored, `Deprecation: true` header), `entries?userId=`,
  `clients.cors_allow_any_extension_origin`, `*` origins, `client_api.allow_tauri_webview_origins` — removed in 2.0.

### Security

- Free client `metadata` on `timer/start` rejected by default (`client_api.allow_metadata`), no server-side idle signal.
- Export CSV neutralises spreadsheet formulas.
- Security audit (Appendix CL) fixes before the release:
  - Scopes fail closed: only an absent list grants every scope. `device/code` answers `400 invalid_scope` and
    `ClientAuthResult::success()` throws `InvalidArgumentException` for an empty list or an unknown scope
    (`ClientScope::fromRequested()`); stored lists are never widened. Tokens without stored scopes (before 1.9) keep
    every scope.
  - Every per-IP limit (API, device flow, client login) keys an IPv6 client by its /64 (`IpPrefix::rateLimitKey()`);
    IPv4-mapped IPv6 addresses (`::ffff:a.b.c.d`) count as their IPv4 address.
  - `device/code` capped per IPv4 /24 or IPv6 /64 (`client_api.device_flow.max_pending_per_prefix`, default 20) and in
    total (`client_api.device_flow.max_pending`, default 10 000) → `429`; `DeviceFlowCapacityExceededException`,
    `DeviceAuthorizationRepositoryInterface::countUnexpired()`.
  - Client login throttled per username across addresses (`clients.login_rate_limit.max_attempts_per_username`,
    default 5 × `max_attempts`); usernames are NFKC-normalised when ext-intl is available.
  - The default verification page sends `X-Frame-Options: DENY`, CSP `frame-ancestors 'none'` and
    `Cache-Control: no-store`, and shows the request time and the requesting network
    (`DeviceAuthorization::getIpPrefix()`, column `ip_prefix`).

## [1.8.0] - 2026-11-02

REQ-100 release: timer and reports pages on the UiKit page contract (layout_template / web_ui.templates.*, 1.x layouts wrapped with a deprecation), PSR clock and logger, paginated reports, 100% coverage without exclusions, demo e2e. Requires nowo-tech/ui-kit-bundle ^2.20. See [UPGRADING](https://github.com/nowo-tech/TimeTrackBundle/blob/v1.8.0/docs/UPGRADING.md#to-180).

### Added

- Reports list pagination (REQ-PERF-001): `nowo_time_track.reports.page_size` (default 50, 1–500), `page` query
  parameter, `TimerService::listEntriesPage()` returning `Dto\TimeEntryPage`; the Doctrine repository pages in the
  database through the new optional `Repository\PaginatedTimeEntryRepositoryInterface`. The reports template receives
  `entry_page`; the page renders the UiKit pagination.
- PSR-3 logging (REQ-OBS-001) on the Monolog channel `time_track`: timer start / stop, client login success / failure /
  throttling, token revocation and the expired-token purge. Records carry the action and the client type only — no
  user, task, duration, token or IP (time entries are personal data). See [SECURITY](SECURITY.md#logging).
- UiKit page contract (REQ-UI-003): `Nowo\TimeTrackBundle\Ui\TimeTrackPages` builds the UiKit view models
  (`ListPage`, `PageHeader`, `Action`, `Pagination`, `EmptyState`) of the timer and reports pages; both templates
  extend `@NowoUiKit/page/list.html.twig` and render inside `nowo_ui_kit.layouts.admin`. New options
  `web_ui.layout_template` (null = UiKit admin layout) and `web_ui.templates.index|reports`. Page contract in
  [TEMPLATES](TEMPLATES.md), enforced by `tests/Integration/Contract/PageContractTest.php` (UiKit
  `PageContractTestCase`, strict missing-translation rule).
- Demo (REQ-DEMO-013): Playwright e2e (`make -C demo/symfony8 test-e2e`: timer page, start / stop, reports) and README
  screenshots `docs/images/demo/timer.webp`, `reports.webp` (`make -C demo/symfony8 demo-screenshots`). The demo
  pages render inside a one-file UiKit bridge (`templates/layouts/admin.html.twig`); the demo is bound to 127.0.0.1;
  demo time entries (`TimeTrackFixtures`) and a migration for the TaskBoardBundle 1.20 schema (demo lock: UiKit 2.21,
  TaskBoard 1.20, FormKit 2.17).
- `make assets` (no-op) and `make validate-phpdoc`; demo targets `restart`, `ensure-up`, `setup`, `test-coverage`,
  `verify`, `release-check` (runs `update-bundle` before `verify`).

### Changed

- **Manage UI markup (REQ-UI-001 / REQ-UI-003):** the timer and reports pages no longer ship local `card` / `btn` /
  `flash` markup or inline styles: page header, actions, table, empty state, flashes and pagination come from the UiKit
  scaffold and theme slots (`nowo_ui_kit.css_framework`). The running timer is shown in the page header (intro
  `Running: %task% (since %started%)`, status badge, Stop action); Start is a row action; durations read
  `%minutes% min`; the
  entry source is translated. Requires `nowo-tech/ui-kit-bundle` `^2.20` (was `^1.4 || ^2.2`).
- Translation keys added: `timer.running_intro`, `timer.status_running`, `tasks.task`, `tasks.board`, `tasks.actions`,
  `reports.period`, `reports.minutes`, `reports.source_web|extension|desktop` (7 locales).

### Deprecated

- `nowo_time_track.templates.layout`, `.css_framework`, `.index`, `.reports` (removed in 2.0): use
  `web_ui.layout_template`, `nowo_ui_kit.css_framework`, `web_ui.templates.index|reports`. They keep working (mapped
  onto `web_ui.*`); a 1.x layout without the UiKit canonical blocks is wrapped in the deprecated
  `_legacy_layout.html.twig` bridge, and `layout.html.twig` is now a shim that renders 1.x templates extending
  `nowo_time_track_layout` inside the UiKit admin layout. See [UPGRADING](UPGRADING.md#unreleased).

- Time comes from an injected `Psr\Clock\ClockInterface` (REQ-DI-001) in `TimerService`, `ClientAuthService`, the
  client API and manage controllers and `DoctrineOrmClientTokenRepository` (optional trailing constructor arguments,
  default: the system clock). Entities accept an optional time argument (`ActiveTimer::__construct(..., $startedAt)`,
  `heartbeat(..., $at)`, `TimeEntry` / `ClientToken` `$createdAt`, `ClientToken::isExpired($now)` / `touch($at)`).
- New runtime requirements: `psr/clock`, `psr/log`, `symfony/clock` (already installed by FrameworkBundle).
- The demo `make up` ends with the canonical `Demo started at: http://localhost:<PORT>` line (the timer page URL is
  printed on the line before it).

### Tests

- REQ-TEST-003: the `src/Controller`, `src/Repository` and `src/Doctrine` coverage exclusions are gone. A functional
  kernel (`tests/Support/FunctionalKernel.php`: Doctrine on in-memory SQLite, SecurityBundle entity provider, FormKit,
  UiKit, the route loader) covers the client API end to end (preflight / CORS, Bearer auth, login validation and
  throttling, timer lifecycle, 409 conflict, entries ACL, logout), the manage UI (start / stop forms, flashes, CSRF,
  access contract, `allow_unauthenticated`, paginated reports) and the Doctrine repositories / metadata listener.
  Only pure interfaces stay excluded.

### Documentation

- USAGE: overridable template subpaths, override procedure and freeze rule (REQ-TWIG-001); translation override
  procedure (REQ-I18N-001). CONFIGURATION: routes and the documented route-loader exemption (REQ-SF-004), reports
  pagination. SECURITY: secrets & cryptography, logging, dependency and updates. DEMO-FRANKENPHP: production start and
  troubleshooting. PHPDoc on every public class and method (REQ-CS-001).

- [ROADMAP](ROADMAP.md): planned browser extension (MV3) and Tauri desktop app with a scoped token API, compliant by
  design with RGPD / LOPDGDD (working-time records, not monitoring); target 1.8 / 2.0.

## [1.7.0] - 2026-10-30

The manage UI form is a FormKit Type (REQ-TWIG-005 rule 11). See [UPGRADING](UPGRADING.md#to-170).

### Changed

- **FormKit is a required dependency** (`nowo-tech/form-kit-bundle` `^2.14`). `TimerActionType` carries
  `#[FormKitConfig('time_track')]` + `FormOptionsTrait` and adds its fields through FormKit's option merger.
- **FormKit profile `nowo_form_kit.profiles.time_track`** (REQ-UI-003 "Form theme" row), prepended by the extension when
  FormKitBundle is registered and the host does not define it: translation domain `NowoTimeTrackBundle`, no classes, no
  convention label / placeholder / help keys — the form renders exactly as in 1.6 (same flat `_token` / `action` /
  `task_id` names and ids). When the host has no `nowo_form_kit` config at all, FormKit's implicit `default` profile
  stays the default one. Without FormKitBundle in the kernel the form keeps its explicit options.

## [1.6.0] - 2026-10-26

Forms in the bundle views are Symfony Form Types rendered with form_start / child loop / form_end, no raw form controls (REQ-TWIG-005). See [UPGRADING](UPGRADING.md#to-160).

### Added

- `make check-no-raw-html-form` (`.scripts/check-no-raw-html-form.sh`) fails on raw `<form>` / `<input>` / `<textarea>` / `<select>` in bundle views and demo templates, and runs in `release-check` (REQ-TWIG-005).

### Changed

- Manage timer page: start/stop buttons are rendered through the new `TimerActionType` Symfony Form (`form_start` / child loop / `form_end`) instead of a raw `<form>` with hand-rolled `_token` / `action` / `task_id` inputs; the controller validates with `handleRequest` + `isSubmitted()` / `isValid()` and always passes `stop_form` / `start_forms`. Same URL, field names and CSRF id (REQ-TWIG-005). Requires `symfony/form`.

- Developer tooling: `make release-check` runs the full REQ-MAKE-002 chain with `ensure-up` first, then the Cursor co-author, open-PR and Twig `extra` gates, composer-sync, the new `cs-fix` step, cs-check, rector-dry, phpstan, igor, translations, coverage-check and the demo release-check.
- Dependencies refreshed (Composer and pnpm/npm lock files of the bundle and its demos, campaign 2026-10-19).
- `.php-cs-fixer.dist.php`: `new ParallelConfig(2)` (REQ-CS-001).
- Documentation: wave-1 REQ compliance — README documentation list order (extra docs under Additional documentation), CONTRIBUTING Cursor Agent settings and post-release-commit trailer check, canonical issue templates, `demo/README.md`, demo `.env.test` / `.gitignore` cleanup, SPEC-DRIVEN-DEVELOPMENT relationship to Engram, doc TOCs, Spec Kit inventory refresh (60/60).
- Internal: applied the pending Rector suggestions (`DefaultClientAuthenticator` private static helper made non-static, `RecoveringFlusher` null check as `instanceof`); no behaviour change. `make rector-dry` is clean again.

## [1.5.2] - 2026-10-18

Allows nowo-tech/ui-kit-bundle 2.x (^1.4 || ^2.2) so hosts can move to UiKit 2.2. See [UPGRADING](UPGRADING.md#to-152).

### Changed

- Allows nowo-tech/ui-kit-bundle 2.x (`^1.4 || ^2.2`); the dev lock uses 2.2.0. Templates are unchanged (compatibility release). The demo lock stays on UiKit 1.x until a TaskBoardBundle release that allows UiKit 2.x is published (the demo requires it). With UiKit 2.x `nowo-ui.css` ships in `@layer nowo-ui`, so unlayered host/framework CSS wins equal-specificity conflicts with kit rules; to keep the 1.x cascade, override the page `stylesheets` blocks and link `css/nowo-ui.unlayered.css` (UiKit 2.x only).

## [1.5.1] - 2026-10-13

### Changed

- `services.yaml` no longer scans `Bridge/`: `StubTaskProvider` and `NullTeamContextProvider` are registered only by `TimeTrackExtension` when selected as `task_provider` / `team_context_provider`, instead of having the explicit definitions overwritten by the resource scan. With custom providers configured, the unused Bridge defaults are no longer registered as (removed) private services. A DI test asserts the final definitions and aliases.

## [1.5.0] - 2026-10-13

### Security

- Client login throttle: new IP-only bucket (`clients.login_rate_limit.max_attempts_per_ip`, default 5 × `max_attempts`, like Symfony's `DefaultLoginRateLimiter`) blocks an IP that rotates usernames; a successful login no longer clears that budget. Usernames are trimmed + lower-cased. Both counters are read with one `getItems()` and committed together (PSR-6 has no atomic increment; documented).
- Client API CORS: `Access-Control-Allow-Credentials: true` is no longer sent (the API uses Bearer tokens, not cookies), and arbitrary `chrome-extension://` / `moz-extension://` origins are no longer allowed by default. List your extension in `clients.cors_allowed_origins` or opt in with `clients.cors_allow_any_extension_origin: true`. `tauri://` stays allowed.

### Deprecated

- `security.manager_can_edit_entries`: the bundle ships no time-entry edit route, so the option only affects `TeamAccessGuard::canEditEntry()` called from host code. Setting it triggers a deprecation; use a `nowo_time_track.time_entry.access_check` listener.

### Fixed

- 100% coverage gate: `TimeTrackExtension::prepend()` UiKit defaults are now tested (unreachable `is_array()` guard removed).

## [1.4.0] - 2026-10-13

### Security

- Manage `reports` page: invalid `from` / `to` query values no longer reach the lenient `DateTimeImmutable` parser (HTTP 500); only ATOM or `Y-m-d` are accepted, anything else falls back to the defaults.
- Client API: `tasks` `limit` is clamped to 1–100 (`TaskListQuery::MAX_LIMIT`, new `TaskListQuery::bounded()`), and the `from`/`to` span of `entries` and `reports` is capped at 366 days.
- `DefaultClientAuthenticator` verifies a dummy bcrypt hash when the username is unknown, so login response time no longer reveals whether an account exists.

### Fixed

- Two concurrent timer starts for the same user now return `ActiveTimerConflictException` (HTTP 409 in the client API) instead of a unique-constraint error (new `ActiveTimerConflictException::concurrentStart()`).
- Null bytes in `from` / `to` no longer raise a `ValueError` in the client `entries` API.
- Manage UI flash messages are translated (`flash.*` keys in the `NowoTimeTrackBundle` domain, 7 locales).

## [1.3.7] - 2026-10-13

### Changed

- Development: `composer.json` pins `config.platform.php` to 8.2.0 so the committed lock stays installable on the minimum PHP; CI overrides the platform per matrix cell.
- Repository: `.twig-cs-fixer.cache` is now git-ignored.

### Dependencies

- `nowo-tech/ui-kit-bundle` 1.9.2 → 1.11.0 (development lock; #39).
- `doctrine/orm` 3.7.4 → 3.7.5 (development lock; #40).

## [1.3.6] - 2026-10-09

### Dependencies

- Dependabot: `doctrine/orm` 3.7.3, `nowo-tech/ui-kit-bundle` 1.8.5, `igor-php/igor-php` `^0.10.0` (dev), `friendsofphp/php-cs-fixer` 3.95.27, phpstan group, `nowo-tech/phpstan-frankenphp`.
- Composer refresh: `nowo-tech/ui-kit-bundle` 1.9.2, `doctrine/orm` 3.7.4, Symfony 7.4.20 components (lockfile); dev tooling `phpstan/phpstan` 2.3.1, `phpstan/phpstan-symfony` 2.1.0, `rector/rector` 2.7.0.
- Demo: Symfony 8.1.8, `doctrine/orm` 3.7.4, `doctrine/dbal` 4.5.0, `nowo-tech/task-board-bundle` 1.5.8, `twig/twig` 3.30.0; regenerated `config/reference.php`.

## [1.3.5] - 2026-09-28

### Security

- Empty `security.access_roles` is fail-closed (deny) unless `allow_unauthenticated` or a custom `access_checker` is set.

### Changed

- **Doctrine ORM SortDirection:** replace string `'ASC'`/`'DESC'` in `#[ORM\OrderBy]` and QueryBuilder `orderBy`/`addOrderBy` with `SortDirection::Ascending`/`Descending` (doctrine/orm deprecation, https://github.com/doctrine/orm/issues/11313); require `doctrine/orm` `^3.7` where applicable.

## [1.3.4] - 2026-09-27

### Added

- **REQ-CS-008:** `igor-php/igor-php` (require-dev only), root `igor.json`, Composer/`Makefile` `igor` target, and `release-check` wiring for FrankenPHP worker-state audit.

### Changed

- **Worker safety (Igor):** justified `// @igor-ignore` annotations and/or `ResetInterface` / request-scoped fixes so `make igor` passes on package `src/`.

### Fixed

- **FrankenPHP worker mode (no kernel reset):** `TeamAccessGuard` refreshes the user from the database before checking `admin_roles`, and `ClientAuthService::resolveUser()` refreshes the token owner, so a role revoked in another worker is honoured on the next request even when the identity map was not cleared (`Doctrine\ManagedEntityRefresher`).
- Repositories reset a closed EntityManager through `ManagerRegistry` when `flush()` fails (e.g. two concurrent `timer/start` calls hitting the unique active-timer index) before rethrowing, so the worker does not stay on a closed manager (`Doctrine\RecoveringFlusher`).
- `TimeTrackRouteLoader` can be loaded more than once per process (removed the "routes already loaded" guard).
- **Docs:** `docs/FRANKENPHP-WORKER-AUDIT.md` updated with the remediation.

### Notes

- New optional `?ManagerRegistry` constructor argument on `TeamAccessGuard`, `ClientAuthService` and the three Doctrine repositories; wired automatically. No configuration changes.

[1.3.5]: https://github.com/nowo-tech/TimeTrackBundle/releases/tag/v1.3.5
[1.3.4]: https://github.com/nowo-tech/TimeTrackBundle/releases/tag/v1.3.4

## [1.3.3] - 2026-08-24

### Changed

- **Demos:** MySQL env policy in FrankenPHP stack (REQ-DEMO-011).
- **QA:** add `phpstan-frankenphp` extension (REQ-CS-005).
- **Docs:** PHP-FIG PSR evaluation (REQ-CS-007).

### Notes

- **No API or configuration changes** for integrators unless noted above.

[1.3.3]: https://github.com/nowo-tech/TimeTrackBundle/releases/tag/v1.3.3

## [1.3.2] - 2026-08-19

### Changed

- **Maintainer:** demo reference PHP CS Fixer alignment.

[1.3.2]: https://github.com/nowo-tech/TimeTrackBundle/releases/tag/v1.3.2

## [1.3.1] - 2026-08-18

### Changed

- **Demos:** pin `nowo-tech/hot-reload-bundle` to `^1.4` with FrankenPHP Mercure/`hot_reload` (`dev`/`test` only).

[1.3.1]: https://github.com/nowo-tech/TimeTrackBundle/releases/tag/v1.3.1

## [1.3.0] - 2026-08-04

### Added
- **REQ-TWIG-004:** require `twig/extra-bundle` + `twig/string-extra`; `make check-twig-extra` in `release-check`; demos register `TwigExtraBundle`.
- **Twig-CS-Fixer:** `vincentlanglet/twig-cs-fixer`, `.twig-cs-fixer.php`, `composer twig:lint` / `twig:fix`.

### Changed

- **REQ-UI-001-kit:** Requires `nowo-tech/ui-kit-bundle` `^1.4`. Layout/pages load `asset('css/nowo-ui.css', 'nowo_ui_kit')` and import `@NowoUiKitBundle/macros/ui.html.twig`. Extension seeds `nowo_ui_kit` from `templates.css_framework` when the host has not configured UiKit. Demo registers `NowoUiKitBundle` + `nowo_ui_kit.yaml`.

[1.3.0]: https://github.com/nowo-tech/TimeTrackBundle/releases/tag/v1.3.0

## [1.2.0] - 2026-08-03

### Added

- **REQ-UI-002** — `security.access_checker`, `security.allow_unauthenticated` (default `false`), and `TimeTrackAccessCheckerInterface` (`ConfigurableTimeTrackAccessChecker` / `AllowAllTimeTrackAccessChecker`).
- Compile-time guard: when `allow_unauthenticated` is `false`, `symfony/security-bundle` is required (`LogicException` otherwise).
- Manage controller enforces the access checker instead of a hard-coded `#[IsGranted('ROLE_USER')]`.

### Changed

- Demo Composer: TaskBoard / TipTap / TagInput / TwigInspector from Packagist (no sibling path mounts).
- Docs: CONFIGURATION / SECURITY document the dual-layer manage ACL.
- Dev deps: `friendsofphp/php-cs-fixer` 3.95.18, `rector/rector` 2.5.9.

### Compatibility

- PHP `>=8.2`, `<8.6`; Symfony `^7.4 || ^8.0`.
- Manage UI with default security settings requires **SecurityBundle** (or set `allow_unauthenticated: true` for trusted local demos).

## [1.1.0] - 2026-07-30

### Added

- **REQ-UI-001 `templates.css_framework`** — default `tabler`; Twig global `nowo_time_track_css_framework`. Validated enum (`bootstrap` / `bootstrap4` / `bootstrap5` / `tabler` / `tailwind` / `foundation` / `custom` / `none`). Documented in CONFIGURATION.md / USAGE.md / UPGRADING.md.
- **REQ-UI-001 layout wiring** — `templates.layout` → Twig global `nowo_time_track_layout` via `TimeTrackTwigExtension`; pages `{% extends %}` config and stack assets with `{{ parent() }}`.

### Changed

- Dev / CI dependency bumps (Dependabot): php-cs-fixer, Rector, PHPStan group, `nowo-tech/phpstan-frankenphp`, `actions/checkout` v7, `actions/stale` v11.
- Demo Symfony 8 `reference.php` refresh for new config keys.

## [1.0.6] - 2026-07-29

### Security

- Manage UI POST start/stop require CSRF (`nowo_time_track_manage`); index route accepts GET+POST.
- `docs/SECURITY.md` — 12.4.1 checklist + SEC-004 row; CSRF control documented.

### Added

- `make check-open-prs`, `coverage-check`, `demo-smoke`, `validate-translations`, `down-dev`.
- `SYMFONY_DEPRECATIONS_HELPER=max[direct]=0` (REQ-SF-005).
- FrankenPHP Friendly banner + `docs/DEMO-FRANKENPHP.md` (REQ-DOCS-017 / DEMO-002).
- Spec inventory locales de/fr/it/nl/pt; PHPStan `ignoreErrors: []` (baseline removed).
- **REQ-CS-005:** `nowo-tech/phpstan-frankenphp` in `require-dev` with classic + worker rulesets.
- GitHub automation: `dependabot.yml`, `pr-lint.yml`, `stale.yml`, `copilot-instructions.md`.
- `ClientAuthResult::requireUser()` for typed success path.

### Changed

- Demo image `frankenphp:1-php8.5`; CI matrix Symfony **7.4 / 8.0 / 8.1** (dropped 7.0).

## [1.0.5] - 2026-07-16

### Added

- **Translations** — UI locales **de**, **fr**, **it**, **nl**, **pt** (in addition to **en** / **es**).
- **CODE_OF_CONDUCT.md** — Contributor Covenant; linked from README and CONTRIBUTING.
- **docs/GITHUB_CI.md** — GitHub Actions CI requirements, including REQ-GIT-001 (no Cursor co-author trailers).
- **Git hygiene** — `.githooks/commit-msg`, `.scripts/check-no-cursor-coauthor.sh`, `.scripts/strip-cursor-coauthor-from-history.sh`, Cursor rule `01-git-commits.mdc`, and CI job `git-hygiene`.
- **Makefile** — `check-no-cursor-coauthor`, `setup-hooks`, `strip-cursor-coauthor-from-history`; `release-check` runs the co-author check first.

### Changed

- **docs/CONTRIBUTING.md** — Code of Conduct and git-hook setup for REQ-GIT-001.
- **docs/RELEASE.md** — Reminder to re-run `make check-no-cursor-coauthor` before pushing the release commit/tag.
- **es** translations — nav/page strings fully localized.

## [1.0.4] - 2026-07-13

### Added

- **`.scripts/php-coverage-percent.sh`** — prints colored PHP line-coverage percentage after `make test-coverage` (REQ-TEST-008).
- **`.cursor/rules/`** — Cursor agent rules for PHP/Symfony bundle, tests, docs, and release workflow.
- **`.cursorignore`** — reduces indexer noise (vendor, caches, locks, secrets).

### Changed

- **Makefile** — `test-coverage` pipes PHPUnit output to `coverage-php.txt` and runs the coverage-percent script.
- **docs/SECURITY.md** — scope, attack surface, threat model, release security checklist (12.4.1), and private reporting contact.
- **`.gitignore`** — ignores `.cursor/sandbox.json` and `/coverage-php.txt`.
- **composer.lock** / **demo/symfony8/composer.lock** — PHP CS Fixer 3.95.13.

### Fixed

- **demo/symfony8** — `config/reference.php` keeps `declare(strict_types=1)` (post-release CS Fixer alignment).

## [1.0.3] - 2026-07-08

### Added

- **GitHub Spec Kit** scaffolding — `.specify/` templates and scripts, Cursor `/speckit-*` skills, baseline feature `specs/001-baseline/` (spec + code inventory).
- **docs/SPEC-KIT.md** — installation, folder layout, Cursor Agent usage, and maintainer checklist.

### Changed

- **docs/SPEC-DRIVEN-DEVELOPMENT.md** — expanded workflow aligned with Spec Kit and baseline traceability.
- **README** — link to [GitHub Spec Kit](docs/SPEC-KIT.md) documentation.
- **composer.lock** / **demo/symfony8/composer.lock** — PHP CS Fixer 3.95.12 and aligned PHPUnit dev dependencies.

### Documentation

- Baseline specification documents current bundle scope under `specs/001-baseline/` (repository only; excluded from integrator runtime).

## [1.0.2] - 2026-07-07

### Changed

- **demo/symfony8** — `config/reference.php` includes `declare(strict_types=1)` (demo only; no Composer package API change).

## [1.0.1] - 2026-07-06

### Fixed

- **CI** — Code Style job scopes auto-commit to CS Fixer paths (`src`, `tests`, `.php-cs-fixer.dist.php`, `demo`) and skips empty commits when only transient files (e.g. `composer.json`) are dirty.
- **demo/Makefile** — `release-check` reads `PORT` from `.env` (falls back to `.env.example`) so the healthcheck matches the running container.

## [1.0.0] - 2026-07-06

### Added

- **Symfony bundle** for personal and team time tracking (PHP **8.2+**, Symfony **7.4+ / 8.0 / 8.1+**).
- **Web UI** — timer at `/tools/time-track`, reports at `/tools/time-track/reports` (EN/ES translations, Twig templates).
- **Doctrine entities** — `TimeEntry`, `ActiveTimer` (one active timer per user), `ClientToken` (hashed Bearer tokens).
- **TimerService** — start/stop/heartbeat, task listing, entry queries; **409** on active-timer conflict.
- **REST client API** — Bearer auth, CORS, login rate limiting for browser extension and desktop agent (`clients.enabled`).
- **Integration interfaces** — `TaskProviderInterface`, `TeamContextProviderInterface`.
- **Default bridges** — `StubTaskProvider` (in-memory demo tasks), `NullTeamContextProvider`.
- **TeamAccessGuard** — admin/manager ACL for viewing team entries.
- **Symfony events** — `timer.start`, `timer.stop`, `time_entry.list_query`, `time_entry.access_check`.
- **Console command** — `nowo:time-track:client-tokens:purge` (expired Bearer cleanup).
- **Symfony 8.1 Docker demo** integrated with **TaskBoardBundle** (`make -C demo up-symfony8`, port **8024**).
- **Chrome MV3 extension MVP** (`extension/chrome/`, v0.1.0) — login, task list, start/stop timer.
- **Tauri 2 desktop agent scaffold** (`desktop/`, v0.1.0) — system tray, idle heartbeat, start/stop timer.

### Configuration

- `nowo_time_track` tree: `user_class`, `table_prefix`, `route_prefix`, task/team providers, customizable routes and templates, security ACL, clients API (`token_ttl`, `idle_threshold_seconds`, CORS, rate limit).

### Testing

- **100%** PHPUnit line coverage on included bundle code (controllers, Doctrine repositories/listeners, and integration interfaces excluded by design).
- Unit tests for services, DI, routing, client auth, entities, events, and commands.

### Documentation

- Installation, configuration, usage, TaskBoard integration, browser extension, desktop agent, security, and spec-driven development guides.

[Unreleased]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.9.0...HEAD
[1.9.0]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.8.0...v1.9.0
[1.8.0]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.7.0...v1.8.0
[1.7.0]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.6.0...v1.7.0
[1.6.0]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.5.2...v1.6.0
[1.5.2]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.5.1...v1.5.2
[1.5.1]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.5.0...v1.5.1
[1.5.0]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.4.0...v1.5.0
[1.4.0]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.3.7...v1.4.0
[1.3.7]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.3.6...v1.3.7
[1.3.6]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.3.5...v1.3.6
[1.2.0]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.0.6...v1.1.0
[1.0.6]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.0.5...v1.0.6
[1.0.5]: https://github.com/nowo-tech/TimeTrackBundle/releases/tag/v1.0.5
[1.0.4]: https://github.com/nowo-tech/TimeTrackBundle/releases/tag/v1.0.4
[1.0.3]: https://github.com/nowo-tech/TimeTrackBundle/releases/tag/v1.0.3
[1.0.2]: https://github.com/nowo-tech/TimeTrackBundle/releases/tag/v1.0.2
[1.0.1]: https://github.com/nowo-tech/TimeTrackBundle/releases/tag/v1.0.1
[1.0.0]: https://github.com/nowo-tech/TimeTrackBundle/releases/tag/v1.0.0
