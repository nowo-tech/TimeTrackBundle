# FrankenPHP demo

**REQ-DEMO-001:** FrankenPHP demos must install **Nowo Twig Inspector** and **Nowo Hot Reload** together (`nowo-tech/twig-inspector-bundle` + `nowo-tech/hot-reload-bundle` in `require-dev`). Caddyfile: Mercure + `hot_reload` (and `worker { file …; watch }` in worker mode). Do not enable Hot Reload in production.

The Symfony 8 demo runs as a **single FrankenPHP container** (no separate Nginx).

## Table of contents

- [Quick start](#quick-start)
- [`FRANKENPHP_MODE` (classic vs worker)](#frankenphp_mode-classic-vs-worker)
- [PHP version (Symfony 8)](#php-version-symfony-8)
- [Development tips](#development-tips)
- [Start in production mode](#start-in-production-mode)
- [Healthcheck](#healthcheck)
- [Troubleshooting](#troubleshooting)
- [Worker mode note](#worker-mode-note)

## Quick start

```bash
make -C demo up-symfony8
```

`make -C demo/symfony8 up` ends with `Demo started at: http://localhost:<PORT>` (`PORT` from `demo/symfony8/.env`, else
`.env.example`; default `8024`); the line before it prints the timer page (`/tools/time-track`). Login:
`demo@example.com` / `demo`.

Other demo targets (`make -C demo/symfony8 <target>`): `down`, `restart`, `ensure-up`, `setup` (database, migrations,
fixtures), `test`, `test-coverage`, `update-bundle`, `verify`, `release-check` (update-bundle + verify).

## `FRANKENPHP_MODE` (classic vs worker)

| Value | Behavior |
| --- | --- |
| `worker` (default) | Production-style Caddyfile with `php_server { worker … }` |
| `classic` | `Caddyfile.dev` — per-request PHP (easier hot-reload) |

Set in `.env` (from `.env.example`), then recreate: `docker compose up -d` (no image rebuild).

## PHP version (Symfony 8)

Demo image: `dunglas/frankenphp:1-php8.5-bookworm` (newest FrankenPHP PHP minor compatible with demo `require.php`).

## Development tips

- Prefer `FRANKENPHP_MODE=classic` while iterating on Twig/controllers.
- Bundle path repo is mounted for live `src/` changes — see `demo/symfony8/composer.json`.

## Start in production mode

The demo is a development app (Twig Inspector, Hot Reload, WebProfiler, demo auto-login). To check it with the
production kernel and worker mode:

1. In `demo/symfony8/.env` set `APP_ENV=prod`, `APP_DEBUG=0`, `FRANKENPHP_MODE=worker` and a real `APP_SECRET`.
2. Recreate the container and warm the production cache:

```bash
cd demo/symfony8
docker compose up -d --force-recreate php
docker compose exec php php bin/console cache:clear --env=prod
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction --env=prod
```

In a real application use the bundle's own security configuration (`security.access_roles`, a real firewall), keep
`security.allow_unauthenticated: false`, restrict `clients.cors_allowed_origins`, serve over HTTPS and schedule
`nowo:time-track:client-tokens:purge` (see [SECURITY](SECURITY.md)). Switch back with `APP_ENV=dev`, `APP_DEBUG=true`
and `docker compose up -d --force-recreate php`.

## Healthcheck

```bash
make -C demo verify-symfony8
# or: curl -sf http://localhost:$PORT/tools/time-track
```

## Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| `Could not resolve host: repo.packagist.org` (curl error 6) during `composer install` | Docker / WSL DNS inside the container | The PHP service sets `dns: 8.8.8.8 / 8.8.4.4`; on restricted networks use your resolvers in a local `docker-compose.override.yml` |
| `Bind for 0.0.0.0:8024 failed: port is already allocated` | Another container or process uses `PORT` | Change `PORT` in `demo/symfony8/.env`, then `make -C demo/symfony8 up` |
| `SQLSTATE[HY000] [2002] Connection refused` on the first request | MySQL is still starting | Wait for the `mysql` healthcheck (`docker compose ps`), then `make -C demo/symfony8 setup` |
| Table `time_track_entries` not found | Migrations not run | `make -C demo/symfony8 setup` |
| Bundle code changes do not show up | Worker keeps the old code / stale container cache | `make -C demo/symfony8 update-bundle`; or set `FRANKENPHP_MODE=classic` while iterating |
| Timer page answers 403 / redirects | The demo auto-login (`App\Security\DemoAutoLoginAuthenticator`) found no `demo@example.com` user | Load the fixtures: `make -C demo/symfony8 setup` |
| The task list is empty | No task provider data | Load the fixtures (`make -C demo/symfony8 setup`); the demo uses TaskBoardBundle's provider |

## Worker mode note

This bundle is **FrankenPHP worker mode friendly**. Keep sessions/security config correct when using long-lived workers.
