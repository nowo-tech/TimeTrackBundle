# TimeTrack Bundle

[![CI](https://github.com/nowo-tech/TimeTrackBundle/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/TimeTrackBundle/actions/workflows/ci.yml) [![Packagist Version](https://img.shields.io/packagist/v/nowo-tech/time-track-bundle.svg?style=flat)](https://packagist.org/packages/nowo-tech/time-track-bundle) [![Packagist Downloads](https://img.shields.io/packagist/dt/nowo-tech/time-track-bundle.svg)](https://packagist.org/packages/nowo-tech/time-track-bundle) [![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE) [![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php)](https://php.net) [![Symfony](https://img.shields.io/badge/Symfony-7.4%2B%20%7C%208.0%20%7C%208.1%2B-000000?logo=symfony)](https://symfony.com) [![GitHub stars](https://img.shields.io/github/stars/nowo-tech/time-track-bundle.svg?style=social&label=Star)](https://github.com/nowo-tech/TimeTrackBundle) [![Coverage](https://img.shields.io/badge/Coverage-100%25-brightgreen)](#tests-and-coverage)

> ⭐ **Found this useful?** Give it a **star** on [GitHub](https://github.com/nowo-tech/TimeTrackBundle) so more developers can find it.

Symfony bundle for **personal and team time tracking**: one active timer per user, task references via `TaskBoardBundle`, browser extension API, and Tauri desktop agent API.

## Features

- Web UI at `/tools/time-track` (timer + reports)
- REST API for browser extension and Tauri desktop (`Bearer` auth)
- Single active timer per user (409 on conflict)
- Teams and managers v1 via `TeamContextProviderInterface`
- Task integration via `TaskProviderInterface` (designed for `nowo-tech/task-board-bundle`)
- Symfony events for timer start/stop and entry ACL

## Installation

```bash
composer require nowo-tech/time-track-bundle
```

```yaml
# config/packages/nowo_time_track.yaml
nowo_time_track:
    user_class: App\Entity\User
    clients:
        enabled: true
```

See [Installation](docs/INSTALLATION.md).

## Demo

Integrated demo with **TaskBoardBundle** (kanban + timer):

```bash
make -C demo up-symfony8
# Demo started at: http://localhost:8024/tools/time-track
```

- Timer UI: `/tools/time-track`
- Task board: `/tools/task-board`
- Login: `demo@example.com` / `demo`

With TaskBoard only, configure `nowo_time_track.task_provider: nowo_task_board.task_provider` — see [TaskBoard integration](docs/TASK-BOARD-INTEGRATION.md).

## Documentation

- [GitHub Actions CI requirements](docs/GITHUB_CI.md)
- [Installation](docs/INSTALLATION.md)
- [Configuration](docs/CONFIGURATION.md)
- [Usage](docs/USAGE.md)
- [Browser extension](docs/BROWSER-EXTENSION.md)
- [Desktop agent (Tauri)](docs/DESKTOP-AGENT.md) — `desktop/` tray app
- [TaskBoard integration](docs/TASK-BOARD-INTEGRATION.md)
- [Contributing](docs/CONTRIBUTING.md)
- [Code of Conduct](CODE_OF_CONDUCT.md)
- [Changelog](docs/CHANGELOG.md)
- [Upgrading](docs/UPGRADING.md)
- [Release process](docs/RELEASE.md)
- [Security](docs/SECURITY.md)
- [Engram](docs/ENGRAM.md)
- [Spec-driven development](docs/SPEC-DRIVEN-DEVELOPMENT.md)
- [GitHub Spec Kit](docs/SPEC-KIT.md)

## Tests and coverage

```bash
make test
make test-coverage
make release-check
```

- **PHP:** 100% lines (justified exclusions: controllers, Doctrine repositories/listeners, integration interfaces)
- **TS/JS:** N/A (desktop agent lives in `desktop/`, outside bundle PHP coverage)
- **Python:** N/A

## License

MIT — see [LICENSE](LICENSE).
