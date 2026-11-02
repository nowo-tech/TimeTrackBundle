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
| `clients.routes.<action>.path` / `.name` | `/api/time-track/...` / `nowo_time_track_api_<action>` | Client API (`login`, `logout`, `me`, `tasks`, `timer`, `timer_start`, `timer_stop`, `heartbeat`, `entries`; only when `clients.enabled`) |

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
| `clients.cors_allowed_origins` | `[]` | Exact CORS origins, e.g. `chrome-extension://<extension-id>` (`tauri://` always allowed; `*` only outside `prod`). No `Access-Control-Allow-Credentials` is sent (Bearer auth) |
| `clients.cors_allow_any_extension_origin` | `false` | Opt-in wildcard: allow every `chrome-extension://` / `moz-extension://` origin (any installed extension). Prefer listing your extension id |
| `clients.login_rate_limit.enabled` | `true` | Throttle failed `POST` logins |
| `clients.login_rate_limit.cache_pool` | `cache.app` | PSR-6 pool shared by all workers (no atomic increment: put a proxy/firewall limit in front for hard guarantees) |
| `clients.login_rate_limit.max_attempts` | `10` | Failed logins per IP + username within the interval |
| `clients.login_rate_limit.max_attempts_per_ip` | `null` | Failed logins per IP (any username) within the interval; `null` = 5 × `max_attempts`. Not cleared by a successful login |
| `clients.login_rate_limit.interval_seconds` | `900` | Window (min. 60) |

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
