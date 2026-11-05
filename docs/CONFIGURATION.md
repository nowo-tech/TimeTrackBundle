# Configuration

All options under `nowo_time_track` in `config/packages/nowo_time_track.yaml`.

## Table of contents

- [Required](#required)
- [Integration](#integration)
- [Routes (REQ-SF-004 exemption)](#routes-req-sf-004-exemption)
- [Reports (REQ-PERF-001)](#reports-req-perf-001)
- [Web UI and templates (REQ-UI-001 / REQ-UI-003)](#web-ui-and-templates-req-ui-001--req-ui-003)
  - [Deprecated `templates.*` keys (1.x, removed in 2.0)](#deprecated-templates-keys-1x-removed-in-20)
- [FormKit profile (REQ-TWIG-005)](#formkit-profile-req-twig-005)
- [Clients (extension + desktop)](#clients-extension--desktop)
- [Client API 1.9](#client-api-19)
- [Client API 1.10](#client-api-110)
- [Client distribution](#client-distribution)
- [Imported entries and retention (1.11)](#imported-entries-and-retention-111)
- [Working time and timesheets (1.13)](#working-time-and-timesheets-113)
- [Calendar suggestions (1.14)](#calendar-suggestions-114)
- [Security](#security)

## Required

| Option | Description |
|--------|-------------|
| `user_class` | FQCN of User entity (`UserInterface` + `getId()`) |

## Integration

| Option | Default | Description |
|--------|---------|-------------|
| `task_provider` | `StubTaskProvider` | `TaskProviderInterface` service id |
| `team_context_provider` | `NullTeamContextProvider` | `TeamContextProviderInterface` service id |
| `table_prefix` | `time_track_` | DB table prefix |

## Routes (REQ-SF-004 exemption)

The routes are opt-in: the host imports them once (the Flex recipe writes `config/routes/nowo_time_track.yaml`):

```yaml
nowo_time_track:
    resource: .
    type: nowo_time_track
```

| Option | Default | Description |
|--------|---------|-------------|
| `route_prefix` | `''` | Prepended to every path below |
| `routes.index.path` / `.name` | `/tools/time-track` / `nowo_time_track_index` | Timer page (GET, POST) |
| `routes.reports.path` / `.name` | `/tools/time-track/reports` / `nowo_time_track_reports` | Reports page (GET) |
| `routes.device_verify.path` / `.name` | `/tools/time-track/device` / `nowo_time_track_device_verify` | Default device verification page (GET, POST; only when `client_api.device_flow.enabled` and `default_page`; session-authenticated, keep it behind the host firewall) |
| `clients.routes.<action>.path` / `.name` | `/api/time-track/...` / `nowo_time_track_api_<action>` | Client API (`login`, `logout`, `me`, `tasks`, `timer`, `timer_start`, `timer_stop`, `timer_switch`, `timer_discard_idle`, `heartbeat`, `entries`, `export`; `device_code`, `device_token` only with the device flow; only when `clients.enabled`) |

**Why a route loader instead of `#[Route]` (documented exemption from REQ-SF-004 rule 1).** Every route **name and
path** is host configuration (`routes.*`, `clients.routes.*`, `route_prefix`), and the client API routes exist only
when `clients.enabled` is true. Static `#[Route]` attributes cannot carry per-host names, and hosts already depend on
the configured names (`path('nowo_time_track_index')`, the browser extension and desktop app call the configured API
paths). `Nowo\TimeTrackBundle\Routing\TimeTrackRouteLoader` (type `nowo_time_track`) therefore builds the routes
from the configuration; names, paths and methods are unchanged since 1.0. The other REQ-SF-004 rules hold:
controllers are services with constructor injection and no `#[AsController]`, no YAML/XML route files ship with the
bundle, access is enforced by `TimeTrackAccessCheckerInterface` (REQ-UI-002), and the controllers carry no `#[Route]`,
so the host's `routing.controllers` import never loads them (rule 7, `tests/Unit/Routing/HostRoutingControllersTest.php`).

Hosts may also route single controller actions themselves (e.g. only the client API under their own firewall):

```yaml
nowo_time_track_api_login:
    path: /api/time-track/login
    controller: Nowo\TimeTrackBundle\Controller\TimeTrackClientApiController::login
    methods: [POST, OPTIONS]
```

## Reports (REQ-PERF-001)

| Option | Default | Description |
|--------|---------|-------------|
| `reports.page_size` | `50` | Entries per page of the reports list (1–500). The page is read from the `page` query parameter; out-of-range pages show the nearest existing page. The default repository pages in the database (`PaginatedTimeEntryRepositoryInterface`); a custom `TimeEntryRepositoryInterface` without it is sliced in memory. |

The template receives the `page` view model (UiKit `ListPage` with a `Pagination`), plus, for 1.x overrides, `entries`
(the entries of the page), `entry_page` (`Nowo\TimeTrackBundle\Dto\TimeEntryPage`: `page`, `perPage`, `total`,
`totalPages()`, `hasPrevious()`, `hasNext()`) and `from` / `to`.

## Web UI and templates (REQ-UI-001 / REQ-UI-003)

Since 1.8 the timer and reports pages extend the UiKit list scaffold (`@NowoUiKit/page/list.html.twig`) and render
inside a UiKit layout; classes come from the UiKit theme slots (`nowo_ui_kit.css_framework`, `nowo_ui_kit.theme`).
The page contract is in [TEMPLATES](TEMPLATES.md).

| Option | Default | Description |
|--------|---------|-------------|
| `web_ui.layout_template` | `null` | Layout of the pages. `null` = `nowo_ui_kit.layouts.admin` (set that once for every UiKit page of the host). A layout that renders the UiKit canonical blocks (`nowo_ui_page_header`, `nowo_ui_flashes`, `nowo_ui_content`, …) is used as is; a 1.x layout (`title`, `stylesheets`, `body`, `javascripts`) is wrapped in a deprecated bridge (removed in 2.0). |
| `web_ui.templates.index` | `@NowoTimeTrackBundle/time_track/index.html.twig` | Timer page template (a host template extending `@!NowoTimeTrackBundle/time_track/index.html.twig`) |
| `web_ui.templates.reports` | `@NowoTimeTrackBundle/time_track/reports.html.twig` | Reports page template |

```yaml
nowo_ui_kit:
    css_framework: bootstrap5
    layouts:
        admin: 'layouts/admin.html.twig'   # one-file bridge onto the project shell (see below)

nowo_time_track:
    user_class: App\Entity\User
    # web_ui:
    #     layout_template: null           # null = nowo_ui_kit.layouts.admin
```

One-file bridge onto the project shell (the UiKit `_bridge.html.twig` prints the canonical blocks):

```twig
{# templates/layouts/admin.html.twig #}
{% extends 'base.html.twig' %}
{% use '@NowoUiKit/layout/_bridge.html.twig' %}
{% block title %}{{ block('nowo_ui_bridge_title') }}{% endblock %}
{% block stylesheets %}{{ parent() }}{{ block('nowo_ui_bridge_assets') }}{% endblock %}
{% block body %}{{ block('nowo_ui_bridge_body') }}{% endblock %}
{% block javascripts %}{{ parent() }}{{ block('nowo_ui_bridge_end') }}{% endblock %}
```

### Deprecated `templates.*` keys (1.x, removed in 2.0)

Each key triggers a configuration deprecation and keeps working until 2.0:

| Deprecated key | Replacement | 1.8 behaviour |
|----------------|-------------|---------------|
| `templates.layout` | `web_ui.layout_template` | Feeds `web_ui.layout_template` unless that is set. The 1.x default `@NowoTimeTrackBundle/layout.html.twig` maps to `null` (UiKit admin layout). A 1.x layout without the canonical blocks is wrapped in `_legacy_layout.html.twig`. Still exposed as Twig global `nowo_time_track_layout`. |
| `templates.css_framework` | `nowo_ui_kit.css_framework` | Seeds `nowo_ui_kit.css_framework` / `icon_set` when the host does not set them; Twig global `nowo_time_track_css_framework`. |
| `templates.index` / `templates.reports` | `web_ui.templates.index` / `.reports` | Feed `web_ui.templates.*` unless those are set. |

| Twig global | Source |
|-------------|--------|
| `nowo_time_track_layout` | `templates.layout` (deprecated) |
| `nowo_time_track_css_framework` | `templates.css_framework` (deprecated) |

## FormKit profile (REQ-TWIG-005)

The manage UI start / stop actions are a Symfony Form Type built with FormKit (`TimerActionType`,
`#[FormKitConfig('time_track')]`), rendered with `form_start` / `form_widget` / `form_end` — never raw `<form>` /
`<input>` (keep it that way in overrides). Its field options come from the FormKit profile
`nowo_form_kit.profiles.time_track`, which the bundle prepends when FormKitBundle is registered:

```yaml
nowo_form_kit:
    profiles:
        time_track:
            alias: time_track
            translation_domain: NowoTimeTrackBundle
            auto_placeholder: false
            auto_help: false
```

Define `profiles.time_track` in your own `nowo_form_kit` config to theme the bundle forms; the bundle then does not
prepend its default. The stock profile adds no classes.

## Clients (extension + desktop)

| Option | Default | Description |
|--------|---------|-------------|
| `clients.enabled` | `false` | Enable REST API routes |
| `clients.token_ttl` | `86400` | Bearer token lifetime (seconds) |
| `clients.idle_threshold_seconds` | `300` | Desktop idle threshold |
| `clients.cors_allowed_origins` | `[]` | Exact CORS origins, merged with `client_api.allowed_origins`. `*` (any origin outside `prod`) is deprecated since 1.9. No `Access-Control-Allow-Credentials` is sent (Bearer auth) |
| `clients.cors_allow_any_extension_origin` | `false` | **Deprecated since 1.9** (removed in 2.0): allow every `chrome-extension://` / `moz-extension://` origin. List the exact origins instead |
| `clients.login_rate_limit.enabled` | `true` | Throttle failed `POST` logins |
| `clients.login_rate_limit.cache_pool` | `cache.app` | PSR-6 pool shared by all workers (no atomic increment: put a proxy/firewall limit in front for hard guarantees) |
| `clients.login_rate_limit.max_attempts` | `10` | Failed logins per IP + username within the interval |
| `clients.login_rate_limit.max_attempts_per_ip` | `null` | Failed logins per IP (any username) within the interval; `null` = 5 × `max_attempts`. Not cleared by a successful login |
| `clients.login_rate_limit.max_attempts_per_username` | `null` | Failed logins per username (any IP) within the interval; `null` = 5 × `max_attempts`. Cleared by a successful login (1.9). Usernames are NFKC-normalised (ext-intl), trimmed, lower-cased. Anyone can spend a user's budget and lock them out of client login for `interval_seconds` (tokens and web login unaffected): raise it, or set `enabled: false` and use a proxy limit. IPv6 clients count per /64 in every bucket (IPv4-mapped addresses as IPv4) |
| `clients.login_rate_limit.interval_seconds` | `900` | Window (min. 60) |

## Client API 1.9

Contract v0.2 ([CLIENT-API](CLIENT-API.md)). Every service id below is optional; `null` keeps the bundle default.

| Option | Default | Description |
|--------|---------|-------------|
| `client_api.allowed_origins` | `[]` | Exact CORS origins (`chrome-extension://<id>`, `moz-extension://<uuid>`, `extension://<id>`); a value with `*` is rejected |
| `client_api.allow_tauri_webview_origins` | `true` | Deprecated 1.x behaviour (false in 2.0): also allow `tauri://localhost`, `http(s)://tauri.localhost` (1.x desktop agent calling from its webview). The 1.9 desktop app sends no Origin |
| `client_api.allow_metadata` | `false` | Accept free `metadata` on `timer/start` (otherwise `400 metadata_not_allowed`; clients send `source` + `note`) |
| `client_api.settings_provider` | `null` | `ClientApiSettingsProviderInterface` (origins + rate limits read per request, e.g. from DB settings) |
| `client_api.credential_state` | `null` | `ClientCredentialStateInterface`, checked on every request (default: always active) |
| `client_api.credential_issuer` | `null` | `ClientCredentialIssuerInterface`, called on device approval (default: opaque `device:<uuid>`) |
| `client_api.task_access_checker` | `null` | `TaskAccessCheckerInterface` for `timer/start` / `timer/switch` (default: the task provider decides) |
| `client_api.profile_provider` | `null` | `ClientUserProfileProviderInterface` (`displayName`, `workingHours` of `GET me`; since 1.11 also `locale` when it implements `ClientUserLocaleProviderInterface`; since 1.12 also `daysOff` when it implements `ClientUserDaysOffProviderInterface`). `workingHours` may use the `slots` or the 1.9 `days` shape: `GET me` always sends `slots`; a malformed value fails closed as `{timezone, slots: []}` (no notifications limited to working hours) with a warning log, `null` only when the provider returns `null` ([CLIENT-API](CLIENT-API.md#working-hours-normalised-since-112)). Days-off labels reach the user's devices and are stored there: public holiday names only, `null` for vacations, never a leave reason. Cache `getDaysOff()` if it calls a calendar service (it runs on every `GET me`) |
| `client_api.rate_limit.cache_pool` | `cache.app` | PSR-6 pool of the API counters, shared by all workers (Redis in production) |
| `client_api.rate_limit.per_token` | `60` | Requests per token per window (`0` = off) |
| `client_api.rate_limit.per_ip` | `300` | Requests per client IP per window, every API route (`0` = off) |
| `client_api.rate_limit.device_per_ip` | `30` | Requests per client IP per window on `device/code`, `device/token` (IPv6 per /64, like every per-IP limit) |
| `client_api.rate_limit.window_seconds` | `60` | Window |
| `client_api.audit.denial_interval_seconds` | `60` | At most one `request_denied` audit event per credential (or IP /24 /64) and reason per interval |
| `client_api.device_flow.enabled` | `false` | Route `device/code`, `device/token` (opt-in) |
| `client_api.device_flow.default_page` | `true` | Also route the bundle verification page (`routes.device_verify`) |
| `client_api.device_flow.verification_uri` | `null` | Absolute `https://` URL of the host page (required when `default_page` is false; `http://localhost` allowed) |
| `client_api.device_flow.code_ttl` | `600` | Lifetime of a device request (60–3600 s) |
| `client_api.device_flow.interval` | `5` | Polling interval; faster polls get `slow_down` |
| `client_api.device_flow.lookup_limit` / `lookup_window_seconds` | `10` / `900` | User-code lookups per signed-in user per window |
| `client_api.device_flow.max_pending_per_prefix` | `20` | Device requests created per client IPv4 /24 or IPv6 /64 within `code_ttl`; above it `device/code` answers `429` (0 = off) |
| `client_api.device_flow.max_pending` | `10000` | Pending, not expired device requests in total; expired rows are purged first, then `device/code` answers `429` (0 = off). About 500 IPv6 /64s can fill it for one `code_ttl`, blocking new device sign-ins only (not login or existing tokens): raise it, lower `max_pending_per_prefix` / `code_ttl`, or set 0 |

```yaml
nowo_time_track:
    clients:
        enabled: true
        authenticator: App\TimeTrack\Client\AppPasswordAuthenticator
    client_api:
        allowed_origins: ['chrome-extension://abcdefghijklmnopabcdefghijklmnop', 'moz-extension://00000000-0000-0000-0000-000000000000']
        credential_state: App\TimeTrack\Client\AppPasswordCredentialState
        credential_issuer: App\TimeTrack\Client\AppPasswordDeviceIssuer
        task_access_checker: App\TimeTrack\Security\ProjectTaskAccess
        rate_limit: { cache_pool: cache.redis }
        device_flow:
            enabled: true
            default_page: false
            verification_uri: 'https://beacon.example/account/security/time-apps/connect'
```

## Client API 1.10

Contract v0.3 ([CLIENT-API](CLIENT-API.md)). All values except `task_url.origin` can also come from a
`ClientApiSettingsProviderInterface` (per instance); they are clamped into the ranges below.

| Option | Default | Description |
|--------|---------|-------------|
| `client_api.backdate.max_seconds` | `86400` | How far back `startedAt` on `timer/start` may go (0–86400; hosts may only lower the 24 h default). `0` turns client-supplied `startedAt` / `endedAt` off (`reason: disabled`) |
| `client_api.backdate.clock_skew_seconds` | `120` | Future tolerance of `startedAt` / `endedAt` read as "now" (0–900); beyond it the value is clamped with `reason: future` |
| `client_api.idle.allow_reassign` | `true` | Accept `timer/discard-idle` action `reassign` (`false`: `400 invalid_request`, `features.idle_reassign: false`) |
| `client_api.idle_check.enabled` | `true` | Clients ask the user about idle time (`features.idle_check`) |
| `client_api.idle_check.threshold_minutes` | `10` | Idle minutes before the client asks (5–60) |
| `client_api.idle_check.answer_timeout_minutes` | `5` | Minutes the idle prompt waits for an answer (1–30) |
| `client_api.idle_check.user_can_disable` | `false` | Whether users may turn the idle check off in the client (`limits.idle_check.user_can_disable`, 1.10.1); a provider can decide per user |
| `client_api.idle_check.provider` | `null` | Service implementing `IdleCheckSettingsProviderInterface` (per-user settings; `null` from it = the values above; its values are clamped; an exception falls back to the values above) |
| `client_api.task_url.origin` | `null` | Expected origin of task URLs (exact `https://host[:port]`); `null` = the request origin |

The server only ever receives the user's idle **decision** (`timer/discard-idle`); no activity signal, idle counter or
input count is sent to it or stored.

Behind a reverse proxy, derive the task URL origin from your public URL instead of the request host (Beacon pattern):

```yaml
nowo_time_track:
    client_api:
        task_url:
            origin: '%beacon.app_url%'   # e.g. https://beacon.example.com: bare origin, no path, no trailing slash
        backdate: { max_seconds: 43200 }
        idle_check: { threshold_minutes: 15, provider: App\TimeTrack\OrganisationIdleCheck }
```

## Client distribution

Download links rendered by `@NowoTimeTrackBundle/client_distribution/_downloads.html.twig`. Only absolute http(s)
URLs are kept.

| Option | Default | Description |
|--------|---------|-------------|
| `client_distribution.provider` | `null` | `ClientDistributionProviderInterface` service (default: the links below) |
| `client_distribution.extension.chrome` / `.edge` / `.firefox` | `null` | Store links |
| `client_distribution.desktop.windows` / `.macos` / `.linux` | `null` | Installer links (e.g. GitHub Releases of the public desktop repo) |
| `client_distribution.desktop.latest_version` | `null` | Shown next to the desktop links |
| `client_distribution.desktop.update_manifest_url` | `null` | Tauri `latest.json` (exposed on `ClientDistribution`, not rendered) |
| `client_distribution.min_supported_version.provider` | `null` | `ClientMinSupportedVersionProviderInterface` service (default: the versions below) — 1.9.1 |
| `client_distribution.min_supported_version.extension` / `.desktop` / `.web` | `null` | Oldest supported client version per type, sent by `GET me` as `minSupportedVersion` (key absent when `null`). Must be semver 2.0.0 (`1.4.0`, `2.0.0-rc.1`; no `v` prefix) — 1.9.1 |

## Imported entries and retention (1.11)

```yaml
nowo_time_track:
  imports:
    max_entry_seconds: 2678400   # longest single imported entry (31 days); 3600–31622400
  retention:
    include_imported: false      # imported entries stay out of retention purges
```

- Imported entries are written by `Import\ImportedTimeEntryWriter` (host code). Their description is kept **in full**
  (unbounded text column, no length cap).
- `retention.include_imported` feeds `Retention\TimeEntryRetentionPolicy`; the bundle ships no purge yet, so a host
  purge must apply `excludedSources()`.

## Working time and timesheets (1.13)

Everything is off by default; see [WORKING-TIME.md](WORKING-TIME.md) for the model.

```yaml
nowo_time_track:
  working_time:
    enabled: false
    calendar_provider: ~            # WorkingCalendarProviderInterface service id (null = DerivedWorkingCalendar)
    worked_time_source: ~           # WorkedTimeSourceInterface (null = TaskTimeWorkedTimeSource, legalRecord false)
    overtime_policy: ~              # OvertimePolicyInterface (null = ConfigOvertimePolicy)
    balance:
      start_date: ~                 # Y-m-d; null = after the last locked period, else the last 366 days
    overtime:
      daily_mode: expected          # expected | ordinary_max | none
      ordinary_max_seconds: 32400   # 9 h, art. 34.3 ET
      weekly_mode: expected         # expected | legal_max
      weekly_max_seconds: 144000    # 40 h
      annual_cap_seconds: 288000    # 80 h, art. 35.2 ET (0 = no cap flags)
      ratify_on_approval: true
  timesheets:
    enabled: false
    scope_mode: provider            # provider | single
    scope_provider: ~               # TimesheetScopeProviderInterface (required with scope_mode: provider)
    approver_provider: ~            # TimesheetApproverProviderInterface (required when enabled)
    period: week                    # week | month
    default_timezone: 'UTC'
    submit_requires_period_end: false
    auto_lock_after_days: ~         # null = off
    allow_self_approval: false      # fixed false in 1.13
    admin_roles_view: false
    reopen_roles: []
    approver_sees_notes: false
    audit_blocked_edits: false
    retention_years: 4              # 4-10
  client_api:
    workday_provider: ~             # Workday\WorkdayProviderInterface (R-GDPR §8); null = no workday API
```

- Refused at compile time: `timesheets.enabled` without `approver_provider`, `scope_mode: provider` without
  `scope_provider`, `allow_self_approval: true`, an unknown `default_timezone`, `retention_years` outside 4–10, a bad
  `balance.start_date`.
- With `working_time.enabled` and no scope provider, everybody is in one scope `''` (like `scope_mode: single`).
- Period zone: the scope's `TimesheetScope::$timezone`, else the user's working-hours zone, else `default_timezone`.
- Client routes (`clients.routes.*`): `workday`, `workday_action` (`/api/time-track/workday/{action}`), `balance`,
  `timesheets`, `timesheet_submit`, `timesheet_withdraw` (`/api/time-track/timesheets/{periodKey}/submit|withdraw`);
  they answer 404 while their feature is off.
- Public services for hosts: `PeriodLockGuard`, `TimesheetService`, `TimesheetFormHandler`, `TimesheetAccessGuard`,
  `WorkingTimeBalanceService`, `BalanceLedger`, `Ui\TimesheetViewBuilder`.

## Calendar suggestions (1.14)

Off by default; see [USAGE.md](USAGE.md#calendar-suggestions-114) for the flow and [SECURITY.md](SECURITY.md#calendar-suggestions-and-meetings-114) for what is read and stored.

```yaml
nowo_time_track:
  calendar:
    enabled: false
    credential_provider: ~          # CalendarCredentialProviderInterface (host tokens; required for server mode)
    task_key_resolver: ~            # TaskKeyResolverInterface (host task keys; required when enabled)
    providers: [google, microsoft]
    modes: [server]                 # server | local
    lookback_days: 14               # 1-14; events must have ended
    suggestion_ttl_days: 14         # kept after the event ends (1-31)
    sync_interval_minutes: 60       # 5-1440, between two scheduled syncs of one connection
    max_event_seconds: 28800        # longer events are ignored
    max_rules_per_user: 20          # per scope (0-100)
    max_pages: 10                   # provider pages per sync (1-50)
```

- Refused at compile time: `enabled` without `task_key_resolver`, `server` in `modes` without `credential_provider`,
  unknown providers or modes, values outside the ranges above.
- Without `timesheets.scope_provider` everybody is in one scope `''` (like `scope_mode: single`).
- Server mode needs `symfony/http-client` (the framework `http_client`); without it a sync fails with `no_http_client`.
- Public services for hosts: `Calendar\CalendarConnectionService`, `Calendar\CalendarRuleService`,
  `Calendar\CalendarSyncService`, `Calendar\CalendarSuggestionService`, `Service\ManualEntryService`.

```yaml
nowo_time_track:
  manual_entries:                   # POST entries (sources manual / calendar), Q-O7
    enabled: false
    max_age_days: 31                # oldest start accepted (1-366); accepted suggestions use calendar.lookback_days
```

- Client routes (`clients.routes.*`): `calendar_connections`, `calendar_connection_revoke`
  (`/api/time-track/calendar/connections/{id}`, DELETE), `suggestions`, `suggestions_accept`, `suggestions_dismiss`,
  `suggestions_resolve_keys`, `entries_create` (`POST /api/time-track/entries`); they answer 404 while their feature is
  off.

## Security

| Option | Default | Description |
|--------|---------|-------------|
| `security.access_checker` | `null` | Custom `TimeTrackAccessCheckerInterface` service id |
| `security.access_roles` | `[ROLE_USER]` | Open manage UI (hosts may set `ROLE_ADMIN` for admin-only) |
| `security.allow_unauthenticated` | `false` | DEV/DEMO only — wires AllowAll checker; production MUST keep `false` |
| `security.admin_roles` | `[ROLE_ADMIN]` | Full report access via `TeamAccessGuard` |
| `security.manager_can_view_entries` | `true` | Managers see team entries |
| `security.manager_can_edit_entries` | `true` | **Deprecated since 1.5** — the bundle ships no entry edit route; only `TeamAccessGuard::canEditEntry()` (host code) reads it. Decide edit access in a `nowo_time_track.time_entry.access_check` listener instead |

Manage UI requires `symfony/security-bundle` when `allow_unauthenticated` is `false` (compile-time `LogicException` otherwise).

```yaml
# config/packages/security.yaml (example)
security:
    access_control:
        - { path: ^/tools/time-track, roles: ROLE_USER }
```

See [BROWSER-EXTENSION.md](BROWSER-EXTENSION.md) and [DESKTOP-AGENT.md](DESKTOP-AGENT.md) for client setup.
