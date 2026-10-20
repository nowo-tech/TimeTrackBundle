# Configuration

All options under `nowo_time_track` in `config/packages/nowo_time_track.yaml`.

## Table of contents

- [Required](#required)
- [Integration](#integration)
- [Templates (REQ-UI-001)](#templates-req-ui-001)
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

## Templates (REQ-UI-001)

| Option | Default | Description |
|--------|---------|-------------|
| `templates.layout` | `@NowoTimeTrackBundle/layout.html.twig` | Outer Twig layout extended by timer/report pages. Exposed as Twig global **`nowo_time_track_layout`**. **Set this to your project layout** (e.g. `base.html.twig`) or a one-file bridge; the bundle default is for demos only. |
| `templates.css_framework` | `tabler` | Host CSS stack hint (REQ-UI-001). Exposed as Twig global **`nowo_time_track_css_framework`**. Values: `bootstrap` (treat as bootstrap5), `bootstrap4`, `bootstrap5`, `tabler` (Bootstrap-compatible), `tailwind`, `foundation`, `custom`, `none`. Default **`tabler`** matches the shared demo Tabler CDN. |
| `templates.index` | `@NowoTimeTrackBundle/time_track/index.html.twig` | Timer page template |
| `templates.reports` | `@NowoTimeTrackBundle/time_track/reports.html.twig` | Reports page template |

```yaml
nowo_time_track:
    user_class: App\Entity\User
    templates:
        # Prefer project chrome — do not fork index/reports just to match the host layout
        layout: base.html.twig
        css_framework: tabler   # or: bootstrap5 | custom | tailwind | foundation | none
```

**CSS stack:** timer/report markup uses **Tabler / Bootstrap 5-compatible** classes (`.card`, `.btn`, …). Set `templates.css_framework` to match the host. With `custom` (or `none`), keep those class names in host CSS or map via semantic `nowo-ui-*` styles — you do **not** need to fork page Twig files. Changing `css_framework` alone does not rewrite markup; it documents the stack and is available in Twig as `nowo_time_track_css_framework` for bridges/macros.

If the project layout uses a different content block than `body`, use a thin bridge:

```twig
{# templates/time_track_layout_bridge.html.twig #}
{% extends 'base.html.twig' %}
{% block body %}
    {% block nowo_ui_content %}{% endblock %}
{% endblock %}
```

Then set `templates.layout: time_track_layout_bridge.html.twig`.

Pages call `{{ parent() }}` in `stylesheets` / `javascripts` so host assets keep loading when the layout points at the project shell.

| Twig global | Source |
|-------------|--------|
| `nowo_time_track_layout` | `templates.layout` |
| `nowo_time_track_css_framework` | `templates.css_framework` |

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
