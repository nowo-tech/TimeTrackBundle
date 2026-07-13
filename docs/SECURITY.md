# Security

## Table of contents

- [Scope](#scope)
- [Attack surface](#attack-surface)
- [Threat model](#threat-model)
- [Client API](#client-api)
- [Reports](#reports)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)
- [Reporting](#reporting)

## Scope

TimeTrackBundle provides time tracking UI, team reports, and a Bearer-token client API for browser extensions.

## Attack surface

| Surface | Description |
| ------- | ----------- |
| Manage UI | Authenticated CRUD for time entries and reports |
| Client API | Bearer tokens for extension clients |
| Configuration | CORS origins, token TTL, table prefix |

## Threat model

| Threat | Mitigation |
| ------ | ---------- |
| Token theft | Tokens stored hashed (SHA-256); HTTPS in production |
| Brute force login | Rate limit per IP + username (`cache.app`) |
| IDOR on entries | `TimeEntryAccessCheckEvent` and team context provider |
| CORS abuse | Restrict `clients.cors_allowed_origins` in production |

## Client API

- Bearer tokens stored hashed (SHA-256) in `{prefix}_client_tokens`
- Rate limit login attempts per IP + username (`cache.app`)
- CORS: `chrome-extension://`, `moz-extension://`, `tauri://` by default; no `*` in production
- Purge expired tokens: `php bin/console nowo:time-track:client-tokens:purge`

## Reports

- Managers access team data via `TeamContextProviderInterface`
- `TimeEntryAccessCheckEvent` for custom ACL

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

Record confirmation in the release PR or tag notes.

## Reporting

Report vulnerabilities privately to **hectorfranco@nowo.tech**. Do not open public GitHub issues for security-sensitive bugs.

Operational checklist for integrators:

- [ ] `clients.cors_allowed_origins` restricted in production
- [ ] HTTPS only
- [ ] Cron for token purge
- [ ] Wire TaskBoardBundle ACL in `task_provider`
