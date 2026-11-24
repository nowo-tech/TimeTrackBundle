# Roadmap

This document outlines the planned direction for Time Track Bundle. Items are grouped by horizon and are subject to
change based on feedback and maintainer capacity.

## Table of contents

- [Current state (v1.x)](#current-state-v1x)
- [Planned: browser extension and desktop app (RGPD / LOPDGDD compliant)](#planned-browser-extension-and-desktop-app-rgpd--lopdgdd-compliant)
  - [Token API](#token-api)
  - [Browser extension (MV3)](#browser-extension-mv3)
  - [Desktop app (Tauri)](#desktop-app-tauri)
  - [Compliance by design](#compliance-by-design)
- [Feature gap review (2026-11-18)](#feature-gap-review-2026-11-18)
  - [Next](#next)
  - [Later](#later)
  - [Considering](#considering)
- [Non-goals](#non-goals)
- [Requested for CrmKit (Beacon spec 254, 2026-11-23)](#requested-for-crmkit-beacon-spec-254-2026-11-23)

---

## Current state (v1.x)

Current release **v1.31.0** (2026-11-18) — see [CHANGELOG.md](CHANGELOG.md).

- Timer, time entries and timesheets for the host's tasks (task provider; [TaskBoard integration](TASK-BOARD-INTEGRATION.md)).
- Client API for the browser extension and the desktop app ([CLIENT-API.md](CLIENT-API.md)); the clients live in their
  own repositories (TimeTrack Extension, TimeTrack Desktop). The prototypes under `extension/` and `desktop/` are kept
  for reference ([BROWSER-EXTENSION.md](BROWSER-EXTENSION.md), [DESKTOP-AGENT.md](DESKTOP-AGENT.md)).
- **1.13**: working time, hours balance and overtime (flags only), balance ledger, weekly timesheets with approval and a
  hard period lock, audit chains, workday client API (R-GDPR §8).
- **1.14**: calendar suggestions (Google / Microsoft 365, read-only, primary calendar, opt-in, server mode), `POST
  entries`, and the meetings API for TimeTrack Desktop reminders (spec 210; the host stores the meetings).
- **1.15 – 1.18**: entries with no user and former people, imported time moves, task time that follows a board move,
  the user reference port and scalar event accessors for hexagonal hosts.
- **1.19 – 1.31**: calendar readers and OAuth helpers, the editable `billable` flag with a host default, `leave` and
  `daysOff` on the workday, framework-free ports and the private entity manager, the timer for token principals
  (`HostTimerApi`), opt-in activity monitoring (1.26), load readiness (own transaction runner, 409 + `Retry-After`) and
  the versioned client API (`/api/time-track/v1`).

---

TimeTrack closure plan (Beacon D-20261111-06; each minor is shippable):

- **1.19** (spec 198 side): calendar readers and OAuth helpers for a host meeting sync, `accepted_user_classes`,
  `GET me` `links.workingTime`, REQ-RUNTIME-002, and part of REQ-ARCH-001 (XML host test, wrapper users, listener
  switch).
- **1.20** (Beacon request 2026-11-11): calendar OAuth apps read at runtime from the host
  (`calendar.oauth.app_provider`, e.g. encrypted in its database), instead of `.env`.
- **1.21** (Beacon D-20261111-12): the `billable` flag of an entry is editable (audited `TimeEntryBillableService`,
  `PATCH entries/{id}`, `billable` on `POST entries`) and new entries take a host default
  (`TimeEntryBillableDefaultProviderInterface`).
- **1.22** (spec 204, Block 2; was 1.21, before that 1.20): `leave` on the workday, the `daysOff` leave kind and
  `links.leave` (done on `feat/leave-spec-204`, `ClientUserLeaveProviderInterface`); budget versus actual with TaskBoard here or in its own minor. REQ-ARCH-001, additive: ports with a user
  id value object next to the `UserInterface` ones (rules 1 / 3), and a documented, tested bundle-owned entity manager
  so no service flushes the host's (rule 6).
- **2.0**: the `UserInterface` ports and entity accessors deprecated in 1.22 are removed.
- **1.25** (Beacon spec 234, AI Flow on Beacon tasks): `HostTimerApi` (timer start / stop / current for token
  principals), `ServiceAccountPolicyInterface` (agents' entries may overlap), the entry channel and service-account mark.
- **2.0** (Beacon review of 1.25): a dedicated `TimeEntrySource` (and `ClientType`) for entries written through
  `HostTimerApi` (`api` / `agent`); 1.25 stores them as `web` with the `channel` column, because a new enum case would
  break an exhaustive `match` in a host.
  In the same major, `HostTimerApi`'s constructor takes `Security\TaskAccessPolicyInterface` instead of the deprecated
  `TaskAccessCheckerInterface` it asks in 1.x (audit PA, Info): 1.25 uses the deprecated slot on purpose, because that
  is the one the ports pass rewrites, so a host configuring either interface is served — but it is a **public** service,
  so the signature change belongs in the 2.0 notes.
- **1.26** (spec 199; planned for 1.23, before that 1.22 and 1.21): the activity-monitoring API (screenshots, apps,
  activity level), with a full audit. Opt-in: off unless your organisation enables it with a recorded DPIA; you see it
  first. Websites / domains come later, with an extension release.
- **1.41** (owner decisions D4, D9, D11 of the 2026-11-24 TimeTrack Desktop monitoring study): window titles and
  project names are never sent (`windowTitles` frozen to false, deprecated; removed in 2.0); team views show project
  aggregates by default and per-person detail only for a declared billing-evidence purpose, with the organisation's
  recorded opt-in and an audit record of every view; evidence is refused with `on_break` during register breaks and
  after clock-out (spec 198). Privacy-sensitive: a full audit before the tag.

## Planned: browser extension and desktop app (RGPD / LOPDGDD compliant)

Target: **TimeTrack 1.8 / 2.0**, with the extension and the desktop app in two private repositories. Requested by the
symfony-beacon host (Beacon `docs/ROADMAP.md`, *TimeTrack: browser extension + desktop app*), 2026-10-30.

### Token API

- `/api/time-track/…` endpoints for start / stop / switch, limited to the user's own entries.
- Personal access tokens or OAuth device-flow tokens: scoped, revocable and expiring.
- An *Integrations* page in the host with store links and signed downloads.

### Browser extension (MV3)

- Manifest V3 extension for Chrome, Edge and Firefox with a toolbar timer.
- Optional task suggestion from the active tab: **off by default**, matched locally, never sends full URLs (VaultBundle
  extension pattern).

### Desktop app (Tauri)

- Windows / macOS / Linux tray timer and global shortcut.
- Offline queue with sync when the connection returns.
- Local idle detection that **only asks** the user what to do with idle time (never edits entries on its own).
- Auto-update with signed update packages (updater signatures; the installers are not OS code-signed).

### Compliance by design

The clients record **working time** (art. 34.9 Estatuto de los Trabajadores). Monitoring (LOPDGDD arts. 87–91, spec
199) is **off unless your organisation enables it with a recorded DPIA; you see it first**:

- No screenshots, no application or activity logging unless the organisation enables monitoring with a recorded DPIA
  (opt-in, 1.26), and then only what its policy lists, only while the worker's own timer runs and the worker is not on
  a break or clocked out (1.41); the worker sees the notice and what is captured first. Never keystrokes, clipboard,
  webcam, microphone, location, files or window titles (1.41); no URL logging in 1.26. Managers see project
  aggregates; per-person detail only for a declared billing-evidence purpose, and every such view is recorded (1.41).
  The activity level is a level per slot, never a score or a ranking.
- Every optional feature is opt-in, with dated consent.
- Worker information text and a DPIA template shipped with the documentation.
- Right to disconnect (LOPDGDD art. 88): no auto-start, no notifications outside the configured working hours.
- Data minimisation and a *What we collect* screen in every client.
- Workers can export and delete their own entries.
- 4-year retention of working-time records.
- Audit of who views whose data.
- Tokens stored in the OS keychain.
- CORS / CSRF scoped to the extension origins.
- Opt-in, EU-hosted crash reporting.
- A `PRIVACY.md` in each client repository.

---

## Feature gap review (2026-11-18)

A review of what a time-tracking product is usually expected to offer, measured against what the bundle has (1.31.0)
and the clients (desktop 0.6.0, extension 0.4.0). The legal working-hours register, leave and holidays belong to
[WorkingTimeBundle](https://github.com/nowo-tech/WorkingTimeBundle) and are reviewed in its roadmap; this list covers
task time. Items already planned above (1.26 websites / domains, 2.0) are not repeated. Each item becomes a Spec Kit
feature with its own spec, tests and audit; the order inside each horizon is the priority. Client-side items are in
the [desktop roadmap](https://github.com/nowo-tech/time-track-desktop/blob/main/docs/ROADMAP.md).

### Next

**Projects, clients and rates**

| Item | What it is | Why it helps |
| --- | --- | --- |
| **Task effort on the client API** | `estimateSeconds`, `loggedSeconds` and `remainingSeconds` on `GET tasks` and `GET timer` through optional `TaskReference` fields (`features.task_effort`), as the desktop roadmap requests | The clients wait for it to show estimate and remaining time; the data is already in TaskBoard |
| **Project and client grouping** | A host port that maps a task to a project and a client (ids and labels), stored as snapshots on the entry like `boardIdSnapshot` | Reports, rates and budgets need a level above the task; today the board snapshot is the only grouping |
| **Tags on entries** | A short list of tenant-defined tags per entry, editable like `billable`, filterable in reports and exports | Separates kinds of work (meeting, review, support) without new tasks |
| **Hourly rates** | Billable and cost rates per tenant, project, task or person with effective dates, applied to entries as amounts at read time; never changes approved periods | Turns tracked time into money for billing and profitability, the next step after the `billable` flag |
| **Budget versus actual** | Hours or amount budgets per project or task with alerts at 80 % and 100 % (planned with TaskBoard, see 1.22) | Overruns are seen while they can still be fixed |

**Reports and dashboards**

| Item | What it is | Why it helps |
| --- | --- | --- |
| **Team reports** | Summary and detailed reports grouped by person, task, project, client, tag and billable, with saved filters and CSV / PDF export, for people the viewer may see (`TeamAccessGuard`) | The reports page lists the viewer's own entries only; managers export by hand |
| **Personal dashboard** | Today, this week and this month by task and project, against the working-time target when a calendar source is set | Gives the worker the overview the clients cannot show on a small screen |
| **Scheduled reports** | A weekly report e-mailed to the person, or to a manager for their team, through the host's notifier | Keeps people informed without opening the app; only for data the recipient may see |

**Timesheets and reminders**

| Item | What it is | Why it helps |
| --- | --- | --- |
| **Submit reminders** | A reminder near the end of the period to submit the timesheet, and to managers about timesheets waiting for approval, inside working hours only | Fewer late periods and fewer manual follow-ups (digital disconnection respected) |
| **Missing-time hints** | Days with less tracked task time than the working-time target, listed on the timesheet before submit | Catches forgotten timers while the person still remembers the day |
| **Required fields policy** | Per project or tenant: description, tag or task required before an entry is saved | Billing and audits need complete entries |

**API and integrations**

| Item | What it is | Why it helps |
| --- | --- | --- |
| **Webhooks** | Signed outgoing events for entries created / changed / removed, timesheets submitted / approved / locked | Billing, payroll and project systems react without polling |
| **Entries API for integrations** | A token API (ApiToken) to list and create entries for systems acting for a tenant, distinct from the personal client API | Imports from other tools and exports to accounting without custom controllers |

### Later

| Item | What it is | Why it helps |
| --- | --- | --- |
| **Invoice drafts** | From approved billable entries and rates, a draft invoice (lines per task or project) handed to the host's invoicing, e-invoice included | Closes the loop from time to billing; only if the host bills by the hour (the bundle does not issue invoices itself) |
| **Capacity view** | Planned hours per person (from the working-time calendar and estimates) against tracked hours, per week | Shows overload before it turns into overtime |
| **Bulk edit and duplicate** | Change task, tag or billable on many entries at once; copy last week's entries as a draft | Saves time on repetitive timesheets |
| **Favourites** | Pinned task + tag + billable combinations shared by web and clients | One-click start for frequent work |
| **Expenses next to time** | Optional expenses per task or project with a receipt (StorageKit), in reports and invoice drafts | Projects billed with costs need both in one place |
| **Issue-tracker integrations** | Map an issue key in a repository host to a task (with the extension button of the desktop roadmap) and show the logged time back on the issue | Developers track where they already work |
| **Mobile web timer** | A phone-first timer page with the offline queue the desktop uses | People away from a computer can track too (a native app is not planned) |

### Considering

Anything that reads activity follows the 1.26 rule: off unless the organisation enables it with a recorded DPIA,
the worker sees it first, and nothing is a score. Productivity scoring and keystroke capture stay non-goals (below).

| Item | What it is | Why it helps | Privacy and DPIA |
| --- | --- | --- | --- |
| **Assisted timesheet drafts** | Deterministic rules (calendar, meetings, Git keys) propose entries the worker confirms | Less typing at the end of the week | Only from data the worker already shared; nothing saved without confirmation |

---

## Non-goals

- Productivity scoring and covert monitoring. Activity monitoring is off unless your organisation enables it with a
  recorded DPIA; you see it first.

## Requested for CrmKit (Beacon spec 254, 2026-11-23)

Beacon spec 254 "CRM HubSpot parity" (owner decision D-20261123-11) and the owner's reuse rule of 2026-11-23 ("if a
nowo bundle already does, or nearly does, a CRM capability, use it or extend it") send these pieces here instead of
building CRM-only copies in CrmKitBundle. Each piece is opt-in, generic (no CRM dependency) and gets its own contract
revision before it is built. Versions are planned next minors and move if this bundle releases something else first.
Plan and reuse matrix: [CrmKitBundle docs/ROADMAP.md](https://github.com/nowo-tech/CrmKitBundle/blob/main/docs/ROADMAP.md#reuse-matrix).

| Version | Piece | Used by |
| --- | --- | --- |
| 1.40.0 | **Extra mail scopes on the PKCE helpers**: the Google / Microsoft OAuth helpers (`CalendarOAuthClients`; Google's scopes are a constant today) accept configurable extra scopes (Graph mail, Gmail), so one consent per person and provider covers the calendar and the mailbox. Tokens stay host-held: the host keeps one connection per person and provider, encrypted with DoctrineEncrypt, and hands MailKit the access token through MailKit's token port; MailKit has no OAuth flow of its own (Q-254-15, Decision 2026-11-23: (b)) | CrmKit B through MailKit 2.5 / 2.6 |
| 1.41.0 | **Busy-window reader**: free / busy for a future window (start and end only, never titles or attendees), next to the read-only past-event readers (Google free/busy, Graph getSchedule; scopes settled in the contract revision) | CrmKit I booking links |

Reused as they are: the read-only calendar readers and the meeting read model (Outlook / Google meetings on a CRM
record's timeline through the host).
