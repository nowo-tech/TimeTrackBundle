# Client API reference (1.22, contract v0.3.1 + timetrack-next v1.1)

API of the browser extension ([nowo-tech/time-track-extension](https://github.com/nowo-tech/time-track-extension))
and the desktop app ([nowo-tech/time-track-desktop](https://github.com/nowo-tech/time-track-desktop)). Enabled with
`clients.enabled: true`; the device flow additionally with `client_api.device_flow.enabled: true`. Paths below are the
defaults (`clients.routes.*`, `route_prefix`). Host wiring: [USAGE](USAGE.md#host-integration-client-api-19); options:
[CONFIGURATION](CONFIGURATION.md#client-api-19); threat model: [SECURITY](SECURITY.md#client-api).

## Table of contents

- [Changes in 1.22 (leave, spec 204)](#changes-in-122-leave-spec-204)
- [Changes in 1.21 (billable)](#changes-in-121-billable)
  - [PATCH entries/{id} (1.21)](#patch-entriesid-121)
- [Changes in 1.19 (links.workingTime)](#changes-in-119-linksworkingtime)
- [Changes in 1.14 (calendar suggestions, manual entries)](#changes-in-114-calendar-suggestions-manual-entries)
  - [GET me/meetings (1.14)](#get-memeetings-114)
- [Changes in 1.13 (working time, timesheets, task effort)](#changes-in-113-working-time-timesheets-task-effort)
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
  - [PATCH entries/{id} (1.21)](#patch-entriesid-121)
  - [GET export](#get-export)
  - [POST device/code](#post-devicecode)
  - [POST device/token](#post-devicetoken)
- [Device verification page](#device-verification-page)
- [Task details](#task-details)
- [Client-supplied times](#client-supplied-times)
- [Idle check](#idle-check)

## Changes in 1.22 (leave, spec 204)

Additive, in the current contract version (no new path segment, no new scope; old clients ignore it). Beacon spec 204
FR-029: the clients show *On leave until …* and send no timer or clock reminders on leave days. Requests, approvals,
balances, the team calendar, iCal feeds and notifications stay on the web.

**Only with a host leave provider** (`client_api.leave_provider`). Without it no key below is sent and every body is
byte for byte the 1.21 one. With it the keys are **always present**, so a client can tell "not on leave" (`null`) from
"this server does not report leave" (no key).

- **`GET workday`** gains **`leave`**: `{"type": "<public label>", "until": "YYYY-MM-DD"}`, or `null` when the user is
  not on leave on the workday's `date`. `until` is the last day of the leave (inclusive, local date in the workday
  `timezone`). `type` is plain text of at most 80 grapheme clusters, cleaned like the `daysOff` labels; for medical /
  sick leave, for any type the host keeps private, and for a label that is empty or not valid UTF-8 it is the bundle's
  **`Absent`**, in the user's language (`de` *Abwesend*, `es` / `pt` *Ausente*, `fr` *Absent*, `it` *Assente*, `nl`
  *Afwezig*). While `leave` is not null, **`reminder` is `null`** whatever the register says (FR-029). Since the audit
  NG fixes every `GET workday` answer (with or without a leave provider) carries `Cache-Control: no-store, private` and
  `Vary: Authorization`.
- **`POST workday/{action}`** answers (and the `state` of a 409) **do not** carry `leave`: they are stored for the 24 h
  `Idempotency-Key` replay, and leave data is never kept there. Clients keep the `leave` of their last `GET workday`.
- **`GET me`** gains **`features.leave`** (true while the server reports leave **and** `features.workday` is true, i.e.
  `GET workday` answers) and **`links.leave`**: the absolute https URL of the host's Leave page
  (`client_api.links_leave_route`), sent only while `features.workday` is true (like `links.workingTime`), else `null`
  (also with no route, or not https).
- **`daysOff`** accepts a third kind, **`leave`** (an approved leave day of any type), **only with the leave
  provider**; without it a `leave` entry is dropped as an unknown kind, exactly as in 1.21. Its `label` is **always
  `null`**: a label the host sends is dropped (and logged as `leave label dropped`, without the label). Clients treat
  `leave` like `vacation` (no schedule reminders) and any unknown kind as a day off as well.
- **Errors**: a leave provider that throws, a leave that ended before the workday date and a workday `date` that is not
  a real date give `leave: null` (the workday API keeps answering) and a `warning` log with the reason only (no user
  id, date, label or exception message).

```json
GET /api/time-track/workday
{"status": "idle", "date": "2026-08-03", "timezone": "Europe/Madrid", "segments": [], "workedSeconds": 0,
 "breakSeconds": 0, "flags": [], "reminder": null, "allowedActions": ["clock-in"],
 "leave": {"type": "Vacaciones", "until": "2026-08-14"}}

GET /api/time-track/workday (medical leave, user locale es)
{"status": "idle", …, "reminder": null, "allowedActions": ["clock-in"], "leave": {"type": "Ausente", "until": "2026-11-07"}}

GET /api/time-track/me
"features": {…, "meetings": false, "leave": true, "monitoring": false},
"links": {"calendars": null, "workingTime": "https://beacon.example/working-time", "leave": "https://beacon.example/leave"},
"daysOff": [{"date": "2026-12-08", "kind": "holiday", "label": "Inmaculada Concepción"},
            {"date": "2026-12-09", "kind": "leave", "label": null}]
```

**Privacy.** The bundle receives only `ClientUserLeave` (a public type or none, and the end date): there is no field for
a reason, a comment, an attachment or a health detail, so none can reach a client. Medical leave is
`ClientUserLeave::absent()`, which has no type at all. Everything sent here is stored on the user's devices.

## Changes in 1.21 (billable)

Additive, in the current contract version (no new path segment, no new scope; old clients ignore it). Beacon decision
D-20261111-12. The TimeTrack Extension and TimeTrack Desktop pick the field up in their own releases.

- **Read**: every entry object (`GET entries`, `GET export`, the `entry` of `timer/stop`, `timer/switch`, `POST
  entries`) has had **`billable`** (`true`, `false` or `null` = unset) since 1.11. New live entries now take the host's
  default (`TimeEntryBillableDefaultProviderInterface`) when the client sends none; without one it stays `null`.
- **Create**: `POST entries` accepts an optional **`billable`** (`true` / `false`; absent or `null` = the host default).
  Any other type answers `400 invalid_request`; an explicit value the host's `edit_billable` check refuses answers
  `403 billable_forbidden`. It is part of the body the `Idempotency-Key` replay compares.
- **Change**: `PATCH entries/{id}` (below).
- **CORS**: `Access-Control-Allow-Methods` now lists `PATCH`.

### PATCH entries/{id} (1.21)

Scope **`entries:write`**. Changes the `billable` flag of one of the caller's **own** entries. Body exactly
`{"billable": true | false | null}`; no other field can be changed (a body with another key is refused, so a later
version can accept more without ambiguity). No `Idempotency-Key`: the request is idempotent.

| Answer | When |
| --- | --- |
| `200 {"entry": {...}}` | changed, or already that value (the entry object, `locked: false`) |
| `400 invalid_request` | no `billable`, not a boolean or null, or another field |
| `403 insufficient_scope` | the token lacks `entries:write` |
| `403 forbidden` | the host's `edit` or `edit_billable` access check refused it |
| `404 not_found` | no such entry, or another user's (they look alike); every request while `manual_entries.enabled` is false |
| `409 read_only` | an imported entry |
| `409 anonymised` | an anonymised or ownerless entry |
| `409 period_locked` (+ `lockedThrough`) / `period_submitted` | the entry's period is locked (approved) or waiting for approval |
| `429 rate_limited` | more than 120 changes per hour per token |

Every answer carries `Cache-Control: no-store, private`. Each real change is audited by the host
(`TimeEntryBillableChangedEvent`, `via: api`).

```http
PATCH /api/time-track/entries/0f9d…-…
Authorization: Bearer …
Content-Type: application/json

{"billable": true}
```

## Changes in 1.19 (links.workingTime)

Additive (spec 198). `GET me` `links` gains **`workingTime`**: the absolute https URL of the host's working-time page
(`client_api.links_working_time_route`), sent only while `features.workday` is true, else null. Clients open it in the
browser for what stays on the web: corrections (for the `flags` of the workday), exports and the month view.

```json
"links": {"calendars": null, "workingTime": "https://beacon.example/working-time"}
```

The workday API itself is unchanged since 1.13 and matches spec 198 FR-008, FR-011 and FR-013: `allowedActions` drives
the buttons (route segments `clock-in`, `break-start`, `break-end`, `clock-out`), `reminder` is already filtered to the
working hours by the host, `flags` are shown with the `workingTime` link, `Idempotency-Key` is required on every action
(24 h replay, 422 `idempotency_mismatch`) and an offline `occurredAt` is accepted up to 72 h old (2 min of clock skew
into the future). The bundle passes the client type (`desktop` / `extension`) to the provider, which stores the source.

## Changes in 1.14 (calendar suggestions, manual entries)

Additive; with `calendar` and `manual_entries` off (the default) every new route answers `404 not_found`.

- **Scopes** `entries:write`, `suggestions:read`, `suggestions:write` — never part of the default grant (like the 1.13
  ones): ask for them explicitly (device flow `scopes`); old tokens answer `403 insufficient_scope`.
- **`GET me`**: `features.calendar_suggestions` (calendar suggestions on and the user has a tenant scope) and
  `features.manual_entries` (`POST entries` on).
- **CORS**: `Access-Control-Allow-Methods` now lists `DELETE` (calendar connection revoke).
- No route ever returns an event title, description, attendee, location or provider id: suggestions carry the task id,
  the time range and opaque ids only. Get task titles through `GET tasks` or `resolve-keys`.

| Method and path | Scope | Body / query | Answers |
| --- | --- | --- | --- |
| `GET /api/time-track/calendar/connections` | `suggestions:read` | `scope?` | 200 `{scopeKey, connections: [{id, provider, mode, status, scopeKey, lastSyncAt, lastErrorCode, consentVersion, consentedAt}], connectUrls: {google, microsoft}}` (host https links or null); 404 |
| `DELETE /api/time-track/calendar/connections/{id}` | `suggestions:write` | — | 204 (the host revokes the token and the grant; the connection, its suggestions and the user's rules in that scope are deleted); 404 for unknown or other users' ids |
| `GET /api/time-track/suggestions` | `suggestions:read` | `scope?`, `from?`, `to?` (RFC 3339; default and earliest: `calendar.lookback_days` back; latest: now), `refresh?` (`1`: sync now, at most once per 5 minutes per token, else 429) | 200 `{scopeKey, from, to, groups: [item], singles: [item], ignored}`; item `{groupKey, taskId, occurrences: [{id, startsAt, endsAt, seconds}], totalSeconds}`; `ignored` = events of the refresh that matched nothing (null without `refresh`); 400 bad dates |
| `POST /api/time-track/suggestions/accept` | `suggestions:write` **and** `entries:write` | `{ids: [1-100 ids] \| groupKey, taskId?, note?}` | 200 `{outcomes: [{id, entryId}\|{id, error}]}` with `error` `period_locked`, `period_submitted`, `overlap`, `not_trackable`, `entry_too_old`, `not_found` (unknown, another user's or already decided); one `calendar` entry per occurrence, partial success allowed |
| `POST /api/time-track/suggestions/dismiss` | `suggestions:write` | `{ids \| groupKey}` | 200 `{dismissed: [ids]}` (dismissed suggestions are not suggested again until they expire) |
| `POST /api/time-track/suggestions/resolve-keys` | `suggestions:read` | `{scopeKey?, keys: [1-50 strings of 1-32 characters]}` | 200 `{matches: {key: {taskId, title, boardId, trackable: true}}}` (keys of unknown tasks and of tasks the user cannot track are left out alike); 60 / min per token; 400; 404 |
| `POST /api/time-track/entries` | `entries:write` | header `Idempotency-Key` (1–64 visible characters, required); `{taskId, startedAt, endedAt, source: manual\|calendar, note?, billable?}` (`billable` since 1.21) (RFC 3339 with offset) | 201 `{entry}` (`metadata.origin` = source); 400 `invalid_request`; 403 `task_forbidden`; 409 `period_locked` (+ `lockedThrough`), `period_submitted`, `overlap` (a live entry or the running timer); 422 `invalid_range` (end ≤ start), `entry_too_long` (> 24 h), `entry_in_future`, `entry_too_old` (> `manual_entries.max_age_days`), `idempotency_mismatch`; 120 / h per token; 404 when `manual_entries.enabled` is false |

The same `Idempotency-Key` and body get the same answer for 24 h (201, 409 and 422 are replayed), also for two requests
sent at once. CORS preflights allow the `Idempotency-Key` header. Every answer of these routes carries `Cache-Control:
no-store, private`. `source: calendar` on `POST entries` is the client's claim (local mode), not evidence of an event.

### GET me/meetings (1.14)

`GET /api/time-track/me/meetings?from=&to=&tz=&limit=` (scope **`meetings:read`**, explicit grant only): the caller's
own meetings overlapping `[from, to)`, minimal fields (contract §22, host guide [MEETINGS.md](MEETINGS.md), JSON schema
[`schemas/meetings.v1.json`](schemas/meetings.v1.json)). There is no user parameter.

| Parameter | Format | Default | Rules |
| --- | --- | --- | --- |
| `from` | RFC 3339 with `Z` or an offset (seconds optional, ≤ 6 fraction digits; encode `+` as `%2B`) | now (to the minute) | ≥ now − `meetings.max_past_days` (1) |
| `to` | same | `from` + 24 h | after `from`; ≤ `meetings.max_range_days` (14) after it; ≤ now + 14 days |
| `tz` | IANA zone | the working-hours zone, else UTC | shapes all-day `date` and bounds only |
| `limit` | 1–200 | 100 | more meetings → `truncated: true` |

200 `{from, to, tz, generatedAt, sync: {status: connected|not_connected|needs_reconnect|syncing, lastSyncAt,
refreshAfterSeconds}, truncated, meetings: [{id, start, end, timeZone, allDay, date, busy, private, title, joinUrl,
joinProvider: teams|meet|zoom|jitsi|webex|null, organizerIsMe, attendees: [{id, name}], externalCount, taskLinks:
[{taskId, key}], updatedAt}]}`. Times are UTC `Z`. Private meetings come with `title: null`, no attendees, no task links
and `externalCount: null`, but keep `joinUrl`. Errors: 400 `invalid_datetime` / `invalid_timezone` / `invalid_range` /
`invalid_limit` / `unknown_parameter` with `{field}` (the value is never echoed); 401; 403 `insufficient_scope`; 404
`not_found` (feature off, no provider, organization disabled calendars); 429 `rate_limited` (6 / min, 60 / h per token;
integer `Retry-After` header and `retryAfter` in the body); 503 `meetings_unavailable` (`Retry-After: 60`). Every answer:
`Cache-Control: no-store, private`, `Pragma: no-cache`, `Vary: Authorization`, `X-Content-Type-Options: nosniff`, no
validators. Clients MUST wait `sync.refreshAfterSeconds` between polls.

`GET me` gains `features.meetings`, `meetings: {status, refreshAfterSeconds}` (null when off) and `links: {calendars}` (the
host's https *Calendars* page for the *Connect* button, or null).

*Track the meeting* (Q-B22): `POST timer/switch` (and `timer/start`) accept `metadata: {"origin": "meeting"}` even when
free metadata is off; it is kept on the timer and its entry. Any other metadata on `timer/switch` is `400
metadata_not_allowed`.

## Changes in 1.13 (working time, timesheets, task effort)

Additive; with the features off every existing route answers as in 1.12 apart from the new keys.

- **Scopes** `workday:read`, `workday:write`, `balance:read`, `timesheet:read`, `timesheet:write`. They are never part of
  the default grant: a token without stored scopes (issued before 1.9), a login whose authenticator names none and a
  device request without `scopes` keep `timer:read`, `timer:write`, `tasks:read`, `entries:read`. Ask for the new ones
  explicitly (device flow `scopes`, or `ClientAuthResult::success($user, $credentialId, $scopes)`); without them the
  routes answer `403 insufficient_scope`.
- **`GET me`**: `features.workday` (real: a workday provider and a register for the user), `features.timesheets`,
  `features.balance`, `features.calendar_suggestions` / `features.manual_entries` (false until 1.14),
  `features.task_effort` (true); `timesheet`: `{scopeKey, periodKey, status, lockedThrough}` of the current scope and
  period, or null. `monitoring` stays reserved (false).
- **Entries / export**: each entry gains `scopeKey` (null = unassigned) and `locked` (its period is locked or submitted:
  no bundle path may change it — skip it for *Resume*); the CSV gains the columns `scope_key` and `locked` (`yes` / `no`).
- **Timer routes** (`timer/start`, `timer/switch`, `timer/discard-idle`): `409 {error: "period_locked", lockedThrough}`
  and `409 {error: "period_submitted"}` (only with timesheets on). `timer/stop` is never refused.
- **Task effort** on every task of `GET tasks` and on the timer of `GET timer`, `timer/start`, `timer/switch`:
  `estimateSeconds` (TaskBoard estimate × 60, from the task provider's `TaskReference::$estimateSeconds`),
  `loggedSeconds` (the user's **own** closed time on the task; the total of **every** user only for users the host lets
  manage the task — `nowo_time_track.task_effort.access_check`, denied by default; the provider may give its own
  `TaskReference::$loggedSeconds`), `loggedSecondsScope` (`"own"`: `loggedSeconds` is the user's own time; `"all"`:
  every user's time — label it accordingly; null when `loggedSeconds` is null) and `remainingSeconds`
  (`max(0, estimate − logged)`); each null when unknown.

### Workday (R-GDPR §8)

| Method and path | Scope | Answers |
| --- | --- | --- |
| `GET /api/time-track/workday` | `workday:read` | 200 `{status: idle\|working\|on_break\|closed, date, timezone, segments[{type, start, end, source}], workedSeconds, breakSeconds, flags[], reminder, allowedActions[]}` (+ `leave: {type, until}\|null` since 1.22 with a leave provider, see [Changes in 1.22](#changes-in-122-leave-spec-204)); 404 when the register is off for the user |
| `POST /api/time-track/workday/{clock-in\|break-start\|break-end\|clock-out}` | `workday:write` | body `{occurredAt?}` (ISO 8601 with offset; not in the future, ≤ 72 h old), header `Idempotency-Key` (required, 1–64 visible characters). 200 state; 400 `invalid_request` (no key); 403 `forbidden`; 404 off; 409 `{error: "invalid_transition", state}`; 422 `invalid_occurred_at`, `idempotency_mismatch` (same key, other body) |

The same key and body from the same user get the same answer (200 or 409) for 24 h without reaching the register again.

### Balance

`GET /api/time-track/balance?from=Y-m-d&to=Y-m-d&scope=` (`balance:read`; default range: the current period; ≤ 92 days)
→ 200 `{scope: {key, label}, from, to, timezone, source: register|task_time, legalRecord, policy: {id, version},
days[{date, kind, label, expectedSeconds, workedSeconds, diffSeconds, overtimeSeconds, flags[]}], weeks[], totals,
balanceSeconds, ledger[], overtime: {year, ratifiedSeconds, compensatedSeconds, countedSeconds, capSeconds, flags[]}}`.
400 bad range; 404 when working time is off, the scope is not the user's or the host has no calendar for them. Show
*"Task time, not a working-time record"* whenever `legalRecord` is false.

### Timesheets

| Method and path | Scope | Answers |
| --- | --- | --- |
| `GET /api/time-track/timesheets?period=current\|previous\|2026-W45&scope=` | `timesheet:read` | 200 `{id, scope, period: {key, kind, start, end, timezone}, status, locked, lockedThrough, lockReason, decisionComment, totals, entries[], unassigned[], events[{action, fromStatus, toStatus, actorRole, client, occurredAt, comment}], allowedActions[]}`; 404 |
| `POST /api/time-track/timesheets/{periodKey}/submit` | `timesheet:write` | body `{scope?}`; 200 timesheet; 409 `timer_running`, `not_started`, `not_ended`, `invalid_transition`, `conflict`; 404 |
| `POST /api/time-track/timesheets/{periodKey}/withdraw` | `timesheet:write` | 200; 409 `invalid_transition`; 404 |

Approve, reject, reopen and the ledger are web only. Other users' timesheets are never reachable through the client API.

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
- `GET entries` (own entries, without `userId`) and `GET export` **always include** the user's imported entries: they
  are the user's copy of their own data (GDPR art. 15 / 20), so a host listener calling
  `TimeEntryListQueryEvent::setIncludeImported(false)` does not remove them there (it does on the team `userId`
  path).

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

`timer:read`, `timer:write`, `tasks:read`, `entries:read`, and since 1.13 `workday:read`, `workday:write`,
`balance:read`, `timesheet:read`, `timesheet:write`. A login grants what the authenticator returns
(`ClientAuthResult::success($user, $credentialId, $scopes)`, default the first four); a device grants what it asked for
and the user approved. Tokens issued before 1.9 carry the first four. The 1.13 scopes and, since 1.14, `entries:write`,
`suggestions:read`, `suggestions:write` and `meetings:read` always need an explicit grant.

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
| 409 | `period_locked` (+ `lockedThrough`), `period_submitted` | Since 1.13: a timer write into a locked or submitted timesheet period |
| 409 | `overlap` | Since 1.14: `POST entries` / an accepted suggestion overlaps a live entry or the running timer |
| 422 | `invalid_range`, `entry_too_long`, `entry_in_future`, `entry_too_old`, `idempotency_mismatch` | Since 1.14: `POST entries` times (and `Idempotency-Key` reuse) |
| 409 | `timer_running` | `timer/start` while a timer runs; `timer/discard-idle` `split` / `reassign` when a concurrent start took the slot first (1.9.1) |
| 429 | `rate_limited` | Rate limit hit (`Retry-After` seconds) |
| 503 | `meetings_unavailable` | Since 1.14: the host meeting provider failed (`Retry-After: 60`) |

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

Scope: any. `200 {"userId": "42", "identifier": "person@example.com", "displayName": null, "scopes": [...], "workingHours": null, "locale": null, "daysOff": null, ...}`
(`displayName` / `workingHours` from `ClientUserProfileProviderInterface`; clients suppress reminders outside the
working hours and on days off — the API never pushes). Since 1.12 `workingHours` is always in the `slots` shape and
`daysOff` lists holidays and vacations: see [Working hours](#working-hours-normalised-since-112) and
[Days off](#days-off-since-112).

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
`null`. The masked title of imported entries is translated into this locale's language when the bundle ships it
(`de`, `en`, `es`, `fr`, `it`, `nl`, `pt`; since 1.12), else into the host's default locale. Since 1.12 a user
`getLocale()` that needs arguments is ignored. A masked entry (`taskVisible: false`) has `description: null` (and an
empty CSV `description` cell) since 1.12.

Since 1.15 the body has `privacy` when the host's profile provider also implements `ClientUserPrivacyProviderInterface`, and
no such key otherwise: an object (usually `url`, `version`, `controllerName`, `dpoContact`, `noticeSummary`) or `null`. It is
in the language of `Accept-Language` (best match among the host's enabled locales), else the user's own. A `url` that is not
absolute `http(s)` is sent as `null`, so clients can open any `url` they get.

#### Working hours (normalised since 1.12)

`workingHours` is `null` only when the provider returns `null` (unknown: TimeTrack Desktop then notifies at any time),
otherwise always this shape:

```json
"workingHours": {"timezone": "Europe/Madrid",
                 "slots": [{"day": 1, "from": "09:00", "to": "14:00"}, {"day": 1, "from": "15:00", "to": "18:00"}]}
```

`day` is the ISO weekday (1 = Monday … 7 = Sunday); `from` (inclusive) and `to` (exclusive) are `HH:MM` local time in
`timezone` (an IANA identifier, or `null` = the client's local time). A weekday without slots is not a working day.
Slots are sorted by day, then start.

`ClientUserProfileProviderInterface::getWorkingHours()` may return either shape; the bundle normalises it:

| Provider returns | `GET me` sends |
|------------------|----------------|
| `{timezone, slots: [{day: 1-7, from, to}]}` | the same, sorted |
| `{timezone, days: {mon: [["09:00","17:00"]], ...}}` (the 1.9 documented shape; keys `mon`…`sun` or `monday`…`sunday`, any case; a range is `[from, to]` or `{from, to}`) | `slots` |
| both keys | `slots` wins, `days` is ignored |
| an overnight range, e.g. Fri `22:00`–`06:00` | split at midnight: `{day: 5, from: "22:00", to: "23:59"}`, `{day: 6, from: "00:00", to: "06:00"}` (Sunday wraps to Monday; `to: "00:00"` = until midnight) |
| overlapping or touching ranges of one day | merged into one slot |
| `to: "24:00"` | `"23:59"` (clients only parse `00:00`–`23:59`) |
| anything else: no `slots` / `days`, unknown or non-canonical time zone (`europe/madrid`), day not an int 1-7 (`"1"`) or unknown day key, time not `H:MM`/`HH:MM` (`" 9:00"`, `09:00:00`), `from` = `to` (`00:00`–`00:00` is not "all day"), more than 100 ranges | **fail closed**: `{timezone: <the given zone when valid, else null>, slots: []}` — never inside working hours, so the clients send no notifications limited to working hours — and a `warning` log (`reason`, no user data) |

Before 1.12 the provider value was passed through unchanged, so a host returning the documented `days` shape sent a
value TimeTrack Desktop 0.4 could not read (0.5.0 reads both shapes).

#### Days off (since 1.12)

```json
"daysOff": [{"date": "2026-12-08", "kind": "holiday", "label": "Inmaculada Concepción"},
            {"date": "2026-12-28", "kind": "vacation", "label": null}]
```

Always present: a list, or `null` when unknown (no provider, the provider returns `null`, or its value is not a list).
TimeTrack Desktop 0.5.0+ (unreleased; desktop PRs #14 / #15) reads it, merges it with the user's local days off and
sends no schedule reminders on those days; the browser extension ignores it.

- `date`: `YYYY-MM-DD` local date in the working-hours `timezone` (the server's PHP time zone when there is none).
- `kind`: `holiday`, `vacation` or (since 1.22, only with `client_api.leave_provider`) `leave`; the label of a `leave`
  day is always `null`.
- `label`: plain text or `null`, at most 80 characters (grapheme clusters). It is JSON, not HTML: the server escapes
  nothing and the clients render it as text.
- Range: today → today + 60 days in that time zone; entries outside it are dropped, one entry per date (the first the
  provider lists), sorted by date.

Source: the profile provider when it also implements `ClientUserDaysOffProviderInterface::getDaysOff(UserInterface $user,
DateTimeImmutable $from, DateTimeImmutable $to): ?array` (`$from` = start of today, `$to` = end of today + 60, both in
that time zone). Validation, entry by entry:

- The value must be a list (else `null`); only its first 400 entries are read.
- An entry without a real `YYYY-MM-DD` `date` or with a `kind` other than `holiday` / `vacation` (/ `leave` with the
  leave provider; any case: `Holiday` is accepted) is **dropped alone**; the other entries are still sent. A `leave`
  label is dropped (logged).
- `label`: control characters (line breaks, tabs, DEL, C1) become one space; bidi controls, zero-width characters,
  BOM and soft hyphens are removed (ZWJ is kept for emoji); Unicode blanks (NBSP included) are trimmed; empty = `null`;
  cut to 80 grapheme clusters. A label that is not a string or not valid UTF-8 becomes `null` (the day off is kept).
- Dropped entries and cleaned labels are logged once per request as a `warning` (counts and reasons, no dates, labels
  or user data).

The provider runs on every `GET me` (each client polls it): if it reads a calendar service, cache the result (for
example per user and day).

**Privacy.** Labels reach the user's devices and are stored there (the desktop keeps them for its reminders). Send
public holiday names only (`"Inmaculada Concepción"`), `null` for vacations, and never a leave reason (illness, family
or any personal detail) in a label. Since 1.22, with a leave provider, send approved leave as kind `leave` (its label
is never sent). Since 1.22 labels (and the `GET workday.leave` type) also lose every Unicode format character except
ZWJ (tag characters included), line / paragraph separators (made spaces) and the invisible fillers U+3164, U+115F,
U+034F, U+FFF9–FFFB, and are cut to 320 bytes after the 80-cluster cut (at a cluster boundary with ext-intl, else at a
UTF-8 boundary). A label left empty is `null`.

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
Each entry carries `billable` (`true`, `false`, `null`); change it with [PATCH entries/{id}](#patch-entriesid-121) (1.21).

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
