# Templates

Pages of TimeTrackBundle on the UiKit page contract (REQ-UI-003, UiKit 2.20+).
`Nowo\TimeTrackBundle\Ui\TimeTrackPages` builds the UiKit view models (`Nowo\UiKitBundle\View\*`) for
`TimeTrackManageController` and resolves the layout (`web_ui.layout_template`, null = `nowo_ui_kit.layouts.admin`).
Both pages extend the UiKit list scaffold and fill data blocks only. Contract test:
`tests/Integration/Contract/PageContractTest.php` (UiKit `PageContractTestCase`, strict missing-translation rule).
Twig namespace: `@NowoTimeTrackBundle` (views in `src/Resources/views/`).

## Table of contents

- [Pages](#pages)
- [Unique pages](#unique-pages)
- [Per-page overrides](#per-page-overrides)
- [Overriding templates (REQ-TWIG-001)](#overriding-templates-req-twig-001)
- [Layouts](#layouts)
- [Allowed exceptions](#allowed-exceptions)

## Pages

Every view model passes `bundle: 'NowoTimeTrackBundle'` (theme exception
`nowo_ui_kit.theme.bundles.NowoTimeTrackBundle`) and `layout: TimeTrackPages::layout()`. Labels are
`NowoTimeTrackBundle` translation keys. Start / Stop are POST `Action`s backed by the CSRF `FormView`s of
`TimerActionType` (flat fields `_token`, `action`, `task_id`; CSRF id `nowo_time_track_manage`) — the posted contract
of 1.x is unchanged.

| Page (`web_ui.templates` id) | Template | Scaffold | View model | Data blocks filled |
| --- | --- | --- | --- | --- |
| Timer (`index`) | `time_track/index.html.twig` | `page/list` | `ListPage` (header `page.timer`; intro `timer.running_intro` (task, start time) + status `timer.status_running` (`success`) + header action Stop (`danger`, `data-testid="time-track-stop"`) while a timer runs, else intro `timer.none`; columns `title` (`tasks.task`), `boardTitle` (`tasks.board`, priority 2); rows = `list<TaskReference>`; row action Start (`primary`, text, `data-testid="time-track-start-<task id>"`) when no timer runs; caption `tasks.title`; `EmptyState` `tasks.empty`; `data-testid="time-track-timer"`) | — (default cells) |
| Reports (`reports`) | `time_track/reports.html.twig` | `page/list` | `ListPage` (header `page.reports`, intro `reports.period` (from – to); columns `taskTitleSnapshot`, `startedAt`, `durationSeconds` (end), `source` (priority 2); rows = `list<TimeEntry>` of the page; `Pagination` (`reports.page_size`, `from` / `to` kept); `EmptyState` `reports.empty`; `data-testid="time-track-reports"`) | `nowo_ui_list_row` (`<time>` start, `reports.minutes`, source label `reports.source_<web|extension|desktop>` on `badge_neutral`) |

The controller also passes the 1.x variables for host templates written for 1.x: `active_timer`, `tasks`,
`stop_form`, `start_forms` (timer page) and `entries`, `entry_page` (`Dto\TimeEntryPage`), `from`, `to` (reports).

## Unique pages

None: both pages extend a scaffold.

## Per-page overrides

- `nowo_time_track.web_ui.templates.<page>` (`index`, `reports`): a host template that extends
  `@!NowoTimeTrackBundle/<template>` and overrides a data block (`nowo_ui_list_row`, `nowo_ui_list_after`, …). The
  deprecated `templates.index` / `templates.reports` keys feed the same values until 2.0.
- Prefer theming the UiKit slots (`nowo_ui_kit.theme`) and scaffolds once for every bundle. Record such overrides
  in the application's override inventory.

## Overriding templates (REQ-TWIG-001)

Any bundle template can be replaced by a file at `templates/bundles/NowoTimeTrackBundle/<subpath>`; the procedure,
the freeze rule and the upgrade-safe alternatives are in [USAGE](USAGE.md#overriding-templates-req-twig-001).

| `<subpath>` | Purpose |
| --- | --- |
| `time_track/index.html.twig` | Timer page (list scaffold) |
| `time_track/reports.html.twig` | Reports page (list scaffold, `nowo_ui_list_row`) |
| `layout.html.twig` | Deprecated 1.x default layout, kept as a shim for 1.x templates that extend it (maps `title`, `stylesheets`, `body`, `javascripts` into the UiKit admin layout); removed in 2.0 |
| `_legacy_layout.html.twig` | Deprecated bridge for a 1.x host layout without the UiKit canonical blocks; removed in 2.0 |

Renaming or removing a `<subpath>` or a data block listed here is a breaking change (see [UPGRADING](UPGRADING.md)).

## Layouts

- `web_ui.layout_template: null` (default): the scaffolds extend `nowo_ui_kit.layouts.admin` — set that once for
  every UiKit page of the host (your layout, or a one-file bridge rendering the canonical blocks).
- `web_ui.layout_template: 'my/layout.html.twig'`: used as is when it renders the canonical blocks
  (`nowo_ui_page_header`, `nowo_ui_flashes`, `nowo_ui_content`, …).
- A 1.x layout (only `title`, `stylesheets`, `body`, `javascripts`, possibly `nowo_ui_content` inside `body`) is
  wrapped in `_legacy_layout.html.twig` and triggers a deprecation; `@NowoTimeTrackBundle/layout.html.twig` (the 1.x
  default) maps to null.

The bundle ships no local pagination, flash, confirm, modal, page header or empty-state partial and no inline script:
the scaffold and the UiKit partials render them; the host layout loads the CSS framework.

## Allowed exceptions

- (none) — the contract test declares no `allowedClasses()`.
