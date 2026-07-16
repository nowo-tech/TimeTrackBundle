# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.5] - 2026-07-16

### Added

- **Translations** — UI locales **de**, **fr**, **it**, **nl**, **pt** (in addition to **en** / **es**).
- **CODE_OF_CONDUCT.md** — Contributor Covenant; linked from README and CONTRIBUTING.
- **docs/GITHUB_CI.md** — GitHub Actions CI requirements, including REQ-GIT-001 (no Cursor co-author trailers).
- **Git hygiene** — `.githooks/commit-msg`, `.scripts/check-no-cursor-coauthor.sh`, `.scripts/strip-cursor-coauthor-from-history.sh`, Cursor rule `01-git-commits.mdc`, and CI job `git-hygiene`.
- **Makefile** — `check-no-cursor-coauthor`, `setup-hooks`, `strip-cursor-coauthor-from-history`; `release-check` runs the co-author check first.

### Changed

- **docs/CONTRIBUTING.md** — Code of Conduct and git-hook setup for REQ-GIT-001.
- **docs/RELEASE.md** — Reminder to re-run `make check-no-cursor-coauthor` before pushing the release commit/tag.
- **es** translations — nav/page strings fully localized.

## [1.0.4] - 2026-07-13

### Added

- **`.scripts/php-coverage-percent.sh`** — prints colored PHP line-coverage percentage after `make test-coverage` (REQ-TEST-008).
- **`.cursor/rules/`** — Cursor agent rules for PHP/Symfony bundle, tests, docs, and release workflow.
- **`.cursorignore`** — reduces indexer noise (vendor, caches, locks, secrets).

### Changed

- **Makefile** — `test-coverage` pipes PHPUnit output to `coverage-php.txt` and runs the coverage-percent script.
- **docs/SECURITY.md** — scope, attack surface, threat model, release security checklist (12.4.1), and private reporting contact.
- **`.gitignore`** — ignores `.cursor/sandbox.json` and `/coverage-php.txt`.
- **composer.lock** / **demo/symfony8/composer.lock** — PHP CS Fixer 3.95.13.

### Fixed

- **demo/symfony8** — `config/reference.php` keeps `declare(strict_types=1)` (post-release CS Fixer alignment).

## [1.0.3] - 2026-07-08

### Added

- **GitHub Spec Kit** scaffolding — `.specify/` templates and scripts, Cursor `/speckit-*` skills, baseline feature `specs/001-baseline/` (spec + code inventory).
- **docs/SPEC-KIT.md** — installation, folder layout, Cursor Agent usage, and maintainer checklist.

### Changed

- **docs/SPEC-DRIVEN-DEVELOPMENT.md** — expanded workflow aligned with Spec Kit and baseline traceability.
- **README** — link to [GitHub Spec Kit](docs/SPEC-KIT.md) documentation.
- **composer.lock** / **demo/symfony8/composer.lock** — PHP CS Fixer 3.95.12 and aligned PHPUnit dev dependencies.

### Documentation

- Baseline specification documents current bundle scope under `specs/001-baseline/` (repository only; excluded from integrator runtime).

## [1.0.2] - 2026-07-07

### Changed

- **demo/symfony8** — `config/reference.php` includes `declare(strict_types=1)` (demo only; no Composer package API change).

## [1.0.1] - 2026-07-06

### Fixed

- **CI** — Code Style job scopes auto-commit to CS Fixer paths (`src`, `tests`, `.php-cs-fixer.dist.php`, `demo`) and skips empty commits when only transient files (e.g. `composer.json`) are dirty.
- **demo/Makefile** — `release-check` reads `PORT` from `.env` (falls back to `.env.example`) so the healthcheck matches the running container.

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

[1.0.5]: https://github.com/nowo-tech/TimeTrackBundle/releases/tag/v1.0.5
[1.0.4]: https://github.com/nowo-tech/TimeTrackBundle/releases/tag/v1.0.4
[1.0.3]: https://github.com/nowo-tech/TimeTrackBundle/releases/tag/v1.0.3
[1.0.2]: https://github.com/nowo-tech/TimeTrackBundle/releases/tag/v1.0.2
[1.0.1]: https://github.com/nowo-tech/TimeTrackBundle/releases/tag/v1.0.1
[1.0.0]: https://github.com/nowo-tech/TimeTrackBundle/releases/tag/v1.0.0
