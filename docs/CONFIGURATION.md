# Configuration

All options under `nowo_time_track` in `config/packages/nowo_time_track.yaml`.

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
| `clients.cors_allowed_origins` | `[]` | Extra CORS origins (`chrome-extension://`, `tauri://` always allowed) |

## Security

| Option | Default | Description |
|--------|---------|-------------|
| `security.admin_roles` | `[ROLE_ADMIN]` | Full report access |
| `security.manager_can_view_entries` | `true` | Managers see team entries |
| `security.manager_can_edit_entries` | `true` | Managers edit team entries |

See [BROWSER-EXTENSION.md](BROWSER-EXTENSION.md) and [DESKTOP-AGENT.md](DESKTOP-AGENT.md) for client setup.
