# Usage

- **Web timer:** `/tools/time-track` — start/stop timer on trackable tasks
- **Reports:** `/tools/time-track/reports` — last 7 days of entries
- **Layout without forking pages:** set `nowo_time_track.templates.layout` to your project layout (Twig global `nowo_time_track_layout`). The vendor `layout.html.twig` is the demo fallback only — see [CONFIGURATION.md](CONFIGURATION.md#templates-req-ui-001).
- **CSS stack:** set `nowo_time_track.templates.css_framework` (default `tabler`) to match the host. Twig global: `nowo_time_track_css_framework`. Markup already uses Tabler/Bootstrap 5-compatible classes; with `custom`, style those classes (or `nowo-ui-*`) in the host — no page Twig fork required.
- **Override Twig (escape hatch):** `templates/bundles/NowoTimeTrackBundle/` — prefer config/`layout` + `css_framework` + `{{ parent() }}` asset stacking for upgrades.

```yaml
nowo_time_track:
    user_class: App\Entity\User
    templates:
        layout: base.html.twig
        css_framework: tabler   # bootstrap5 | custom | …
```

## API (clients)

| Method | Path | Description |
|--------|------|-------------|
| POST | `/api/time-track/login` | Issue Bearer token |
| GET | `/api/time-track/me` | Current user |
| GET | `/api/time-track/tasks` | Trackable tasks; `?search=&limit=&offset=` (`limit` 1–100, default 50) |
| GET | `/api/time-track/timer` | Active timer (204 if none) |
| POST | `/api/time-track/timer/start` | `{ "taskId", "clientType" }` |
| POST | `/api/time-track/timer/stop` | Close active timer |
| POST | `/api/time-track/heartbeat` | `{ "isIdle": true/false }` |
| GET | `/api/time-track/entries` | `?from=&to=&userId=` (see below) |

`from` / `to` (client `entries` API and the manage `reports` page) accept ATOM / RFC 3339 (`2026-05-01T10:30:00+02:00`) or `Y-m-d` (midnight). Any other value falls back to the default (`from` = 7 days ago, `to` = now). The span is capped at 366 days: an earlier `from` is moved to `to - 366 days`.

## Events

- `nowo_time_track.timer.start` — `TimerStartEvent`
- `nowo_time_track.timer.stop` — `TimerStopEvent` (TaskBoard can aggregate `time_spent`)
- `nowo_time_track.time_entry.list_query` — filter report user ids
- `nowo_time_track.time_entry.access_check` — fine-grained ACL
