# FrankenPHP demo

The Symfony 8 demo runs as a **single FrankenPHP container** (no separate Nginx).

## Quick start

```bash
make -C demo up-symfony8
```

Default URL: see `demo/symfony8/.env.example` (`PORT`, typically `http://localhost:8024/tools/time-track`).

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

## Healthcheck

```bash
make -C demo verify-symfony8
# or: curl -sf http://localhost:$PORT/tools/time-track
```

## Worker mode note

This bundle is **FrankenPHP worker mode friendly**. Keep sessions/security config correct when using long-lived workers.
