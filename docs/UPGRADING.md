# Upgrading

## Table of contents

- [Unreleased](#unreleased)
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

