# Usage

## Table of contents

- [Web UI](#web-ui)
- [Overriding templates (REQ-TWIG-001)](#overriding-templates-req-twig-001)
- [Translations (REQ-I18N-001)](#translations-req-i18n-001)
- [API (clients)](#api-clients)
- [Events](#events)

## Web UI

- **Web timer:** `/tools/time-track` — start/stop timer on trackable tasks
- **Reports:** `/tools/time-track/reports` — last 7 days of entries by default (`?from=&to=`), paginated
  (`?page=`, `reports.page_size` entries per page — see [CONFIGURATION.md](CONFIGURATION.md#reports-req-perf-001))
- **Layout without forking pages:** set `nowo_time_track.templates.layout` to your project layout (Twig global `nowo_time_track_layout`). The vendor `layout.html.twig` is the demo fallback only — see [CONFIGURATION.md](CONFIGURATION.md#templates-req-ui-001).
- **CSS stack:** set `nowo_time_track.templates.css_framework` (default `tabler`) to match the host. Twig global: `nowo_time_track_css_framework`. Markup already uses Tabler/Bootstrap 5-compatible classes; with `custom`, style those classes (or `nowo-ui-*`) in the host — no page Twig fork required.
- **Override Twig (escape hatch):** `templates/bundles/NowoTimeTrackBundle/` — prefer config/`layout` + `css_framework` + `{{ parent() }}` asset stacking for upgrades.
- **Forms (REQ-TWIG-005):** the start/stop buttons are Symfony Forms (`Nowo\TimeTrackBundle\Form\TimerActionType`, flat field names `_token` / `action` / `task_id`, CSRF id `nowo_time_track_manage`). The controller always passes `stop_form` and `start_forms` (keyed by task id) to `time_track/index.html.twig`. Render them with `form_start`, the child loop and `form_end`; hosts that override the page and demos must not reintroduce raw `<form>` / `<input>` markup:

```twig
{{ form_start(stop_form) }}
    {% for child in stop_form %}
        {% if not child.rendered %}
            {{ form_row(child) }}
        {% endif %}
    {% endfor %}
    <button class="btn btn-danger" type="submit">{{ 'action.stop'|trans({}, 'NowoTimeTrackBundle') }}</button>
{{ form_end(stop_form) }}
```

```yaml
nowo_time_track:
    user_class: App\Entity\User
    templates:
        layout: base.html.twig
        css_framework: tabler   # bootstrap5 | custom | …
```

## Overriding templates (REQ-TWIG-001)

Any bundle template can be replaced by a file at `templates/bundles/NowoTimeTrackBundle/<subpath>` (same relative
path as under `src/Resources/views/`). `TwigPathsPass` registers the application directory first (`prependPath`, when
it exists) and the bundle views after it (`addPath`), so **an application file at the override path always wins**.
The paths are registered whenever the bundle is enabled. Pages are rendered by logical name
(`@NowoTimeTrackBundle/...`, or the `templates.index` / `templates.reports` values).

**Freeze rule.** An application file at the override path always wins and will not pick up vendor changes for that
path until you remove or merge it: after `composer update` the application keeps rendering its own copy.
Upgrade-safe customisation, in this order:

| Depth | How | On a TimeTrackBundle upgrade |
| --- | --- | --- |
| Preferred — no Twig fork | `templates.layout` (your project layout or a one-file bridge), `templates.css_framework`, translations (below), the FormKit profile `time_track`, CSS on `nowo-ui-*` / `--nowo-ui-*` | Vendor templates and fixes apply automatically |
| Surgical | `templates.index` / `templates.reports` pointing at a thin host template that `{% extends '@!NowoTimeTrackBundle/time_track/index.html.twig' %}` and overrides one block with `{{ parent() }}` | Only that block is frozen |
| Full-file override | Copy a page into `templates/bundles/NowoTimeTrackBundle/<subpath>` | That `<subpath>` is frozen until removed or merged |

**Procedure.**

1. Find the `<subpath>` in the table below (path relative to `src/Resources/views/`).
2. Create `templates/bundles/NowoTimeTrackBundle/<subpath>` in the application (same file name).
3. Clear the cache in dev if needed: `php bin/console cache:clear`.
4. Treat the file as a fork: it does not change when the bundle ships a new version of that `<subpath>`.

Example: to replace the reports page, create `templates/bundles/NowoTimeTrackBundle/time_track/reports.html.twig`.

**Recovering upstream after a freeze.** Delete (or rename aside) the application file, clear the Twig cache, then
re-apply only surgical customisations if still needed. Diff your copy against
`vendor/nowo-tech/time-track-bundle/src/Resources/views/<subpath>` before re-copying anything.

**Overridable subpaths.**

| `<subpath>` | Purpose |
| --- | --- |
| `layout.html.twig` | Default demo layout (`templates.layout` default); hosts set `templates.layout` to their own layout instead of overriding it |
| `time_track/index.html.twig` | Timer page: active timer + stop form, trackable tasks + start forms (`active_timer`, `tasks`, `stop_form`, `start_forms`) |
| `time_track/reports.html.twig` | Reports page: time entries of the period with pagination (`entries`, `entry_page`, `from`, `to`) |

Renaming or removing a `<subpath>` or a published block is a breaking change (see [UPGRADING](UPGRADING.md)).

## Translations (REQ-I18N-001)

All bundle strings use the translation domain **`NowoTimeTrackBundle`** (catalogues in `src/Resources/translations/`,
7 locales: de, en, es, fr, it, nl, pt). The application overrides any key without forking templates:

1. Create `translations/NowoTimeTrackBundle.<locale>.yaml` (or `.xlf`) in the application — same domain name.
2. Add only the keys you want to change; missing keys fall back to the bundle catalogue.
3. Clear the cache in dev if needed: `php bin/console cache:clear`.

Example — `translations/NowoTimeTrackBundle.en.yaml`:

```yaml
page:
  timer: Timesheet
tasks:
  empty: No open tasks assigned to you.
flash:
  timer_started: Clock is running.
```

Application translations are loaded after the bundle catalogues, so they take precedence. To add a locale the bundle
does not ship, create the full file for that locale (copy the English catalogue as a starting point).

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
