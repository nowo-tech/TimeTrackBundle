# Client API reference (1.9, contract v0.2)

API of the browser extension ([nowo-tech/time-track-extension](https://github.com/nowo-tech/time-track-extension))
and the desktop app ([nowo-tech/time-track-desktop](https://github.com/nowo-tech/time-track-desktop)). Enabled with
`clients.enabled: true`; the device flow additionally with `client_api.device_flow.enabled: true`. Paths below are the
defaults (`clients.routes.*`, `route_prefix`). Host wiring: [USAGE](USAGE.md#host-integration-client-api-19); options:
[CONFIGURATION](CONFIGURATION.md#client-api-19); threat model: [SECURITY](SECURITY.md#client-api).

## Table of contents

- [Conventions](#conventions)
- [Credential model](#credential-model)
- [Scopes](#scopes)
- [Errors](#errors)
- [Endpoints](#endpoints)
  - [POST login](#post-login)
  - [POST logout](#post-logout)
  - [GET me](#get-me)
  - [GET tasks](#get-tasks)
  - [GET timer](#get-timer)
  - [POST timer/start](#post-timerstart)
  - [POST timer/stop](#post-timerstop)
  - [POST timer/switch](#post-timerswitch)
  - [POST timer/discard-idle](#post-timerdiscard-idle)
  - [POST heartbeat (deprecated)](#post-heartbeat-deprecated)
  - [GET entries](#get-entries)
  - [GET export](#get-export)
  - [POST device/code](#post-devicecode)
  - [POST device/token](#post-devicetoken)
- [Device verification page](#device-verification-page)

## Conventions

- JSON bodies (`Content-Type: application/json`), `Authorization: Bearer <token>` on every endpoint except `login`,
  `device/code` and `device/token`.
- **Stateless**: no session is read or started (also on `401` / `429`); no cookies. Works behind a `security: false`
  firewall.
- **CORS**: `Access-Control-Allow-Origin` only for exact origins in `client_api.allowed_origins` /
  `clients.cors_allowed_origins` (e.g. `chrome-extension://<id>`, `moz-extension://<uuid>`, `extension://<id>`); never
  `Access-Control-Allow-Credentials`. The desktop app (Tauri HTTP plugin) sends no `Origin`. Every route answers
  `OPTIONS` with `204`.
- **Rate limits**: per client IP on every route (`client_api.rate_limit.per_ip`, default 300/min), per token
  (`per_token`, 60/min), per IP on `device/*` (`device_per_ip`, 30/min); failed logins per IP + username and per IP
  (`clients.login_rate_limit`). Over the limit: `429 rate_limited` + `Retry-After`.
- Dates are RFC 3339 (`2026-11-02T09:30:00+01:00`, `Z` and milliseconds accepted where noted).

## Credential model

| Layer | Owner | What | Lifetime |
|---|---|---|---|
| Credential | Host (Beacon: app password) | Created, named, listed and revoked by the user in the host | Until revoked |
| Session token | Bundle (`ClientToken`) | Bearer the client holds, issued from a credential (`credentialId`, `scopes`, `issuedAt`); never listed on its own | `clients.token_ttl` |

Every authenticated request asks the host `ClientCredentialStateInterface::isActive($token)`; deleting a credential
calls `ClientTokenRevoker::revokeByCredential()`.

## Scopes

`timer:read`, `timer:write`, `tasks:read`, `entries:read`. A login grants what the authenticator returns
(`ClientAuthResult::success($user, $credentialId, $scopes)`, default all four); a device grants what it asked for and
the user approved. Tokens issued before 1.9 carry all four. `entries:write` is not part of 1.9.

## Errors

`{"error": "<code>", "message": "<English text>"}`.

| Status | `error` | When |
|---|---|---|
| 400 | `invalid_request` | Missing / malformed field |
| 400 | `metadata_not_allowed` | Free `metadata` on `timer/start` while `client_api.allow_metadata` is false |
| 400 | `authorization_pending`, `slow_down`, `expired_token`, `access_denied` | `device/token` states |
| 401 | `invalid_credentials` | `login` with wrong credentials |
| 401 | `invalid_token` | Missing, unknown, expired, revoked token or inactive credential (`WWW-Authenticate: Bearer error="invalid_token"`) |
| 403 | `insufficient_scope` | Token lacks the endpoint scope |
| 403 | `task_forbidden` | Task missing **or** not trackable by the user (same answer, no existence oracle) |
| 403 | `forbidden` | Deprecated `entries?userId=` for a user the team guard refuses |
| 404 | `no_active_timer` | `timer/stop`, `timer/discard-idle`, `heartbeat` without a running timer |
| 409 | `timer_running` | `timer/start` while a timer runs |
| 429 | `rate_limited` | Rate limit hit (`Retry-After` seconds) |

## Endpoints

### POST login

```http
POST /api/time-track/login
{"username": "person@example.com", "password": "<app password>", "clientType": "extension"}
```

`200 {"token": "<43 chars>", "expiresAt": "2026-11-03T09:30:00+00:00", "scopes": ["timer:read", "timer:write", "tasks:read", "entries:read"]}`
— `400`, `401 invalid_credentials`, `429`.

### POST logout

Revokes the presented token. Always `204`.

### GET me

Scope: any. `200 {"userId": "42", "identifier": "person@example.com", "displayName": null, "scopes": [...], "workingHours": null}`
(`displayName` / `workingHours` from `ClientUserProfileProviderInterface`; clients suppress reminders outside the
working hours — the API never pushes).

Since 1.9.1 the body also carries `"minSupportedVersion": "1.4.0"` — the oldest version of the **calling token's**
client type (`extension`, `desktop` or `web`) the host still supports, from `ClientMinSupportedVersionProviderInterface`.
The key is **absent** when the host sets none for that type. A client older than this value should ask the user to
update (it may keep working read-only); compare it as semver.

### GET tasks

Scope `tasks:read`. `?search=&limit=1..50&offset=` → `200 {"tasks": [{"id", "title", "boardId", "boardTitle"}]}`.

### GET timer

Scope `timer:read`. `200 {"timer": {"id", "taskId", "taskTitle", "boardId", "startedAt", "lastHeartbeatAt", "clientType", "metadata"}}` or `204`.

### POST timer/start

Scope `timer:write`. `{"taskId": "task-1", "note": "optional, ≤500 chars", "source": "extension|desktop|web"}` →
`201 {"timer": {...}}`. Per request `TaskAccessCheckerInterface::canTrack()` → `403 task_forbidden`; `409
timer_running`; `source` defaults to the 1.x `clientType` field, else the token's client. The note is stored as
`metadata.note`.

### POST timer/stop

Scope `timer:write`. `{"note": "optional"}` → `200 {"entry": {"id", "taskId", "taskTitle", "boardId", "startedAt",
"endedAt", "durationSeconds", "source", "metadata"}}` or `404 no_active_timer`.

### POST timer/switch

Scope `timer:write`. `{"taskId": "task-2", "note": "for the stopped entry", "source": "desktop"}` → `201 {"entry":
{...}|null, "timer": {...}}`. The new task is checked **before** the running timer is stopped, so `403 task_forbidden`
leaves it running.

### POST timer/discard-idle

Scope `timer:write`. The user's decision after the client detected idle time locally (the server never receives an
activity signal):

```json
{"from": "2026-11-02T10:00:00Z", "to": "2026-11-02T10:25:00.000Z", "action": "split"}
```

| `action` | Effect | Response `200` |
|---|---|---|
| `keep` | Nothing changes | `{"entry": null, "timer": {...}}` |
| `discard` | Entry from the timer start to `from`; timer stopped | `{"entry": {...}, "timer": null}` |
| `split` | Entry to `from`; a new timer on the same task from `to` | `{"entry": {...}, "timer": {...}}` |

`from` ≥ timer start, `to` > `from`, `to` ≤ now, else `400`. No timer → `404`.

### POST heartbeat (deprecated)

Deprecated since 1.9, removed in 2.0 (`Deprecation: true` header). Scope `timer:write`. `isIdle` is ignored; answers
`{"timer", "idleThresholdSeconds", "deprecated": true}` or `404`.

### GET entries

Scope `entries:read`. Own entries, newest first: `?from=&to=&limit=1..100&offset=` → `200 {"entries": [...], "limit",
"offset"}`. `from` / `to`: RFC 3339 or `Y-m-d`, default the last 7 days, span capped at 366 days. The deprecated
`userId` parameter keeps the 1.x team view through `TeamAccessGuard`.

### GET export

Scope `entries:read`. Own data export (RGPD art. 15 / 20): `?from=&to=&format=json|csv` → download
(`Content-Disposition: attachment; filename="time-entries-YYYYMMDD-YYYYMMDD.<format>"`, `Cache-Control: no-store`),
at most 10 000 rows. CSV columns `id,taskId,taskTitle,boardId,startedAt,endedAt,durationSeconds,source,note`; cells
starting with `= + - @` are prefixed with `'`.

### POST device/code

Device flow, step 1 (no Bearer):

```http
POST /api/time-track/device/code
{"clientType": "desktop", "clientName": "Work laptop", "scopes": ["timer:read", "timer:write", "tasks:read"]}
```

```json
{"deviceCode": "<43 chars, secret>", "userCode": "BCDF-GHJK", "expiresIn": 600, "interval": 5,
 "verificationUri": "https://host.example/account/time-apps/connect",
 "verificationUriComplete": "https://host.example/account/time-apps/connect?code=BCDF-GHJK"}
```

`clientType`: `desktop` (default) or `extension`. The user code is 8 letters from `BCDFGHJKLMNPQRSTVWXZ`. Show it and
open the verification URI in the browser.

### POST device/token

Poll every `interval` seconds with `{"deviceCode": "..."}`:

- `400 authorization_pending` — not decided yet; `400 slow_down` — polled faster than `interval`;
- `400 access_denied` — the user denied (then the request is gone); `400 expired_token` — expired, unknown or already
  exchanged;
- `200 {"token", "expiresAt", "scopes"}` — once; the token is linked to the credential the host issued.

## Device verification page

`DeviceAuthorizationService` holds the logic (lookup by user code, approve, deny, CSRF token ids
`nowo_time_track_device/<approve|deny>/<request id>`). The optional default page (`routes.device_verify`,
`/tools/time-track/device`, `client_api.device_flow.default_page: true`) renders the UiKit form and show scaffolds:
enter the code → client name, type, scopes, expiry → Approve / Deny. Hosts with their own page (Beacon: Account →
Security → Time tracking apps, with 2FA re-auth) set `default_page: false` and `verification_uri`.
