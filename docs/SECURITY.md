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
- [Entries with no user (1.15)](#entries-with-no-user-115)
- [Moving imported time between owners (1.16)](#moving-imported-time-between-owners-116)
- [Task time follows a board move (Unreleased)](#task-time-follows-a-board-move-unreleased)
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
| Bulk removal of a deleted user's locked time | Since 1.15 `isRowBlocked()` follows the deleted user's id in `person_ref`; a row with no identity is kept while timesheets are on |

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
| Ledger chain forks and retention (audit GB-L3, GB-L4) | Unique `(owner_ref, scope_key, prev_hash)`: a concurrent append is refused (`conflict`) instead of forking the chain. Since 1.16.2 that holds for a new chain's first line too: it stores a marker in `prev_hash`, because NULL does not collide. A chain that already has two starts from before 1.16.2 keeps them, `verify` keeps reporting it, and it cannot be repaired by hand because the hashes cover the rows; both lines are counted, and new lines go on from the newest. Since 1.17.1 (audit HT-L2, verified in Appendix IE) `verify` also follows the links from each start of such a chain and adds the lines not linked to the alarm (`the chain has N starts, and K line(s) are not linked to them`); before, it checked only each line's hash there. The bundle no longer writes NULL there, so a chain with two NULL first lines is from before 1.16.2 or a hand edit: list them after upgrading (UPGRADING) as your baseline. A retention purge removing the starts clears the alarm. The head is the line no other line follows, so a same-millisecond pair or a clock behind no longer sticks a chain, and a chain already stuck goes on after the upgrade. The purge deletes only a chain-order prefix and marks the first line kept (`purged_before`), so `verify` keeps working after a purge; since 1.17.1 (audit HT-L1) that order follows the links, not the `created_at` sort, so a line written on a clock behind is never cut out of the middle. Not covered in 1.13: the chain has no stored head, so deleting the **newest** lines by hand is not detected — use DB triggers (UPGRADING) or keep the `BalanceLedgerLineAddedEvent` ids in an external log |
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
| Meeting reader (1.19) reading more than the suggestions | `CalendarReaderInterface::meetings()` is called only by the host's own meeting sync (spec 210); the suggestions sync keeps the narrow masks above. The meeting views (attendee e-mails, description, location, join links) live in memory only (redacted debug output, serialization throws); the host keeps its minimal fields. Graph reads the body only with `calendar.microsoft_scope: Calendars.Read` |
| OAuth helpers (1.19) | PKCE `S256` only; state from `CalendarOAuthState` (≥ 32 unreserved characters, constant-time `matches()`, one use; LZ-I1); `https` redirect URIs (`http` only on loopback, no user info, no fragment; LZ-I5); token calls are `POST`s to constant URLs, 10 s, no redirects, 64 KB; tokens, verifier and meeting personal data behind methods, redacted in `json_encode()`, casts, `var_export()`, dumps, Serializer and Monolog (LZ-L1), not serializable; client secrets passed as `%env()%` and kept out of the `nowo_time_track.calendar` parameter; errors are codes, never provider messages |
| Leaving data behind | Revoke calls the host `revoke()` (token and grant) first, then deletes the connection, its suggestions and the user's rules in that scope; account deletion cascades; suggestions expire `suggestion_ttl_days` after the event |
| Titles in logs, events or exceptions | Logs and events carry ids, codes and counts only (marker-string tests over rows, logs and events) |
| Suggestions for events that are gone (audit GG-L3) | After a complete read of the window, pending suggestions of that connection whose event was not seen again (moved, cancelled, declined, key removed) are deleted; accepted and dismissed rows stay (the DELETE carries `status = pending`, so a row a concurrent accept decided is kept, delta GG-D-5). A read cut at `max_pages` deletes nothing |
| Sync starvation and batch aborts (audit GG-L5) | Due order is "never attempted, then least recently attempted"; a failure records `last_attempt_at` and backs off (2 × interval, doubling, at most a day; a reconnect clears it); outside-hours rows do not use the run limit; any exception of the host, the store or a listener is a per-connection `internal_error` (logged by class), so neither the batch nor `refresh=1` fails. Only connections of enabled providers are due; one whose provider was removed from `calendar.providers` is recorded as `not_syncable` with the same backoff, and a row met twice in one run is skipped (delta GG-D-1) |
| Hostile titles against the host key extractor (audit GG-I4) | Titles are cut to 1024 characters before `TaskKeyResolverInterface::extractKeys()` and rule matching; hosts MUST use linear-time matching (no backtracking regex) |
| Erasure and secret rotation (audit GG-I8) | `CalendarConnectionService::revokeAllFor($user)` revokes every connection (host token and grant first) in one call; call it before deleting an account (the database cascade alone does not reach the host). Rotating `kernel.secret` changes every `event_ref`: dismissed events are suggested once more |
| Duplicate or overlapping entries from concurrent requests (audit GG-L1) | `POST entries` and every accepted occurrence run the period lock check, the overlap check and the insert in one transaction holding the user's row lock (`SELECT … FOR UPDATE`, `UserSerialisedTimeEntryRepositoryInterface`; SQLite serialises writers). The `Idempotency-Key` is re-read and its answer stored under that lock, so a concurrent request with the same key replays; accept and dismiss re-read the suggestion under the lock, so it is decided once. A host replacement of the entry store without that capability keeps the race. A user the manager does not manage is locked by id (warning when no row can be locked, delta GG-D-2); the 201 answer is stored after the commit (delta GG-D-3) |
| Half-applied accepts (audit GG-L2) | One transaction per occurrence covers the entry and the accepted status; `TIME_ENTRY_CREATED` and `CALENDAR_SUGGESTION_DECIDED` are dispatched after the commit (a throwing listener of either is logged by class and does not undo anything or fail the request, delta GG-D-4); any exception of an occurrence is its own `internal_error` outcome and the others go on |
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

## Entries with no user (1.15)

Audit status: Appendix HA (2026-11-06, PR #73): first audit at `39c0b06` Fail / High; re-verified at `38f5230`: **Pass (conditional) / Medium**. The only open Medium was accepted by the owner on 2026-11-06: a running timer is lost on any delete that runs no bundle code (bulk DQL, raw SQL, a database cascade from a parent table, code deleting `ActiveTimer` rows); hosts call `release()` or `stopForUserDeletion()` first. Tracked time already closed is kept on every delete path.

| Threat | Control |
| --- | --- |
| Tracked time lost when a user is deleted | `user_id` is `ON DELETE SET NULL`; `removeForUser()` is documented as re-association only |
| A rollback destroys tracked time or identities | Each down script refuses and changes nothing while an entry has no user, with the native clients' default options: one transaction on PostgreSQL, one atomic `ALTER` and forced strict mode on MySQL / MariaDB, `.bail on` and a transaction on SQLite. Proved by `.scripts/check-rollback-clients.sh` in CI. **Residual:** a non-CLI SQLite runner that carries on after an error can still lose data; the file says it must stop |
| One tenant's manager reads another tenant's former people | Nobody lists ownerless entries by default. `includeOwnerless($scopeKeys)` is set by a host listener and filters by `scope_key`; only `null` lists every scope, and an entry with no scope needs `null`. The client API never lists them. **Residual:** on a case-insensitive MySQL collation, scopes differing only by case match each other in this filter; keep tenant keys distinct regardless of case |
| One tenant takes another's former person through a crafted external id | A plain user row never takes an ownerless entry. A claim must name the stored reference and have exactly the stored scope, so a claim with a scope is refused on a row with none; a person row must match the reference, and the scope when the row has one. All compared case-sensitively in PHP |
| A sync rewrites a deleted user's hours | Entries of a deleted user or with no identity are frozen: `Unchanged`, nothing written. Only a claim by that same id reaches them, with the old and the new period checked |
| A re-created user with a reused id takes the old entries | A plain row is a mismatch; bringing them back needs an explicit claim |
| Task title leaked through an ownerless row | `hiddenTaskEntryIds($entries, $viewer)` asks about the viewer; without a viewer the row is masked. Host lists must pass the viewer |
| Stored XSS or a formula through an imported name | The name is cleaned to one line, controls, bidi overrides and every other format character stripped, cut to 255; Twig autoescapes it. Hosts printing `getPersonName()` must escape it, and a host writing a CSV from `export()` must use `TimeEntryCsv::cell()` |
| A contact detail stored as a name | A name holding an `@` is dropped, so also `Ana <ana@example.com>`, `mailto:…` and lists of addresses. A contact detail written without one is not recognised |
| A re-sync re-identifies an erased person | `anonymised_at` freezes the row and the hashed external id still finds it: every upsert is `Unchanged`, whoever it names. Exception: after an explicit manager removal of the row nothing remembers it, so a re-sync creates it again, with the name |
| Identity forged through a person reference | `user:` is reserved, whatever the case, and refused from hosts' `ImportedTimeEntryPerson`; the reference is never serialised by `toArray()` |
| Personal data kept after a plain delete | By default a delete keeps name, description and note, with no expiry of their own: the owner's rule keeps the hours, and it does not require keeping the person. The host chooses `retention.anonymise_on_user_delete` or calls `anonymiseUser()`, and sets a term with `anonymiseOwnerlessEndedBefore()` (GDPR art. 5(1)(e), to be confirmed by counsel) |
| "Anonymised" overstating the result | `anonymise*` clears name, reference, description, note and task title, and hashes the external id. **It is pseudonymisation unless the host has assessed it** (recital 26): task id, board, scope and exact times stay, because totals and tenant isolation need them, and in a small team they can single someone out; so do `billable`, `source`, `external_source` (which can hold a directory id) and `created_at`, and timesheets keep `owner_ref` and task totals (`totals.byTask`), which can re-link task time to an id. Whoever holds the remote id can recompute the hash, and whoever can enumerate a small id space can reverse it (the hash is unsalted: a 6-digit id took 0.36 s in the audit); resolving the id to a person still needs the remote system. **Accepted by the owner on 2026-11-06: `external_id` keeps this hash, a pseudonym, with no HMAC.** Do not anonymise entries a host uses as its legal working-time register within the legal term |
| Erasure holes | `anonymiseUser()` stops the running timer first, so a later delete cannot bring the person back, and it covers entries already released. **After a bulk DQL or raw SQL delete with no `release()`, no later erasure or export can find one person's rows**: only `anonymiseUnidentified()` reaches them, all together |
| Personal data with no export path | `FormerPersonTimeEntries::export($ref)` for a person with no account or a released user |
| Time in progress lost with the user | `remove($user)` stops the timer first through the normal stop. **Residual, by decision:** a bulk DQL `DELETE` of users, raw SQL, a database cascade from a parent table, or host code deleting `ActiveTimer` rows such as an eraser, loses it, with no row, event or log left, and with no cap on how long that timer ran. Such hosts must call `release()` or `stopForUserDeletion()` first. A timer started between `remove()` and the flush is lost too. A `SET NULL` on the timers table could keep the row, but a foreign key runs no code, so nothing could close it at the deletion: there would be only a sweep ending it at the last heartbeat, with no name or exact end, and every reader of `ActiveTimer::getUser()` would have to accept null. **Accepted by the owner on 2026-11-06: no `SET NULL` on the timers table; this residual stays documented and the DDL stays as it is** |
| `remove($user)` commits the host's pending work when a timer runs | **Residual, by decision:** `TIMER_STOP` needs a live user and its listeners flush (TaskBoard's task total), so it cannot wait for the host's flush. Inside a host transaction the stop and the delete roll back together; without one a failed delete leaves a stopped, marked entry and no time is lost |
| A failed delete changes a living user's rows | The name snapshot and `anonymise_on_user_delete` are written only after the flush's commit, in `postFlush`, so a delete that fails changes nothing on those rows. Loaded entries change inside the flush's transaction. **Residuals:** if the process dies between the commit and `postFlush`, or an entry is written for the user during their delete, rows stay with no name or reference, and only `anonymiseUnidentified()` reaches them, all together; with `anonymise_on_user_delete`, run it on a schedule, because nothing signals that this happened. A host `postFlush` listener that flushes, ordered before the bundle, has the same effect. A `remove($user)` from inside an `onFlush` listener, with nothing else to flush, can settle a living user's rows: do not remove a user inside a flush. Time is kept in all of these. |
| A locked period blocks an account deletion, or a late entry goes unseen | The stop is never refused, as for any stop; `TIMESHEET_LATE_ENTRY` tells the approver and the entry stays locked by the deleted user's timesheets, found by `owner_ref` |
| Ownerless time removed with no trace or inside a locked period | Removal is an explicit manager call: the single path needs a `delete_imported` grant, bulk calls need the host's check; locked rows are kept, and a refusal fires the event too. `TimeEntriesRemovedEvent` carries count, kept, seconds, people and actor, and the log counts and whether an actor was given. **Residual:** the bundle has no audit table, so hosts must store the event; `$actorId` stays optional for compatibility |
| `closed_by` forged | `start()` drops the reserved key |
| Malicious `privacy.url` opened by a client | `ClientPrivacyNormalizer` parses it and sends null unless it is absolute `http(s)` with a real host, no credentials, blanks, controls or backslashes; the warning carries no value |
| A host's entry store breaks every delete | The service fails only when used; the listener does nothing without the capability |

## Moving imported time between owners (1.16)

Audit status: Appendix HM (2026-11-07, PR #74): first audit at `30c00f9` Pass (conditional) / Medium; fixes re-verified at `6848136`: **Pass (good) / Low**, no open Critical, High or Medium. Residuals (Low): on MySQL 8 a sync into the moved source, or one next to it in sort order, can wait for the whole move or fail with a deadlock, with no time lost and the next run repeating it, so run big moves outside sync windows; on SQLite without foreign keys, `anonymise_on_user_delete` does not reach rows that were not loaded. A move refreshes managed `Timesheet` entities, so flush pending changes to them first.

Since then, Appendix HQ (2026-11-07, PR #75): **Pass (good) / Low** at `1fe4dd1`, no open Critical, High or Medium. 1.16.1 fixes the SQLite residual and reduces the MySQL one. Residuals (Low), all without time lost: on MySQL and MariaDB a sync's insert can occasionally still wait for the rest of a move (8 of 49 rounds here, up to 6 s for 10000 rows, no error), and creating a timesheet can wait for a move, also for an owner whose reference sorts next to the moved people, so keep running big moves outside busy hours. Since then, Appendix HT (2026-11-07, PR #76): **Pass (good) / Low** at `ad1de5c`, no open Critical, High or Medium. 1.16.2 fixes the two ledger defects present since 1.13: a chain stuck on `conflict` while `verify` says intact, and two concurrent first lines. Residuals (Low): a chain that already has two starts keeps that alarm, and while it does, `verify` does not check the links of that chain, only each line's hash; and a purge cutting through two lines whose chain order and sort order differ, which is rare, can make `verify` report lines as not linked, with nothing else lost. Both residuals are fixed in 1.17.1 (Appendix IE, Pass (good) / Info): `verify` follows the links from each start of such a chain and reports lines that are not linked next to the starts (HT-L2), and the purge takes its prefix in chain order (HT-L1).

| Threat | Control |
| --- | --- |
| Re-associating time deletes it | Moves only update the owner columns; there is no delete statement on that path |
| A move changes a closed period | A row in a locked or submitted period of its owner, or of the new user, stays and is counted, also for a deleted user's timesheets by `owner_ref` |
| A move or a claim crosses tenants | The row's scope must be exactly the target's, null included, compared in PHP; the scope is never rewritten. With a scope provider it must also be among `scopesFor()` of the new user, so this is only as strong as that method. **With no provider the host vouches for the scope it passes,** and a null scope moves when none is passed: such a row has no tenant. A deleted user's reference only goes back to that id. Source, ids and references compare case-sensitively in PHP |
| A crafted id list moves other rows | Ids must be strings of the id pattern, so a cast such as `12` matching `12abc` or a trailing NUL cannot reach the database |
| A move misses a period locked meanwhile | The guard reads timesheets under a shared lock inside the move's transaction, so on MySQL and MariaDB such a lock is seen or waits for the move. A custom timesheet store without `LockingGuardTimesheetRepositoryInterface` reads as usual |
| A half-done move | One transaction. The ids are read with no lock, then the rows are locked by primary key and their source and owner checked again, so what is locked is what changes and `secondsMoved` is exact. Only those rows are locked: since 1.16.1 a sync elsewhere normally does not wait, and on MySQL or MariaDB rarely waits for the rest of the move (audit HQ-L1). |
| Settling overwrites a move | After a user's delete, settling touches only rows with no owner and no reference, or still with that user |
| Who moved it is unknown | `TimeEntriesMovedEvent` carries owners, counts and actor, and the log counts and whether an actor was given. **Residual:** `$actorId` is optional and the bundle has no audit table, so hosts store the event. The host authorises every call |
| A soft-erased user gets a person back | The bundle cannot know it; the host must not call a move for such an account |
| `skipMismatches` hides a refusal | Opt-in; each skipped row has its own outcome and a count, and the log gets a warning with the count. The default still refuses the batch |
| A host listener cancels or misdirects settling | `postFlush` priority 1000 (HA-L18), and settling only if the user row is gone (HA-L19). A host listener with a higher priority that flushes can still cancel it, and `remove()` inside a flush stays unsupported |

## Task time follows a board move (Unreleased)

Audit status: Appendix ID (2026-11-08, PR #77) at `c9de79c`: Medium (docs) / Low; fixes for ID-M1, ID-L1, ID-L2 and ID-I1 and the owner decision of 2026-11-08 since then. Audit ID (2026-11-08): Pass (good) / Info at `41e0ef2`.

| Threat | Control |
| --- | --- |
| A move deletes or creates time | One bulk `UPDATE` of `board_id_snapshot` and `scope_key` by primary key (timers: `board_id_snapshot` only); there is no insert or delete on this path. Tests compare every other column before and after, and count and total per user |
| A move rewrites what a closed period billed | With timesheets on, on a move into another scope, an entry whose period is locked, approved or submitted, in its current scope or in the target scope, is kept and reported. Within one scope it follows in `board_id_snapshot` only, which neither `entries_hash` nor `totals` covers (owner decision 2026-11-08; a test compares hash and totals before and after). The timesheet `entries_hash`, `totals` and the audit and ledger hash chains are never touched, so `verify` stays intact. Timesheets are read under a shared lock inside the batch's transaction, as for owner moves: on MySQL and MariaDB a lock **committed** before or during the batch is seen or waits. A submit still in flight (its snapshot built before the move, the period not yet submitted) is not seen: its stored hash then includes a moved entry, and `approve()` recomputes the hash and refuses it as `stale`, so the owner withdraws and submits again (audit ID-I1) |
| A move rewrites anonymised or unattributable rows | Anonymised entries are kept always; entries with no user and no reference are kept while timesheets are on (the guard's rule) |
| Time moves into a tenant it should not reach | **The host vouches for the target board and scope:** the service checks neither against the actor nor against the scope provider. A wrong target shows the entries' hours, task titles, descriptions and notes in the other tenant's reports, exports and bills. It must only be called for a move TaskBoard has authorised and committed. No route, no controller |
| A stale or replayed call pulls the time back (audit ID-M1) | The last call wins. With `fromBoardId` only entries and timers on the board the move left change; entries on a third board are kept as `on_another_board`. USAGE resolves the target from the task's current board at handling time. **Residual:** without `fromBoardId`, or with a host that takes the target from an old payload, a replay still moves the time. Two moves of one task handled at the same time can leave the time on the wrong board (audit ID-I2): USAGE asks hosts to re-dispatch every move after a delay or to handle moves of one task one at a time |
| A crafted task id list moves other rows | Ids must be non-empty strings of at most 36 bytes, bound as parameters; the locked rows' task id is compared again in PHP, case-sensitively |
| A half-done move | Each batch is one transaction; a failure rolls that batch back and leaves the rest with the old snapshot, with no time lost. The same call finishes it; already-moved rows are counted, not written |
| A stale managed entity writes the old board back | Loaded entries and timers of the batch are detached after the update |
| A timer stopped during a move leaves its entry behind (audit ID-L2) | The time is safe (count and totals per user unchanged) but counts in the source until another call. **Residual, host side:** USAGE asks for a delayed second call, or a check of new entries against the task's board |
| The audit record is lost (audit ID-L1) | `TaskTimeMovedEvent` fires after each committed batch, also for entries already at the target, so a retry reports what an earlier call committed and a failed batch does not hide earlier ones. A throwing listener is logged; the move finishes and the call then throws `TaskTimeMoveEventException` with the full result. The bundle has no audit table, so hosts store the event. `alreadyAtTarget`, `kept` and `secondsKept` repeat in every call's event, so summing them across calls counts twice (audit ID-I3) |

Residuals (author's view, for the auditor): kept entries keep counting in the source tenant until the period is reopened and the call repeated (owner decision 1); a batch holds row locks on up to 500 entries until its commit; with a listener that throws, other listeners of that one event dispatch do not run until the next call.

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
