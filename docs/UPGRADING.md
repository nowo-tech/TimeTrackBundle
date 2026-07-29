# Upgrading

This document describes how to upgrade between versions of TimeTrack Bundle.

## 1.0.x patch releases

### 1.0.6 (2026-07-29)

- **Security** — Manage UI start/stop POSTs require CSRF token `nowo_time_track_manage`. Update custom Twig overrides of the timer UI to include the token (see demo/`index.html.twig`).
- FrankenPHP banner / demo PHP **8.5**; CI matrix Symfony **7.4 / 8.0 / 8.1** (Symfony **7.0** dropped from CI — package constraints unchanged unless you relied on CI-only coverage).
- Contributors: PHPStan baseline removed; `phpstan-frankenphp` rulesets after `composer install`.

```bash
composer update nowo-tech/time-track-bundle
php bin/console cache:clear
```

### 1.0.5 (2026-07-16)

- **Translations** — New locale files for **de**, **fr**, **it**, **nl**, **pt**. No config changes required; Symfony picks them up when the app locale matches.
- **Repository-only** — Code of Conduct, GitHub CI docs, and git hygiene tooling (REQ-GIT-001). No bundle API, config key, or migration changes.

```bash
composer update nowo-tech/time-track-bundle
```

### 1.0.4 (2026-07-13)

Repository-only: security documentation, coverage reporting script, Cursor rules, and dev lockfile updates. No bundle API, config key, or migration changes for consumers.

```bash
composer update nowo-tech/time-track-bundle
```

### 1.0.3 (2026-07-08)

Repository-only: GitHub Spec Kit scaffolding, baseline specs, and documentation. No bundle API, config key, or migration changes for consumers.

```bash
composer update nowo-tech/time-track-bundle
```

### 1.0.2 (2026-07-07)

Repository-only: demo `reference.php` strict-types alignment. No bundle API, config key, or migration changes.

```bash
composer update nowo-tech/time-track-bundle
```

### 1.0.1 (2026-07-06)

Repository-only: CI Code Style job fix and demo `reference.php` strict-types. No bundle API, config key, or migration changes for consumers.

```bash
composer update nowo-tech/time-track-bundle
```

## 1.0.0 (2026-07-06)

First stable release. No upgrade steps required.

### Requirements

- **PHP** >= 8.2 (Symfony 8.x requires PHP 8.4+).
- **Symfony** ^7.4 || ^8.0.
- **Doctrine ORM** ^2.15 || ^3.0 with `doctrine/doctrine-bundle` ^2.10 || ^3.0.

### Installation checklist

1. Install the package:

   ```bash
   composer require nowo-tech/time-track-bundle
   ```

2. Configure `user_class` and enable clients if you use the extension or desktop agent:

   ```yaml
   # config/packages/nowo_time_track.yaml
   nowo_time_track:
       user_class: App\Entity\User
       clients:
           enabled: true
   ```

3. Run Doctrine migrations (see demo migration `demo/symfony8/migrations/Version20250706120000.php` for reference schema).

4. Add security rules — Bearer auth for `/api/time-track/*`, session auth for `/tools/time-track`:

   ```yaml
   # config/packages/security.yaml
   security:
       access_control:
           - { path: ^/api/time-track, roles: PUBLIC_ACCESS }
           - { path: ^/tools/time-track, roles: ROLE_USER }
   ```

5. **Optional:** wire **TaskBoardBundle** providers — see [TaskBoard integration](TASK-BOARD-INTEGRATION.md).

### Client apps (repository only)

- **Browser extension** — load unpacked from `extension/chrome/`; enable `clients.enabled` and CORS on the host app.
- **Desktop agent** — build from `desktop/` with Tauri 2; default demo API URL is `http://localhost:8024`.

Neither client is part of the Packagist archive (`demo/` and client folders are for development and integration testing).
