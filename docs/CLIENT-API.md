# Client API reference (1.10.1, contract v0.3.1)

API of the browser extension ([nowo-tech/time-track-extension](https://github.com/nowo-tech/time-track-extension))
and the desktop app ([nowo-tech/time-track-desktop](https://github.com/nowo-tech/time-track-desktop)). Enabled with
`clients.enabled: true`; the device flow additionally with `client_api.device_flow.enabled: true`. Paths below are the
defaults (`clients.routes.*`, `route_prefix`). Host wiring: [USAGE](USAGE.md#host-integration-client-api-19); options:
[CONFIGURATION](CONFIGURATION.md#client-api-19); threat model: [SECURITY](SECURITY.md#client-api).

## Table of contents

- [Notes](#notes)
- [Changes in 1.11 (imported entries)](#changes-in-111-imported-entries)
- [Changes in v0.3.1 (1.10.1)](#changes-in-v031-1101)
- [Changes since v0.2 (1.10)](#changes-since-v02-110)
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
- [Task details](#task-details)
- [Client-supplied times](#client-supplied-times)
- [Idle check](#idle-check)

## Notes

Since 1.10.2 every client note (`timer/start`, `timer/stop`, `timer/switch`, `timer/discard-idle` `keep` and
`reassign`, and a string `metadata.note` when free metadata is allowed) goes through one sanitiser and one merge rule:

- **Cleaning:** bidirectional controls (U+202A–U+202E, U+2066–U+2069, and since 1.10.3 the marks U+061C, U+200E and
  U+200F), U+200B and U+FEFF are removed; each run of
  control characters (CR, LF, tab, C0, DEL, C1) becomes one space; leading / trailing white space and zero-width
  joiners are trimmed (joiners inside the text are kept). A note that is empty after cleaning counts as no note.
- **Cap:** 500 code points everywhere. `keep` answers `400` above it; the other endpoints cut the note.
- **Merge:** the timer collects the start note and every `keep` note; `timer/stop` / `timer/switch` append their note.
  Notes are joined with a newline and the result is cut at 500 code points, earlier text first. So once a timer's note
  has reached 500 code points, later `keep` and stop / switch notes are **dropped** (the call still succeeds; the
  earlier text wins). Clients that want the user to see this can compare the returned `metadata.note`.
- **Follow-ups:** idle `split` and reassign `then: continue` start the follow-up timer **without** the note (it stays
  on the entry up to the idle start). A reassign note goes on the reassigned entry only.
- Notes are plain user text: clients render them as text; hosts escape them.

## Changes in 1.11 (imported entries)

- `GET entries` and `GET export`: an entry may have `source: "import"` (time imported from another tool). New keys on
  every entry: `billable` (bool or null), `description` (string or null, full text), `readOnly` (true for imported
  entries) and `taskVisible` (false when the owner cannot see the task; `taskTitle` is then the translated
  *Task not visible*, in the user's `locale`). The CSV export appends `billable`, `description`, `imported`.
- `GET me` has `locale`: the user's preferred locale (`es`, `pt_BR`, `zh-Hant-TW`, …) or `null` when the host does
  not know it. Clients may use it for their UI language instead of the system language.
- Clients render `import` as *Imported*, never offer to edit it, and **skip `readOnly` entries for *Resume* and the
  recent / quick tasks**.

## Changes in v0.3.1 (1.10.1)

Additive. A 1.10.1 server is recognised by `features.idle_keep_note`.

| Where | Change |
|---|---|
| `POST timer/discard-idle` `keep` | Optional `note`, added to the running timer's note (it ends up on the entry at stop) |
| `GET me` | `features.idle_keep_note: true`; `limits.idle_check.user_can_disable` (bool) |

## Changes since v0.2 (1.10)

All additive: a 1.9 client keeps working unchanged; a 1.10 client detects a 1.9 server by the missing `features` key on
`GET me`.

| Where | Change |
|---|---|
| `GET me` | `features` (object of booleans) and `limits` |
| `GET tasks` | `projectId`, `projectTitle`, `url` on every task |
| Every `timer` object | `boardTitle`, `projectId`, `projectTitle`, `url` (resolved live) |
| `POST timer/start` | Optional `startedAt`; response `startedAtResult` |
| `POST timer/stop` | Optional `endedAt`; response `endedAtResult` |
| `POST timer/discard-idle` | Action `reassign` (`taskId`, `then`, `note`, `source`); response key `reassigned` |

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
- Dates are RFC 3339 (`2026-11-02T09:30:00+01:00`, `Z` / `z` and milliseconds accepted where noted). Since 1.9.2 the offset
  must lie between `-12:00` and `+14:00` and the value is converted to the server time zone before it is compared or
  stored; since 1.10 surrounding whitespace is refused and idle spans are compared on whole seconds.

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
| 403 | `task_forbidden` | Task missing **or** not trackable by the user (same answer, no existence oracle), on `timer/start`, `timer/switch` and `timer/discard-idle` `reassign` |
| 403 | `forbidden` | Deprecated `entries?userId=` for a user the team guard refuses |
| 404 | `no_active_timer` | `timer/stop`, `timer/discard-idle`, `heartbeat` without a running timer |
| 409 | `timer_running` | `timer/start` while a timer runs; `timer/discard-idle` `split` / `reassign` when a concurrent start took the slot first (1.9.1) |
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

Since 1.10 the body also carries:

```json
"features": {"task_details": true, "start_backdate": true, "stop_backdate": true, "idle_reassign": true,
             "idle_keep_note": true, "idle_check": true, "workday": false, "monitoring": false},
"limits": {"backdate": {"max_seconds": 86400, "clock_skew_seconds": 120},
           "idle_check": {"threshold_minutes": 10, "answer_timeout_minutes": 5, "user_can_disable": false}}
```

`idle_keep_note` and `limits.idle_check.user_can_disable` are 1.10.1 additions. `user_can_disable` tells the client
whether it may offer the user a switch to turn the idle check off (organisation policy: `false`; personal accounts may
get `true` from the host's `IdleCheckSettingsProviderInterface`).

`workday` and `monitoring` are **reserved** and always `false` in 1.10. Clients treat a missing or unknown key as
`false` and ignore keys they do not know. `limits.idle_check` is the token user's setting (host provider, else the
instance settings) and is present even when `features.idle_check` is false.

Since 1.11 the body also carries `"locale": "es"` (always present, string or `null`): the user's preferred locale, from
the profile provider when it also implements `ClientUserLocaleProviderInterface`, else the user's own `getLocale()`
when the user class has one. Values that are not a locale (`^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8}){0,3}$`) are sent as
`null`. The masked title of imported entries is translated into this locale.

### GET tasks

Scope `tasks:read`. `?search=&limit=1..50&offset=` → `200 {"tasks": [{"id", "title", "boardId", "boardTitle",
"projectId", "projectTitle", "url"}]}` (the last three since 1.10, always present, nullable; see [Task details](#task-details)).

### GET timer

Scope `timer:read`. `200 {"timer": {"id", "taskId", "taskTitle", "boardId", "startedAt", "lastHeartbeatAt", "clientType", "metadata",
"boardTitle", "projectId", "projectTitle", "url"}}` or `204`. The last four (since 1.10) are on every `timer` object
of every endpoint.

### POST timer/start

Scope `timer:write`. `{"taskId": "task-1", "note": "optional, ≤500 chars", "source": "extension|desktop|web"}` →
`201 {"timer": {...}}`. Per request `TaskAccessCheckerInterface::canTrack()` → `403 task_forbidden`; `409
timer_running`; `source` defaults to the 1.x `clientType` field, else the token's client. The note is stored as
`metadata.note`.

Since 1.10, optional `"startedAt": "2026-11-04T08:47:13.250Z"` (RFC 3339; milliseconds dropped) starts the timer in the
past, e.g. when a client replays a start made offline. The value is clamped, never refused (a malformed value is
`400 invalid_request`), and the response adds `"startedAtResult": {"requested", "applied", "clamped", "reason"}` (only
when `startedAt` was sent). See [Client-supplied times](#client-supplied-times).

### POST timer/stop

Scope `timer:write`. `{"note": "optional"}` → `200 {"entry": {"id", "taskId", "taskTitle", "boardId", "startedAt",
"endedAt", "durationSeconds", "source", "metadata"}}` or `404 no_active_timer`.

Since 1.10, optional `"endedAt"` (RFC 3339) stops the timer earlier, e.g. when a client replays a stop made offline;
the response adds `"endedAtResult"` (only when `endedAt` was sent). See [Client-supplied times](#client-supplied-times).

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
| `keep` | Nothing changes; since 1.10.1 an optional `note` is added to the running timer's note | `{"entry": null, "timer": {...}}` |
| `discard` | Entry from the timer start to `from`; timer stopped | `{"entry": {...}, "timer": null}` |
| `split` | Entry to `from`; a new timer on the same task from `to` | `{"entry": {...}, "timer": {...}}` |
| `reassign` (1.10) | Entry to `from` (omitted when `from` is the timer start); entry on `taskId` for `[from, to]`; then `then` | `{"entry": {...}\|null, "reassigned": {...}, "timer": {...}\|null}` |

`from` ≥ timer start, `to` > `from`, `to` ≤ now, else `400`. No timer → `404`. Since 1.10 every response carries
`reassigned` (`null` except for `reassign`).

`keep` `note` (1.10.1): optional plain text, cleaned like every note (see [Notes](#notes)); a blank note is ignored; a
value that is not a string or is longer than 500 code points (after cleaning) answers `400 invalid_request` ("note
must be plain text of at most 500 characters."). The note is appended to the running timer's `metadata.note` and
ends up on the entry at stop. The note is passed to `IdleResolvedEvent::getNote()` (raw user text: escape it); logs
carry its length only (`note_length`).

`reassign` fields: `taskId` (required), `then` = `continue` (default: a new timer on the original task from `to`) |
`switch` (a new timer on `taskId` from `to`) | `stop` (no timer), optional `note` (≤ 500 chars, stored on the
reassigned entry) and `source` (as on `timer/start`). Checks, in order, nothing written before the last:
`400` when reassign is disabled (`client_api.idle.allow_reassign`), `taskId` is blank, or `then` / `source` is
invalid → `403 task_forbidden` when `TaskAccessCheckerInterface::canTrack()` refuses (identical answer for a missing
and a foreign task) → `404` without a timer → `400` when `taskId` is the running task → `400` for a span outside the
timer → `404` when a concurrent stop won → `409` when a concurrent start took the follow-up slot (entries kept). Both
entries are written in one unit of work and each dispatches `nowo_time_track.timer.stop`.

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

## Task details

Since 1.10 the task provider may fill `projectId`, `projectTitle` and `url` on `TaskReference`. The API publishes:

- `projectId`: trimmed, 1–64 chars, else `null`; `projectTitle`: trimmed, cut to 255 chars, blank → `null`;
- `url`: only an absolute `https://` URL, ≤ 2048 chars, without whitespace, control characters or credentials, whose
  origin equals `client_api.task_url.origin` (else the request origin, so set `trusted_proxies` behind a proxy). Any
  other value is published as `null` (logged at debug level as `task_url_dropped`, never with the URL). Clients check
  the origin again before opening it.

On `timer` objects the details are resolved **live** with `TaskProviderInterface::findForUser()` for the token user:
a task that was deleted or is no longer visible gives `null` details (the stored `taskTitle` snapshot stays), and a
provider error gives `null` details with a `200`.

## Client-supplied times

`startedAt` (`timer/start`) and `endedAt` (`timer/stop`) are clamped and reported, never refused:

| Field | Bound | `reason` when moved |
|---|---|---|
| both | later than now + `client_api.backdate.clock_skew_seconds` (default 120) → now; up to the allowance → now, not reported | `future` |
| `startedAt` | before the end of the user's latest entry → that end (no overlap) | `overlap` |
| `startedAt` | older than `client_api.backdate.max_seconds` (default and maximum 24 h) → the limit | `max_backdate` |
| `endedAt` | before the timer start → the timer start (a 0-second entry) | `before_start` |
| both | `max_seconds: 0` → now | `disabled` |

`{"requested": "…", "applied": "…", "clamped": true, "reason": "overlap"}`. An earlier stop only shortens the
recorded time, so `endedAt` has no `max_backdate` bound. The overlap check covers this bundle's entries only.

## Idle check

The clients detect idle time locally and ask the user what to do with it, using `features.idle_check` and
`limits.idle_check` of `GET me` (organisation settings: `client_api.idle_check.*` or the host's
`IdleCheckSettingsProviderInterface`). **The server only ever receives the user's decision** through
`POST timer/discard-idle` (`keep`, `discard`, `split`, `reassign`). No activity signal, idle counter, input count or
screen data is sent to the server or stored by it; the deprecated `heartbeat` ignores `isIdle`.
