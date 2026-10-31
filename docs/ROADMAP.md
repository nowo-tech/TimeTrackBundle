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

Current release **v1.7.0** — see [CHANGELOG.md](CHANGELOG.md).

- Timer, time entries and timesheets for the host's tasks (task provider; [TaskBoard integration](TASK-BOARD-INTEGRATION.md)).
- Prototype clients: a minimal Chrome / Firefox extension under `extension/` ([BROWSER-EXTENSION.md](BROWSER-EXTENSION.md))
  and a Tauri tray agent under `desktop/` ([DESKTOP-AGENT.md](DESKTOP-AGENT.md)), both against `/api/time-track/…`.

---

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
- Signed installers and auto-update.

### Compliance by design

The clients record **working time** (art. 34.9 Estatuto de los Trabajadores), they do **not monitor** workers
(LOPDGDD arts. 87–91):

- No screenshots, no keystrokes, no application or URL logging.
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

- Productivity scoring, activity tracking or any form of employee surveillance.
