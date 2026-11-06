# Security

## Table of contents

- [Scope](#scope)
- [Attack surface](#attack-surface)
- [Threat model](#threat-model)
- [Client API](#client-api)
- [Reports](#reports)
- [Imported entries (1.11)](#imported-entries-111)
- [Timesheets, balance and lock (1.13)](#timesheets-balance-and-lock-113)
- [Task effort on the client API (1.13)](#task-effort-on-the-client-api-113)
- [Calendar suggestions and meetings (1.14)](#calendar-suggestions-and-meetings-114)
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
| Client API | Stateless Bearer API of the extension and desktop app (session tokens issued from host credentials, scopes), device flow (`device/code`, `device/token`) and the optional device verification page |
| Configuration | CORS origins, token TTL, table prefix |

## Threat model

| Threat | Mitigation |
| ------ | ---------- |
| Token theft | Tokens stored hashed (SHA-256); HTTPS in production |
| Brute force login | Rate limit per IP + username (`cache.app`); unknown usernames still run a password hash check (no timing oracle) |
| IDOR on entries | `TimeEntryAccessCheckEvent` and team context provider |
| CORS abuse | Exact origins only (`client_api.allowed_origins`); origins from `ClientApiSettingsProviderInterface` that are not an exact `scheme://host[:port]` (`*`, wildcards, paths) are ignored in every environment |
| CSRF on manage UI | Manage POST start/stop require Symfony CSRF token `nowo_time_track_manage` |
| Unauthenticated manage UI | Keep `security.allow_unauthenticated: false` in production; demo-only bypass wires AllowAll checker |

## Client API

Threat model of the client API (1.9 contract v0.2, 1.10 contract v0.3):

| Threat | Mitigation |
| ------ | ---------- |
| Stolen session token | SHA-256 at rest; short TTL (`clients.token_ttl`); never logged nor put in audit events; per-request `ClientCredentialStateInterface` (revoked credential, disabled user, sign out everywhere after `issuedAt` → `401`); `ClientTokenRevoker::revokeByCredential()` on credential deletion; logout |
| Second hidden token list | Tokens are not listed to users; the host credential list is the only one (device approval creates a host credential through `ClientCredentialIssuerInterface`) |
| Over-privileged client | Fixed scopes per token (`timer:read`, `timer:write`, `tasks:read`, `entries:read`), checked per endpoint (`403 insufficient_scope`); no write access to entries. Scopes fail closed: only an absent list grants every scope; an empty list or an unknown / misspelled / future scope is refused (`400 invalid_scope` on `device/code`, `InvalidArgumentException` from `ClientAuthResult::success()`), and a stored list is never widened (tokens issued before 1.9, without stored scopes, keep every scope) |
| IDOR on tasks | `TaskAccessCheckerInterface::canTrack()` per `timer/start` / `timer/switch`, deny by default; missing and foreign task → same `403 task_forbidden` |
| IDOR on entries / export | Own entries only (`entries`, `export`); the deprecated `userId` goes through `TeamAccessGuard` |
| Brute force (login, user code, device code) | Failed-login throttle per IP + username, per IP and per username (any IP, `clients.login_rate_limit.max_attempts_per_username`); per-IP limit on every route and a stricter one on `device/*`; user-code lookups limited per user; 20^8 user codes, 256-bit device codes, 10-minute lifetime |
| Device code phishing / replay | Approval only by the signed-in user on the host page after reviewing client name, type, scopes, request time and the requesting network (/24 or /64); `clientName` / `clientType` are the device's own unauthenticated claims, never a trust signal; CSRF bound to action + request id; device code single use (atomic `approved → consumed`), hashed at rest; denied / expired requests removed |
| CSRF / session riding on the API | Bearer only, no cookies, no session started (kernel test incl. `401` / `429`); no `Access-Control-Allow-Credentials` |
| CORS abuse | Exact origins only; `*` and any-extension are deprecated 1.x opt-ins (off by default) |
| Address rotation (IPv6) | Every per-IP limit (API, device, login) keys an IPv6 client by its /64 (`IpPrefix::rateLimitKey()`), IPv4 per address; keep a reverse-proxy limit in front for hard guarantees |
| Device request flood | `device/code` capped per IPv4 /24 or IPv6 /64 within the code lifetime (`client_api.device_flow.max_pending_per_prefix`, 20) and in total (`max_pending`, 10 000; expired rows purged first) → `429 rate_limited` + `Retry-After` |
| Clickjacking of the approval page | Every answer of the default verification page sends `X-Frame-Options: DENY`, `Content-Security-Policy: frame-ancestors 'none'` and `Cache-Control: no-store`; a host page built on `DeviceAuthorizationService` must send the same ([USAGE](USAGE.md#host-integration-client-api-19)) |
| Flooding / DoS | Per-token and per-IP fixed-window limits on a shared pool (`client_api.rate_limit.cache_pool`, Redis recommended); bounded lists (`tasks` ≤ 50, `entries` ≤ 100, export ≤ 10 000 rows, `entries` / `export` / reports periods ≤ 366 days); since 1.13 the working-time routes are bounded too (see [Timesheets, balance and lock](#timesheets-balance-and-lock-113)) |
| Custom entry stores (EF-I6) | Without `LatestTimeEntryRepositoryInterface` the overlap check reads the backdate window plus one day; an older entry that ends inside the window is missed (documented in UPGRADING) |
| Monitoring creep | No server-side activity signal: `heartbeat` ignores `isIdle`; free `metadata` rejected unless `client_api.allow_metadata`; idle handling is the user's decision (`timer/discard-idle`); the 1.10 idle-check settings (`GET me`) only configure the client prompt, and the server still receives only the decision, never an activity counter |
| Time inflation by backdating (1.10) | Instants normalised to the server zone first (EF-M1); `startedAt` clamped to `client_api.backdate.max_seconds` (≤ 24 h), never before the user's latest entry, never after now; `endedAt` only shortens time; every clamp is reported to the client, logged without data (`timer_start_clamped`, `timer_stop_clamped`) and exposed to the host on `TimerStartEvent::getStartTime()` / `TimerStopEvent::getEndTime()` |
| IDOR / oracle on idle reassign (1.10) | `taskId` checked with `TaskAccessCheckerInterface::canTrack()` before any write; a missing and a foreign task get a byte-identical `403 task_forbidden`; only the token user's own timer is touched; `IdleResolvedEvent` for host audit |
| Open redirect via task URLs (1.10) | `url` published only when https, without credentials, spaces or control characters (Unicode included), on `client_api.task_url.origin` (else the request origin); never logged; clients re-check the origin. Without `task_url.origin` the request origin follows the caller's `Host` header (EF-I1): it only affects the caller's own response, but set `task_url.origin` or `framework.trusted_hosts` |
| Shifted timestamps (EF-M1, fixed in 1.9.2 / 1.10.0) | Client date-times are converted to the server's default time zone before they are compared or stored (Doctrine stores wall time without an offset); offsets outside -12:00..+14:00 are refused; durations are checked after reload in a functional Doctrine test |
| Audit flooding / privacy | `ClientApiEvent` with user id, credential id, client type and IP truncated to /24 (IPv4) or /64 (IPv6); denials throttled per credential (or IP prefix) and reason; PSR-3 records carry action, client and reason only |
| CSV injection in exports | Cells whose first visible character (after spaces and invisible format characters such as U+200B / U+FEFF) is `= + - @` or a full-width `＝ ＋ － ＠`, or that start with tab / CR, are prefixed with `'` (EF2-I3) |
| Note spoofing and injection (1.10.2) | One sanitiser for every note: bidi controls, U+200B / U+FEFF removed, control characters folded, 500 code points (EF2-I2, EF2-I4). Notes are never logged (length only) and never rendered by the bundle; `IdleResolvedEvent::getNote()` is raw user text that host listeners must escape (EF2-I5). Racing keeps may lose a note (EF2-I1, own data only, documented) |
| Malicious download links | The downloads partial keeps absolute http(s) URLs only, renders plain anchors, no inline script / style |

Operations: purge expired tokens and device requests with `php bin/console nowo:time-track:client-tokens:purge`.

**Residual risks (accepted trade-offs, Low)**

- **Per-username login lockout.** Anyone who knows a username can spend its budget
  (`clients.login_rate_limit.max_attempts_per_username`, default 5 × `max_attempts` = 50 failures per 15 min, any IP)
  and lock that user out of `POST login` for up to `interval_seconds`. Usernames are NFKC-normalised (with ext-intl),
  trimmed and lower-cased, so Unicode variants share the counter. Existing tokens, the device flow and the web login
  are not affected. Tune it with `max_attempts_per_username` / `interval_seconds`, or disable the client-login throttle
  with `clients.login_rate_limit.enabled: false` (then rely on a proxy limit).
- **Global device cap.** About 500 IPv6 /64s (20 requests each) can fill `client_api.device_flow.max_pending` (10 000)
  for one `code_ttl`; this blocks new device sign-ins only (`device/code` → `429`), not `login` nor existing tokens.
  Raise `max_pending`, lower `max_pending_per_prefix` or `code_ttl`, or set `max_pending: 0` and rely on the per-prefix
  cap and a proxy limit.
- IPv4-mapped IPv6 client addresses (`::ffff:a.b.c.d`) are keyed and shown as their IPv4 address.

## Reports

- Managers access team data via `TeamContextProviderInterface`
- `TimeEntryAccessCheckEvent` for custom ACL
- Bounded list (REQ-PERF-001): the reports page shows `reports.page_size` entries per page (default 50, max 500),
  paged in the database by `DoctrineOrmTimeEntryRepository`; out-of-range `page` values are clamped to the existing
  pages; the period is capped at 366 days

## Imported entries (1.11)

| Threat | Control |
| --- | --- |
| Imported time edited into worked (payroll) time | Read-only by source; `canEditEntry()` is false before any listener runs; no timer events; excluded from the backdate overlap clamp; `isLive()` / `findLiveByUserAndPeriod()` for host registers |
| One tenant's import overwrites another's entry, or time moves silently between users | The writer refuses an owner change (`ImportedEntryOwnerMismatchException`); the external source carries the host scope; removals match the source exactly, never by prefix |
| Duplicates on re-sync | Unique `(external_source, external_id)`; unchanged rows are not written; two rows for one identity in a batch are refused. A unique violation from a concurrent run rolls the whole flush back (nothing half-written); the batch is then re-read once (the raced row becomes an update, new rows are created with their owners re-attached, the owner check still applies). It fails closed instead, rethrowing the unique violation for the host to retry the slice, when no `ManagerRegistry` is wired (the entity manager stays closed) or the call runs inside a host transaction |
| Malformed input from the source tool | Ids pattern-checked; title and description must be valid UTF-8; controls and bidi overrides stripped (title on one line, ≤ 255); descriptions kept in full (no cap; bounded only by what the host's HTTP client accepts); since 1.12 start year ≥ 1970 and end ≤ now + 1 day |
| Task title leaked to an owner without access | Imported entries of a task the owner cannot track show the translated *Task not visible* in the API, export (JSON / CSV) and reports. The stored `taskTitleSnapshot` is never masked: host template overrides (including a surgical `nowo_ui_list_row` override) must use `hidden_task_entry_ids`, and host lists and exports must call `TimerService::hiddenTaskEntryIds()` and `toArray(false, …)`. Since 1.12 the description of a masked entry is hidden too. Masking follows what the entry owner may track, not the viewer |
| Imported-entry reads bypass a host store or decorator | Since 1.12 `ImportedTimeEntryRepositoryInterface` resolves to the configured `TimeEntryRepositoryInterface` service and fails closed (`LogicException`) when it does not implement the interface |
| Time lost when tasks or boards are deleted | `time_track_entries` has no foreign key to tasks or boards: entries keep their `task_id` and snapshots and simply stop being linked. The bundle never calls `remove*()` itself |
| Deleting imported time without authority | Single deletion needs a `delete_imported` grant (default deny); bulk removals are host calls after its own check; never a side effect of deleting tasks or boards |
| Personal data in logs | The writer logs counts and the scope kind only, never descriptions, titles or ids of people |

## Timesheets, balance and lock (1.13)

| Threat | Control |
| --- | --- |
| An approved period changed afterwards (listener, admin, import, host shortcut) | `PeriodLockGuard` on every bundle write path (timer start / switch / idle, import writer outcome `locked`, imported removals, ledger); `canEditEntry()` / `canDeleteImportedEntry()` re-check the lock **after** the listener; null-scope entries are checked against every scope; only an audited reopen with a reason unlocks. Host code that writes entries must call the guard (UPGRADING) |
| Self-approval | Refused for everybody, `security.admin_roles` included; `allow_self_approval` cannot be enabled |
| Approving something else than what was submitted | Entries hash at submit, recomputed at approve (`stale`); the submitted period is soft-locked for the owner; optimistic `version` + unique `(timesheet_id, seq)` make concurrent decisions fail (`conflict`) |
| Cross-tenant leaks (approver in A, member in B) | Every decision goes through `TimesheetAccessGuard`: scope must be one of the actor's scopes, approvers per scope from the host provider, admin roles see nothing unless `admin_roles_view` or approver; others' timesheets answer `not_found` exactly like non-existent ids; the approval queue lists `reviewableUserIds()` of a scope of the approver only |
| Audit tampering | Timesheet events and ledger lines are append-only (no update / delete in the repositories, only the retention purge), SHA-256 chained over canonical JSON; `timesheets:verify` names the first broken link; hosts may add triggers |
| Approvers seeing more than needed | Totals per task; task titles masked when the approver cannot see the task; notes only with `approver_sees_notes`; no activity, idle or device data in timesheets |
| Personal data in logs / events | Ids, codes and counts only; comments and reasons never in events or logs (a `hasComment` flag) |
| Task time presented as the legal register | `legalRecord: false` / `source: task_time` on every balance payload and component unless the host source is its register |
| Workday replay / forged times | `Idempotency-Key` required (24 h replay, `idempotency_mismatch` for another body), `occurredAt` not in the future and at most 72 h old; the register logic stays in the host |
| Old tokens gaining new powers | `workday:*`, `balance:read`, `timesheet:*` are never granted by default (a token without stored scopes keeps the pre-1.13 set) |
| Unbounded balance computation (DoS, audit GB-M1) | The request range is not the only bound: the running balance is computed from `working_time.balance.start_date` (or the last locked timesheet) to the end of the range. `WorkingTimeBalanceService::report()` refuses dates outside 2000-01-01 – 2099-12-31 and ranges ending more than 366 days after today, and never computes a span longer than 3660 days (ten provider calls; null + a `balance_span_exceeded` warning instead). `GET balance` additionally caps the range at 92 days; `GET timesheets?period=` accepts keys in the window up to the next period only (404 otherwise). Hosts with an old `start_date` lock timesheets (their snapshots restart the running balance) or move `start_date` forward |
| Rows created by reads (audit GB-L1) | `GET me` never writes; `GET timesheets` / `TimesheetService::view()` create the owner's row lazily only for the current and the previous period, any other period is an unstored draft (`id: null`). Rows are otherwise created by submit and auto-lock |
| Imported-entry masking bypassed (audit GB-M2) | `GET timesheets` and the week-grid component mask imported entries whose task the owner can no longer see (title and description, `taskVisible: false`), as `GET entries` / `GET export` do since 1.11 — for the owner too |
| Cross-site posts of approver actions (audit GB-L2) | `TimesheetFormHandler` checks the posted `_token` itself with the CSRF token manager (`nowo_time_track_timesheet` / `nowo_time_track_ledger`), whatever the host's `framework.form.csrf_protection` says; without a token manager (`symfony/security-csrf` + `framework.csrf_protection`) every post is refused. Owner actions act only on the actor's own period |
| Components rendering data the host should not show (audit GB-I2) | The week grid and the ledger table check `canView()` for the viewer (`not_found` otherwise) and the ledger table drops lines of another owner or scope. Still, obtain timesheets through `TimesheetService::find()` / `view()` and lines through `BalanceLedger::linesOf()` |
| Ledger write flooding (contract §11.4, audit GB-L6) | **No web rate limit in 1.13**: ledger writes come from authenticated approvers through host routes and are audited and chained. Hosts **must** put a per-actor `RateLimiter` on their ledger (and decision) routes |
| Ledger chain forks and retention (audit GB-L3, GB-L4) | Unique `(owner_ref, scope_key, prev_hash)`: a concurrent append is refused (`conflict`) instead of forking the chain. The purge deletes only a chain-order prefix and marks the first line kept (`purged_before`), so `verify` keeps working after a purge. Not covered in 1.13: the chain has no stored head, so deleting the **newest** lines by hand is not detected — use DB triggers (UPGRADING) or keep the `BalanceLedgerLineAddedEvent` ids in an external log |
| Scope back-fill changing approved periods (audit GB-L5) | `entries:backfill-scope` leaves entries of locked or submitted periods without a scope and reports them |
| Personal-data access (art. 15 / 20, contract §12.4, audit GB-L6) | `TimesheetPersonalDataExporter::export($ownerRef)` returns the person's timesheets with their audit trail and their ledger lines (no other person's id); hosts add it to their personal-data export next to the entries |
| Workday idempotency under concurrency (audit GB-I4) | The key check is check-then-act: two simultaneous posts with one key can both reach the provider, which receives the key and must deduplicate on it |

## Task effort on the client API (1.13)

`GET tasks` and the timer payloads carry `estimateSeconds`, `loggedSeconds` and `remainingSeconds` (owner decision
2026-11-05, audit GB-M3):

- `loggedSeconds` is the caller's **own** closed time on the task (live and imported entries).
- Only users who can **manage** the task see the total of **every** user. The bundle cannot know who manages a task, so
  this is denied by default (admin roles included): the host grants it per user and task by listening to
  `nowo_time_track.task_effort.access_check` (`TaskEffortAccessCheckEvent::grant()`; `TeamAccessGuard::canSeeTaskEffortTotal()`).
- A total over several people can still reveal one person's effort when only one other person tracked the task, which
  is why it is limited to managers. A provider that fills `TaskReference::$loggedSeconds` itself must apply the same rule
  (the value is shown as given, labelled by that rule).
- `loggedSecondsScope` (`own` / `all`) tells clients which of the two the figure is.

## Calendar suggestions and meetings (1.14)

| Threat | Control |
| --- | --- |
| Reading more of a calendar than needed | Primary calendar only, `[now − lookback_days, now]`; Google `fields` / Graph `$select` masks with no description, location, organiser, attendee identity or conference data; least-privilege scopes (`calendar.events.readonly`, `Calendars.ReadBasic`) |
| Storing event data | Events live only in memory during a sync (`CalendarEventView`: redacted debug output, serialization throws). A suggestion keeps the task, the time range and keyed HMAC references (`event_ref`, `series_ref`; per-user key derived from the kernel secret) — never a title, attendee, location, link, calendar name or provider id. Unmatched events are only counted |
| Tokens leaking | No token in the bundle: the host returns a `CalendarCredential` per sync (`#[SensitiveParameter]`, redacted, not serializable); `credential_ref` is an opaque host id and never leaves the API |
| Hostile or compromised provider responses | GET only, constant base URIs (Graph next links only under the same base), no redirects, 10 s timeout, 2 MB per page, `max_pages`; errors stored as codes (`last_error_code`), never provider messages |
| Rate limits / provider abuse | A 429 waits once for a short `Retry-After` (≤ 5 s), then the sync stops with `rate_limited` until the next interval |
| Night-time processing (LOPDGDD art. 88) | The scheduled sync reads a calendar only inside its owner's working hours; unknown hours are never inside |
| Other people seeing calendar data | Connections, rules and suggestions are owner-only services; no approver, manager or admin path exists |
| Regex denial of service in user rules | Rules are literal substrings (`mb_stripos`), at most 120 characters and `max_rules_per_user` per scope; control and format characters refused |
| Leaving data behind | Revoke calls the host `revoke()` (token and grant) first, then deletes the connection, its suggestions and the user's rules in that scope; account deletion cascades; suggestions expire `suggestion_ttl_days` after the event |
| Titles in logs, events or exceptions | Logs and events carry ids, codes and counts only (marker-string tests over rows, logs and events) |
| Suggestions for events that are gone (audit GG-L3) | After a complete read of the window, pending suggestions of that connection whose event was not seen again (moved, cancelled, declined, key removed) are deleted; accepted and dismissed rows stay. A read cut at `max_pages` deletes nothing |
| Sync starvation and batch aborts (audit GG-L5) | Due order is "never attempted, then least recently attempted"; a failure records `last_attempt_at` and backs off (2 × interval, doubling, at most a day; a reconnect clears it); outside-hours rows do not use the run limit; any exception of the host, the store or a listener is a per-connection `internal_error` (logged by class), so neither the batch nor `refresh=1` fails |
| Hostile titles against the host key extractor (audit GG-I4) | Titles are cut to 1024 characters before `TaskKeyResolverInterface::extractKeys()` and rule matching; hosts MUST use linear-time matching (no backtracking regex) |
| Erasure and secret rotation (audit GG-I8) | `CalendarConnectionService::revokeAllFor($user)` revokes every connection (host token and grant first) in one call; call it before deleting an account (the database cascade alone does not reach the host). Rotating `kernel.secret` changes every `event_ref`: dismissed events are suggested once more |
| Duplicate or overlapping entries from concurrent requests (audit GG-L1) | `POST entries` and every accepted occurrence run the period lock check, the overlap check and the insert in one transaction holding the user's row lock (`SELECT … FOR UPDATE`, `UserSerialisedTimeEntryRepositoryInterface`; SQLite serialises writers). The `Idempotency-Key` is re-read and its answer stored under that lock, so a concurrent request with the same key replays; accept and dismiss re-read the suggestion under the lock, so it is decided once. A host replacement of the entry store without that capability keeps the race |
| Half-applied accepts (audit GG-L2) | One transaction per occurrence covers the entry and the accepted status; `TIME_ENTRY_CREATED` and `CALENDAR_SUGGESTION_DECIDED` are dispatched after the commit (a throwing entry listener is logged by class and does not undo the entry); any exception of an occurrence is its own `internal_error` outcome and the others go on |
| Task ids of tasks the user cannot track (audit GG-L4) | `resolve-keys` leaves such keys out exactly like unknown keys (no task id, no title) |
| Client caches (audit GG-I1) | Calendar, suggestion and `POST entries` answers carry `Cache-Control: no-store, private` |
| Rate limiter properties (audit GG-I2) | Fixed windows on the shared PSR-6 pool: up to twice the limit across a window edge, increments not atomic (a few hits may be lost under concurrency), counted per token (a user with several tokens gets each limit per token); accept, dismiss and revoke have only the global per-token limit. Put a reverse-proxy limit in front where hard guarantees matter |
| `source: calendar` provenance (audit GG-I3) | `POST entries` accepts `source: calendar` from the desktop's local mode without a stored suggestion: it states the client's claim, not evidence of a real event. Only entries created by an accepted suggestion are backed by the bundle's own sync |
| Long on-demand refreshes (audit GG-I7) | `refresh=1` (once per 5 minutes per token) syncs inline: at most `max_pages` pages × 20 s plus one ≤ 5 s wait per connection. Keep `max_pages` low (default 10) or let the scheduled command do the work |
| Meetings API: reading someone else's calendar | No user parameter exists; unknown query parameters are refused (400 `unknown_parameter`); the provider receives the authenticated user only; `meetings:read` needs an explicit grant |
| Meetings API: personal data leaving through the client API | Field whitelist (JSON schema with `additionalProperties: false`); private events cleared even when the host leaks them; internal attendees `{id, name}` only, ≤ 50, never the caller; external attendees as a count; task links only for trackable tasks; titles cleaned and cut |
| Meetings API: hostile join links | `MeetingLinkDetector`: https, no user information, port 443 only, no whitespace / control / quote / angle-bracket / backslash characters, ASCII host on the allow-list (Teams, Meet, Zoom, Jitsi, Webex), ≤ 2048 characters; ChatKit 1.19 vectors plus look-alikes in tests |
| Meetings API: caches and logs | `Cache-Control: no-store`, `Pragma: no-cache`, `Vary: Authorization`, `nosniff`, no validators on every answer; nothing stored or cached by the bundle; the access log has status, count, dropped and duration only (join links often carry passcodes); provider exceptions answer 503 without their message and are logged by class |
| Meetings API: feature probing (audit GG-I5) | With the feature off (or no provider) the route answers 404 before authentication, so an anonymous caller can tell whether meetings are on for the instance; it learns nothing about any user. Kept on purpose (contract §22.2: off = 404) |
| Meetings API: polling abuse | 6 / min and 60 / h per token on top of the global limits; 429 with an integer `Retry-After`; clients respect `refreshAfterSeconds` |

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
