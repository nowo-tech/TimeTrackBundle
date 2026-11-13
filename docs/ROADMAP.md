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
- [Non-goals](#non-goals)

---

## Current state (v1.x)

Current release **v1.19.1** (2026-11-11) — see [CHANGELOG.md](CHANGELOG.md).

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
- **1.23** (spec 199; was 1.22, before that 1.21): the monitoring API, with a full audit. Off unless your organisation
  enables it with a recorded DPIA; you see it first.

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

- No screenshots, no keystrokes, no application or URL logging unless the organisation enables monitoring with a
  recorded DPIA, and then only what its policy lists; the worker sees the policy and what is captured first.
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

## Non-goals

- Productivity scoring and covert monitoring. Activity monitoring is off unless your organisation enables it with a
  recorded DPIA; you see it first.
