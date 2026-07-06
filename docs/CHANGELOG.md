# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-07-06

### Added

- **Symfony bundle** for personal and team time tracking (PHP **8.2+**, Symfony **7.4+ / 8.0 / 8.1+**).
- **Web UI** — timer at `/tools/time-track`, reports at `/tools/time-track/reports` (EN/ES translations, Twig templates).
- **Doctrine entities** — `TimeEntry`, `ActiveTimer` (one active timer per user), `ClientToken` (hashed Bearer tokens).
- **TimerService** — start/stop/heartbeat, task listing, entry queries; **409** on active-timer conflict.
- **REST client API** — Bearer auth, CORS, login rate limiting for browser extension and desktop agent (`clients.enabled`).
- **Integration interfaces** — `TaskProviderInterface`, `TeamContextProviderInterface`.
- **Default bridges** — `StubTaskProvider` (in-memory demo tasks), `NullTeamContextProvider`.
- **TeamAccessGuard** — admin/manager ACL for viewing team entries.
- **Symfony events** — `timer.start`, `timer.stop`, `time_entry.list_query`, `time_entry.access_check`.
- **Console command** — `nowo:time-track:client-tokens:purge` (expired Bearer cleanup).
- **Symfony 8.1 Docker demo** integrated with **TaskBoardBundle** (`make -C demo up-symfony8`, port **8024**).
- **Chrome MV3 extension MVP** (`extension/chrome/`, v0.1.0) — login, task list, start/stop timer.
- **Tauri 2 desktop agent scaffold** (`desktop/`, v0.1.0) — system tray, idle heartbeat, start/stop timer.

### Configuration

- `nowo_time_track` tree: `user_class`, `table_prefix`, `route_prefix`, task/team providers, customizable routes and templates, security ACL, clients API (`token_ttl`, `idle_threshold_seconds`, CORS, rate limit).

### Testing

- **100%** PHPUnit line coverage on included bundle code (controllers, Doctrine repositories/listeners, and integration interfaces excluded by design).
- Unit tests for services, DI, routing, client auth, entities, events, and commands.

### Documentation

- Installation, configuration, usage, TaskBoard integration, browser extension, desktop agent, security, and spec-driven development guides.

[1.0.0]: https://github.com/nowo-tech/TimeTrackBundle/releases/tag/v1.0.0
