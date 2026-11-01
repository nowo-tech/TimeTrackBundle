# Security

## Table of contents

- [Scope](#scope)
- [Attack surface](#attack-surface)
- [Threat model](#threat-model)
- [Client API](#client-api)
- [Reports](#reports)
- [Secrets & cryptography](#secrets--cryptography)
- [Logging](#logging)
- [Dependency and updates](#dependency-and-updates)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)
- [Reporting](#reporting)

## Scope

TimeTrackBundle provides time tracking UI, team reports, and a Bearer-token client API for browser extensions.

## Attack surface

| Surface | Description |
| ------- | ----------- |
| Manage UI | Authenticated timer page (POST start / stop) and reports list (`from`, `to`, `page` query parameters) behind `TimeTrackAccessCheckerInterface` + `security.access_roles` |
| Client API | Bearer tokens for extension clients |
| Configuration | CORS origins, token TTL, table prefix |

## Threat model

| Threat | Mitigation |
| ------ | ---------- |
| Token theft | Tokens stored hashed (SHA-256); HTTPS in production |
| Brute force login | Rate limit per IP + username (`cache.app`); unknown usernames still run a password hash check (no timing oracle) |
| IDOR on entries | `TimeEntryAccessCheckEvent` and team context provider |
| CORS abuse | Restrict `clients.cors_allowed_origins` in production |
| CSRF on manage UI | Manage POST start/stop require Symfony CSRF token `nowo_time_track_manage` |
| Unauthenticated manage UI | Keep `security.allow_unauthenticated: false` in production; demo-only bypass wires AllowAll checker |

## Client API

- Bearer tokens stored hashed (SHA-256) in `{prefix}_client_tokens`
- Rate limit login attempts per IP + username (`cache.app`)
- CORS: only `tauri://` plus the exact `clients.cors_allowed_origins` by default; any `chrome-extension://` / `moz-extension://` only with `clients.cors_allow_any_extension_origin: true`; no `*` in production; never `Access-Control-Allow-Credentials` (Bearer tokens)
- Login throttle: per IP + username and per IP (`max_attempts_per_ip`, default 5 × `max_attempts`)
- Unbounded queries: `tasks` `limit` capped at 100; `entries` `from`/`to` parsed strictly and capped at 366 days
- Purge expired tokens: `php bin/console nowo:time-track:client-tokens:purge`

## Reports

- Managers access team data via `TeamContextProviderInterface`
- `TimeEntryAccessCheckEvent` for custom ACL
- Bounded list (REQ-PERF-001): the reports page shows `reports.page_size` entries per page (default 50, max 500),
  paged in the database by `DoctrineOrmTimeEntryRepository`; out-of-range `page` values are clamped to the existing
  pages; the period is capped at 366 days

## Secrets & cryptography

- **Client Bearer tokens:** 32 random bytes (`random_bytes`), base64url; only the SHA-256 hash is stored
  (`{prefix}_client_tokens.token_hash`), so a database dump does not reveal usable tokens. The plain token is returned
  once by `POST /api/time-track/login` and never stored, logged or rendered.
- **Passwords:** checked by the host password hasher (`security.user_password_hasher`) or the host
  `ClientAuthenticatorInterface`; unknown usernames still run a dummy hash check (no timing oracle).
- **Rate-limit keys:** IP and username are hashed (SHA-256) before they become cache keys.
- **No secrets in the package:** no API keys, no default credentials outside the demo (`demo/symfony8/.env.example`
  holds local-only placeholder values; `.env` / `.env.dev` are gitignored). Never commit a real `APP_SECRET`.

## Logging

Time entries are personal data (GDPR / LOPDGDD: who worked on what and for how long), so the bundle logs **operations,
not people**. Services receive a PSR-3 logger (Monolog channel `time_track` when MonologBundle is installed; no logging
otherwise):

| Record | Level | Context |
| --- | --- | --- |
| `TimeTrack timer started.` / `TimeTrack timer stopped.` | info | `bundle`, `action`, `client` (web / extension / desktop) |
| `TimeTrack timer start rejected: concurrent start.` | warning | same |
| `TimeTrack client login succeeded.` / `TimeTrack client token revoked.` | info | same |
| `TimeTrack client login failed.` | notice | same |
| `TimeTrack client login throttled.` | warning | same |
| `TimeTrack expired client token purge started / finished / failed.` | info / error | `bundle`, `action`, `purged` count, `exception_class` on failure |

Never logged: user ids, usernames, e-mails, passwords, tokens (plain or hashed), client IPs, task ids and titles, board
ids, durations, timer metadata, request bodies. Hosts that need an audit trail of time entries should listen to
`nowo_time_track.timer.start` / `nowo_time_track.timer.stop` and store it under their own retention policy.

## Dependency and updates

- `composer audit` runs in CI on every push / PR (job *Composer audit*) and is part of the release checklist below;
  a known advisory blocks the release.
- Dependabot (`.github/dependabot.yml`) opens grouped updates for Composer and GitHub Actions weekly; security
  updates are merged and released as a patch as soon as CI is green.
- Supported versions and private reporting: [`.github/SECURITY.md`](../.github/SECURITY.md).

## Release security checklist (12.4.1)

Before tagging a release, confirm:

| Item | Notes |
| ---- | ----- |
| **SECURITY.md** | Current and linked from README |
| **`.gitignore` / `.env`** | No secrets committed |
| **Input / output** | Validation on API payloads; Twig auto-escape |
| **Dependencies** | `composer audit` run |
| **Logging** | No raw tokens in logs |
| **Permissions** | Firewall + roles on manage routes; CORS restricted in prod |
| **Limits** | Rate limits on client login; token purge documented |
| **CSRF** | Manage UI mutations use CSRF tokens |
| **AI security audit (REQ-SEC-004)** | Pass (conditional) recorded in monorepo `BUNDLES_SECURITY_ANALYSIS.md` |

Record confirmation in the release PR or tag notes.

## Reporting

Report vulnerabilities privately to **hectorfranco@nowo.tech**. Do not open public GitHub issues for security-sensitive bugs.

Operational checklist for integrators:

- [ ] `clients.cors_allowed_origins` restricted in production
- [ ] HTTPS only
- [ ] Cron for token purge
- [ ] Wire TaskBoardBundle ACL in `task_provider`
