# Upgrading

This document describes how to upgrade between versions of TimeTrack Bundle.

## 1.2.0 (2026-08-03)

Minor release: REQ-UI-002 manage-UI access checker (`access_roles` / `access_checker` / `allow_unauthenticated`) plus SecurityBundle compile-time guard. Demo no longer path-mounts sibling optional bundles.

### Install / update

```bash
composer require nowo-tech/time-track-bundle:^1.2
php bin/console cache:clear
```

### Behaviour change (manage roles)

| Topic | Before | 1.2.0 |
| --- | --- | --- |
| Default manage gate | `#[IsGranted('ROLE_USER')]` on controller | `TimeTrackAccessCheckerInterface` driven by `security.access_roles` (default `[ROLE_USER]`) |
| Apps without SecurityBundle | Could boot | Boot fails with `LogicException` unless `allow_unauthenticated: true` |

**Demos / trusted local kernels** without SecurityBundle:

```yaml
nowo_time_track:
    security:
        allow_unauthenticated: true   # never in production
```

**Production** (recommended): keep `allow_unauthenticated: false`, ensure SecurityBundle is installed, and grant at least one of `access_roles` (or provide a custom `access_checker`).

### New optional config

```yaml
nowo_time_track:
    security:
        access_roles: [ROLE_USER]
        access_checker: null
        allow_unauthenticated: false
```

### Breaking changes

Apps that load the manage UI without SecurityBundle must either install/configure SecurityBundle or set `allow_unauthenticated: true` for non-production use. Hosts that relied only on firewall `access_control` without matching `access_roles` should align roles.

## 1.1.0 (2026-07-30)

Host layout / CSS stack integration without forking timer pages (REQ-UI-001). Aligned with TaskBoardBundle **1.3.0**.

```bash
composer require nowo-tech/time-track-bundle:^1.1.0
php bin/console cache:clear
```

### Layout integration (REQ-UI-001)

- Timer/report pages now `{% extends nowo_time_track_layout %}` (Twig global from `nowo_time_track.templates.layout`).
- **Hosts:** set `templates.layout` to the project layout (or a one-file bridge). Do not fork `index.html.twig` / `reports.html.twig` only to change chrome.
- Custom Twig overrides that still hard-code `@NowoTimeTrackBundle/layout.html.twig` should switch to the global so config takes effect.
- Styles/scripts blocks on pages call `{{ parent() }}` so host assets remain when using the project layout.

### CSS framework (REQ-UI-001)

- New config `templates.css_framework` (default **`tabler`**) exposed as Twig global `nowo_time_track_css_framework`.
- Accepted values: `bootstrap`, `bootstrap4`, `bootstrap5`, `tabler`, `tailwind`, `foundation`, `custom`, `none`.
- Align with TaskBoardBundle (`templates.css_framework`). Set the same value as your host admin stack; with `custom`, keep Bootstrap/Tabler-compatible class names styled by the host.

```yaml
nowo_time_track:
    templates:
        layout: 'base.html.twig'
        css_framework: tabler   # or bootstrap5 | custom
```

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

## Unreleased

## To 1.3.0

From **1.2.0** — Adds FormKit and/or UiKit where applicable, Twig Extra (REQ-TWIG-004), and Twig-CS-Fixer. Register TwigExtraBundle, NowoFormKitBundle, and NowoUiKitBundle if Flex did not. See CHANGELOG.

```bash
composer update nowo-tech/time-track-bundle
php bin/console cache:clear
```

### Twig Extra Bundle (REQ-TWIG-004)

Hosts that render this bundle's Twig templates must install:

```bash
composer require twig/extra-bundle twig/string-extra
```

and enable `Twig\Extra\TwigExtraBundle\TwigExtraBundle`. Flex recipes usually register it automatically.

### Twig-CS-Fixer (maintainers)

Package maintainers: `composer twig:lint` / `composer twig:fix` use `.twig-cs-fixer.php` over `src/` (and `templates/` when present).

