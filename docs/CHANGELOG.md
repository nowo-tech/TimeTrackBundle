# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.21.0] - 2026-11-12](#1210---2026-11-12)
- [[1.20.0] - 2026-11-11](#1200---2026-11-11)
- [[1.19.1] - 2026-11-11](#1191---2026-11-11)
- [[1.19.0] - 2026-11-11](#1190---2026-11-11)
- [[1.18.0] - 2026-11-09](#1180---2026-11-09)
- [[1.17.1] - 2026-11-08](#1171---2026-11-08)
- [[1.17.0] - 2026-11-08](#1170---2026-11-08)
- [[1.16.2] - 2026-11-07](#1162---2026-11-07)
- [[1.16.1] - 2026-11-07](#1161---2026-11-07)
- [[1.16.0] - 2026-11-07](#1160---2026-11-07)
- [[1.15.0] - 2026-11-06](#1150---2026-11-06)
- [[1.14.0] - 2026-11-06](#1140---2026-11-06)
- [[1.13.0] - 2026-11-05](#1130---2026-11-05)
- [[1.12.0] - 2026-11-05](#1120---2026-11-05)
- [[1.11.0] - 2026-11-05](#1110---2026-11-05)
- [[1.10.3] - 2026-11-04](#1103---2026-11-04)
- [[1.10.2] - 2026-11-04](#1102---2026-11-04)
- [[1.10.1] - 2026-11-04](#1101---2026-11-04)
- [[1.10.0] - 2026-11-04](#1100---2026-11-04)
- [[1.9.2] - 2026-11-04](#192---2026-11-04)
- [[1.9.1] - 2026-11-02](#191---2026-11-02)
- [[1.9.0] - 2026-11-02](#190---2026-11-02)
- [[1.8.0] - 2026-11-02](#180---2026-11-02)
- [[1.7.0] - 2026-10-30](#170---2026-10-30)
- [[1.6.0] - 2026-10-26](#160---2026-10-26)
- [[1.5.2] - 2026-10-18](#152---2026-10-18)
- [[1.5.1] - 2026-10-13](#151---2026-10-13)
- [[1.5.0] - 2026-10-13](#150---2026-10-13)
- [[1.4.0] - 2026-10-13](#140---2026-10-13)
- [[1.3.7] - 2026-10-13](#137---2026-10-13)
- [[1.3.6] - 2026-10-09](#136---2026-10-09)
- [[1.3.5] - 2026-09-28](#135---2026-09-28)
- [[1.3.4] - 2026-09-27](#134---2026-09-27)
- [[1.3.3] - 2026-08-24](#133---2026-08-24)
- [[1.3.2] - 2026-08-19](#132---2026-08-19)
- [[1.3.1] - 2026-08-18](#131---2026-08-18)
- [[1.3.0] - 2026-08-04](#130---2026-08-04)
- [[1.2.0] - 2026-08-03](#120---2026-08-03)
- [[1.1.0] - 2026-07-30](#110---2026-07-30)
- [[1.0.6] - 2026-07-29](#106---2026-07-29)
- [[1.0.5] - 2026-07-16](#105---2026-07-16)
- [[1.0.4] - 2026-07-13](#104---2026-07-13)
- [[1.0.3] - 2026-07-08](#103---2026-07-08)
- [[1.0.2] - 2026-07-07](#102---2026-07-07)
- [[1.0.1] - 2026-07-06](#101---2026-07-06)
- [[1.0.0] - 2026-07-06](#100---2026-07-06)

## [Unreleased]

Target **1.22.0** (minor; Beacon spec 204 FR-029, Block 2 bundle side): the clients learn that the user is on leave.
Additive: without the new host port every client API body is byte for byte the 1.21 one; no schema change, no new scope.
The TimeTrack Desktop and Extension pick the fields up in their own releases (their repositories are not changed here).

### Added

- **Host port `Client\ClientUserLeaveProviderInterface::getCurrentLeave(string $userId, DateTimeImmutable $day):
  ?ClientUserLeave`** (`client_api.leave_provider`, default null). Framework-free (REQ-ARCH-001 rule 3): the user id
  string in, the bundle-owned value object `Client\ClientUserLeave` out. A new optional interface: no method is added to
  the profile, days-off or workday ports.
- **`Client\ClientUserLeave`** (`final readonly`, private constructor): `typed(string $publicType, string $until)` and
  `absent(string $until)` (medical leave, or any type the devices must not learn), `isAbsent()`. It holds only a public
  type (or none) and the last day; an invalid `until` throws `InvalidArgumentException`.
- **Client API, only with a leave provider**: `GET workday` gains `leave: {type, until}` or `null` (`type` is the cleaned
  public label, else the bundle's *Absent* in the user's language; `reminder` is null while on leave); `GET me` gains
  `features.leave` (= `features.workday`) and `links.leave` (`client_api.links_leave_route`, https only and only while
  `features.workday` is true, else null). `GET workday` always sends `Cache-Control: no-store, private` and
  `Vary: Authorization`.
  Workday action answers are unchanged (no leave in the idempotency store). A provider that throws, a leave that ended
  before the workday date or an unreadable workday date give `leave: null` and a warning without user data.
- **`daysOff` kind `leave`**, only with `client_api.leave_provider` (without it the entry is dropped as an unknown kind,
  as in 1.21): accepted from `ClientUserDaysOffProviderInterface`; its label is always sent as null (a host label is
  dropped and logged as `leave label dropped`). `ClientDaysOffNormalizer::normalize()` gains a trailing
  `bool $acceptLeave = false` and the constant `KIND_LEAVE`; `KINDS` is unchanged.
- **Labels** (`daysOff` and the leave type): every Unicode format character except ZWJ (tag characters included), line /
  paragraph separators and the fillers U+3164, U+115F, U+034F, U+FFF9–FFFB are removed, and a 320-byte cap
  (`LABEL_MAX_BYTES`) follows the 80-cluster cut; an empty result is null.
- Config `client_api.leave_provider`, `client_api.links_leave_route`; translation key `client_api.leave_absent` in the
  seven shipped languages.
- `ClientDaysOffNormalizer::label()` is public (the leave type is cleaned with it);
  `ClientWorkingTimeContext::reportsLeave()`, `leaveLink()`, `leave()` and its trailing optional constructor arguments
  `$leaveProvider`, `$leaveRoute`, `$logger`.
- Tests: `LeaveClientApiTest` (with the provider, medical as *Absent*, no comment / attachment / type in the bodies or
  the logs), `WorkingTimeClientApiTest` (without it: the exact 1.21 body), `LeaveDisabledClientApiTest` (the exact 1.21
  `GET me` body with a `leave` day dropped), `LeaveWorkerModeNoResetTest` (1,800
  requests, three people, no services resetter), `DddHostLeaveTest` (XML host user, wrapper security user, the port
  gets the id string; `lint:container`), `ClientApiV122LeaveTest`.

### Changed

- Audit NG (before the tag): NG-1 leave kind gated, NG-2 / NG-5 label cleaning and byte cap, NG-3 `GET workday` cache
  headers, NG-4 `links.leave` only while the workday API is on.
- Docs: CLIENT-API *Changes in 1.22*, USAGE *Leave on the clients* plus the standard and DDD host sections,
  CONFIGURATION, WORKING-TIME, FRANKENPHP-WORKER-AUDIT (re-audit 1.22), ROADMAP.

## [1.21.0] - 2026-11-12

Target **1.21.0** (minor; Beacon D-20261111-12, request 2026-11-11 *billable time entries*): the `billable` flag of an
entry is editable under the edit and period-lock rules and audited, and new live entries take a host default. No schema
change (the column exists since 1.11), no new scope; the client API change is additive in the current version. Hosts
that wrote the column themselves (Beacon override D23) can call the service instead.

### Added

- **`Service\TimeEntryBillableService`** (public): `change(UserInterface $actor, TimeEntry $entry, ?bool $billable)`,
  `changeById()`, `find()`. Refused with `Exception\BillableChangeRefusedException` (`not_found`, `read_only` for an
  imported entry, `anonymised` for an anonymised or ownerless one, `forbidden` when the edit grant says no) and with
  `PeriodLockedException` / `PeriodSubmittedException` (`WriteKind::Edit`) when the entry's period is locked (approved)
  or submitted; no role or listener overrides the lock. The lock check and the write run in one transaction holding the
  owner's write lock.
- **Audit**: `Event\TimeEntryBillableChangedEvent` (`TimeTrackEvents::TIME_ENTRY_BILLABLE_CHANGED`) after the commit of
  each real change: entry, owner and actor ids, old and new value, time and `via` (`api` / `host`). Ids and scalars only;
  a throwing listener is logged and does not undo the change.
- **Host port `Integration\TimeEntryBillableDefaultProviderInterface::defaultFor(string $scopeKey, ?string $taskRef):
  ?bool`** (scalars only, REQ-ARCH-001 rule 3), default `NullTimeEntryBillableDefaultProvider`. Asked for every new live
  entry stored without a value: timer stop / switch, idle reassign, `POST entries`, accepted calendar suggestions. A
  null answer keeps the flag unset, as before. Imported entries keep their source's value.
- **`TeamAccessGuard::isEditGranted()`**: the edit grant of `canEditEntry()` without the period lock (owner, manager with
  `security.manager_can_edit_entries`, admin roles, then the `edit` access-check event), so a caller can tell 403 from
  409. `canEditEntry()` is unchanged.
- **Client API**: `PATCH entries/{id}` `{"billable": true|false|null}` (scope `entries:write`, own entries only, 120 / h
  per token; route `clients.routes.entries_update`), and an optional `billable` on `POST entries`. CORS allows `PATCH`.
  `billable` was already read on every entry object. The TimeTrack Extension and TimeTrack Desktop pick the field up in
  their own releases (their repositories are not changed here).
- `TimeEntry::markBillable()` (`@internal`) and the optional store capability
  `Repository\FindableTimeEntryRepositoryInterface` (`findById()`), and on top of it the optional
  `Repository\LockingFindableTimeEntryRepositoryInterface` (`findForUpdate()`, a row-locked re-read; REQ-BC-001: a
  separate interface, so a store that implements only `findById()` keeps working and is re-read without a row lock). The
  Doctrine store implements both.
- **Audit MV (before the tag):** the entry is re-read under a row lock inside the owner's transaction and checked again
  (anonymised, imported or moved meanwhile: refused, MV-T-L1); `PATCH entries/{id}` answers 404 while
  `manual_entries.enabled` is false (MV-T-L2); a dedicated access-check action `edit_billable`
  (`TeamAccessGuard::ACTION_EDIT_BILLABLE`, `isBillableEditGranted()`, default: the edit grant) decides a change and an
  explicit `billable` on `POST entries` (`billable_forbidden`, 403; MV-T-L3); USAGE documents that `admin_roles` are not
  tenant-aware and that hosts answer `read_only` / `anonymised` as 404 to non-owners (MV-T-I1–I3).
- `ManualEntryService::create()` gains a trailing `?bool $billable = null`; `ManualEntryService`, `TimerService` gain a
  trailing optional `$billableDefaults` constructor argument (wired by the extension).

### Changed

- `TimeTrackCalendarApiController` takes `TimeEntryBillableService` (autowired).
- ROADMAP: the planned "Block 2" (leave, the REQ-ARCH-001 additive ports and the bundle-owned entity manager) moves from
  1.21 to **1.22**, the monitoring API from 1.22 to 1.23; the deprecations announced for 1.21 now land in 1.22.

## [1.20.0] - 2026-11-11

Target **1.20.0** (minor; Beacon request 2026-11-11): the calendar OAuth apps can be read at runtime from the host, for
example encrypted in its database, instead of `.env`. No schema change, no client contract change; without the new
option the behaviour is that of 1.19.1.

### Added

- **`calendar.oauth.app_provider`** (service id) and the port `Calendar\CalendarOAuthAppProviderInterface::app(string
  $provider): ?CalendarOAuthApp` (no framework type, REQ-ARCH-001). `GoogleCalendarOAuthClient` and
  `MicrosoftCalendarOAuthClient` ask it on **every** use — consent URL, code exchange, refresh, revoke and
  `isConfigured()` — once per call, and keep nothing (REQ-RUNTIME-002: a rotated secret applies to the next call in a
  worker with kernel reset false or a Messenger worker). With a provider the static client ids and secrets are ignored
  and not compiled into the container; `calendar.oauth.microsoft.tenant` is the default of an app without a tenant.
- **`Calendar\CalendarOAuthApp`**: final readonly value object (`clientId`, client secret, optional `tenant`). The secret
  is read only through `revealClientSecret()`; `__toString()`, `__debugInfo()` (var_dump, VarDumper and the profiler's
  dump panel), `json_encode()`, `__serialize()`, `var_export()`, an `(array)` cast, Serializer and Monolog see
  `[redacted]`, and unserializing throws. Deliberate reflection on the internal wrapper still reads it (audit ML-I1).
- **`Calendar\CalendarOAuthClients`**: provider discovery for a host Integrations page, its connect buttons and its
  refresh job (`configuredProviders()`, `isConfigured(CalendarProvider)`, `client(CalendarProvider)`), asked live on
  each call.
- **`CalendarSyncException::NOT_CONFIGURED`** (`not_configured`): with an app provider, a null app, an empty client id or
  secret, or an exception from the provider (its message dropped) hides the provider (`isConfigured()` false) and fails
  its calls closed: `authorizationUrl()`, `exchangeCode()` and `refresh()` throw `not_configured` without a request.
  Google `revoke()` needs no client credentials and still revokes (audit ML-L2). Stored tokens are never touched by
  the bundle. A provider exception logs a warning on the `time_track` channel with the provider name and the exception
  class only (optional PSR-3 logger, audit ML-I3).
- **`calendar.http_client`** (service id, default the framework `http_client`): the HTTP client of the calendar readers
  and the OAuth token calls, so a host can use a client the profiler does not trace (audit ML-L1).

### Security

- Audit ML (Pass, no GHSA). ML-L1 (since 1.19.0): in debug, the profiler's HTTP Client panel records token request
  bodies in plain text (`client_secret`, `code`, `code_verifier`, `refresh_token`); the redaction claims now name
  VarDumper and the dump panel only, SECURITY.md says never to enable the profiler where real secrets are used, and
  `calendar.http_client` lets a host bypass the traced client. ML-I2: Microsoft tenants `.` and `..` are refused
  (`common`); the pattern is `MicrosoftCalendarOAuthClient::TENANT_PATTERN`.

### Unchanged

- Token encryption stays the host's; PKCE `S256`, the state checks, the redirect URI rules, the PSR-20 clock and the
  `Retry-After` cap of 1.19.1 are untouched. Without `app_provider` the static configuration applies exactly as before
  (an empty static app still builds a URL, as in 1.19.1).

### Tests

- `CalendarOAuthAppProviderTest`: rotation between two calls (the new app on the very next call, one lookup per call),
  Microsoft tenant per app with fallback, a null / incomplete app hides the provider and rejects every call without a
  request, a failing store fails closed without its message, the secret never reaches a token error, the static
  fallback unchanged.
- `CalendarOAuthAppTest`: the secret stays out of `__toString()`, `serialize()`, `var_export()`, `var_dump()`,
  `print_r()`, VarDumper, `json_encode()`, casts, Serializer and Monolog.
- `CalendarOAuthAppWorkerTest`: one kernel kept alive (debug off, services resetter never run), 150 rounds alternating
  apps one, apps two and no app with a request through `http_kernel` in between, for Google and Microsoft (tenant
  alternating, token URL asserted each round); every call uses the app stored at that moment, Google still revokes
  without an app, and no secret reaches the test logger.
- Audit ML: Google revoke without an app (and with a failing store), `.` / `..` tenants, the provider-failure warning
  without the secret or the message, the `calendar.http_client` wiring.
- `CalendarExtensionTest`: the provider wiring, no static secret compiled in with a provider, a blank service id
  refused.

### Docs

- CONFIGURATION, USAGE (an InstanceSettings-backed provider), MEETINGS, UPGRADING, FRANKENPHP-WORKER-AUDIT.
- ROADMAP: the planned "Block 2" (leave, the REQ-ARCH-001 additive ports and bundle-owned entity manager) moves from
  1.20 to **1.21**, the monitoring API to 1.22; the deprecations announced for 1.20 now land in 1.21.

## [1.19.1] - 2026-11-11

Target **1.19.1** (patch; Beacon report 2026-11-11 after adopting 1.19.0).

### Fixed

- **The container builds the calendar HTTP services with any PSR-20 clock.** `CalendarTokenEndpoint` and
  `CalendarHttpClient` type-hinted `Symfony\Component\Clock\ClockInterface` while the bundle wires them with
  `Psr\Clock\ClockInterface`; a host whose PSR clock alias points to its own implementation (Beacon: Cookie Consent's
  `SystemClock`) got a `TypeError` building them. Both now accept `Psr\Clock\ClockInterface` (BC: Symfony's clock
  extends it). The 429 `Retry-After` wait still goes through the clock when it is a Symfony clock (a `MockClock` never
  waits) and falls back to `usleep()` otherwise. Every other clock consumer already type-hinted the PSR interface, and
  all of them are wired explicitly (no autowiring of the clock argument).

### Tests

- `PsrOnlyClockContainerTest`: a debug kernel with every feature on, `Psr\Clock\ClockInterface` aliased to a
  non-Symfony PSR clock, builds every bundle service whose constructor takes a clock and checks each holds the host
  clock (fails on 1.19.0 with the reported `TypeError`). `CalendarHttpClientTest` covers the plain-PSR-clock wait.

## [1.19.0] - 2026-11-11

Target **1.19.0** (TimeTrack closure, Block 1 / Beacon spec 198 and spec 210): the calendar meeting reader Beacon asked
for (BUNDLES request 2026-11-09), `accepted_user_classes` for the user reference port (audit JV-T3), `GET me
links.workingTime`, REQ-RUNTIME-002 (met) and part of REQ-ARCH-001. No schema change, no client contract break.

Audit LZ (REQ-SEC-004): Pass-Low at `b636b0a`; LZ-L1 and LZ-I1 / I2 / I3 / I5 are fixed in this release, LZ-I4 / I8
documented.

**REQ-ARCH-001 is only partially met.** This release adds the XML-mapped host test, wrapper users with
`accepted_user_classes`, the former-person listener switch and the docs. Still open, both pre-existing:
- rule 6: the services flush the configured entity manager, which is the host's by default;
- rules 1 / 3: 21 host ports take Symfony `UserInterface`.
Planned for **1.20**, additive: ports with a user id value object next to the old ones, and a documented and tested
bundle-owned entity manager. The old `UserInterface` ports become deprecated then and are removed in **2.0**.

### Added

- **Calendar readers for a host meeting sync** (Beacon spec 210). `Calendar\CalendarReaderInterface`, implemented by
  `GoogleCalendarEventSource` and `MicrosoftCalendarEventSource` next to the unchanged suggestions reader:
  - `calendars()`: the user's calendars as `CalendarDescriptor {id, name, primary}` (Google `users/me/calendarList`,
    readable ones; Graph `me/calendars`);
  - `events(..., $calendarId = 'primary')`: the suggestion fields of any calendar;
  - `meetings(..., $calendarId = 'primary')`: a `CalendarMeetingBatch` of `CalendarMeetingView` (original start for a
    stable occurrence id, IANA time zone, private / confidential flag, organizer e-mail and self flag, attendees
    `CalendarAttendee {email, self, declined, resource}`, join candidates from Google `hangoutLink` / video entry points
    and Graph `onlineMeeting.joinUrl`, description and location), `complete: false` when the page cap stopped the read.
    Non-meeting entries are skipped (Google event types other than `default`, Graph series masters). Every view lives
    in memory only: debug output is redacted and serialization throws.
  - Same HTTP rules as 1.14: `GET` only under a constant API base, Bearer, 10 s, no redirects, 2 MB per page, bounded
    pages (`calendar.max_pages`), error codes only.
- **OAuth helpers** (optional): `CalendarOAuthClientInterface` with `GoogleCalendarOAuthClient` and
  `MicrosoftCalendarOAuthClient` (authorization URL with PKCE `S256`, code exchange, refresh, revoke), `CalendarPkce`
  and `CalendarTokens` (redacted, not serializable). Microsoft Graph has no per-grant revoke: `supportsRevoke()` is
  false and `revoke()` returns false. Config `calendar.oauth.{google,microsoft}` (client id, secret, Microsoft tenant;
  empty = not configured); the secrets stay out of the `nowo_time_track.calendar` parameter.
- `calendar.microsoft_scope`: `Calendars.ReadBasic` (default, no body: task keys from titles only) or `Calendars.Read`
  (the meeting reader also asks for the body as text, for task keys in the description). Used by the reader and the
  OAuth helper.
- `GET me` **`links.workingTime`** (spec 198): the absolute https URL of the host's working-time page
  (`client_api.links_working_time_route`), only while `features.workday` is true; null otherwise.
- `accepted_user_classes` (audit JV-T3): the security user classes (wrappers / DTOs of a hexagonal host) that
  `DoctrineUserReferenceResolver` converts to the `user_class` entity. Default: none.
- `retention.former_person_listener` (default true): false leaves `FormerPersonSnapshotListener` off the host entity
  manager, for DDD hosts that delete users through their own flow and call `FormerPersonTimeEntries` themselves.

### Security (audit LZ)

- **LZ-L1:** the access and refresh tokens (`CalendarTokens`), the PKCE verifier (`CalendarPkce`), the credential and
  the meeting personal data (`CalendarMeetingView`, `CalendarAttendee`) are no longer public properties. They sit in an
  internal `SensitiveValue` and are read through methods (`accessToken()`, `refreshToken()`, `verifier()`, `email()`,
  `title()`, `description()`, `location()`, `organizerEmail()`, `attendees()`, `joinCandidates()`, `eventId()`,
  `seriesId()`, `originalStart()`). `json_encode()`, `(array)` casts, `var_export()`, `print_r()`, Symfony Serializer
  and Monolog formatters see `[redacted]` (every class is `JsonSerializable`); a test covers each channel.
- **LZ-D1:** Symfony VarDumper (`dump()`, `dd()`, the profiler) merges the real properties with `__debugInfo()`, so
  it printed the closure that holds the secret, and since 1.14 the `CalendarCredential` token. `SensitiveValue` now
  keys its debug info like its private property, so the dumper shows `[redacted]` in its place; the channels test
  covers the CLI and HTML dumpers.
- **LZ-I1:** `CalendarOAuthState::create()` / `matches()` (random state, constant-time compare);
  `authorizationUrl()` refuses a state shorter than 32 unreserved characters.
- **LZ-I2:** calendar ids made only of dots are refused; MEETINGS says to pass only ids `calendars()` returned.
- **LZ-I3:** `accepted_user_classes` takes concrete classes only: `UserInterface` or any other interface is refused at
  compile time.
- **LZ-I5:** a redirect URI with a fragment is refused (RFC 6749 §3.1.2).

### Changed

- **JV-T3:** `DoctrineUserReferenceResolver` converts a security user that is not a `user_class` instance only when its
  class is in `accepted_user_classes`, and only when a `user_class` row has its id (it now loads the entity with
  `find()` instead of building an unchecked reference). Both refusals throw `AccessDeniedException` before anything is
  written, also on databases without enforced foreign keys. See UPGRADING if your firewall user is a wrapper.
- The calendar readers and OAuth helpers are registered whether or not calendar suggestions are on (the suggestion
  sync still gets no source while `calendar.enabled` is false).
- REQ-RUNTIME-002: `FormerPersonSnapshotListener` clears its per-flush state itself at `kernel.terminate`,
  `console.terminate` and the Messenger worker events, not only on `kernel.reset`.

### Documentation

- MEETINGS: "Reading the calendars in your sync" (readers, OAuth helpers, Graph `Calendars.ReadBasic` versus
  `Calendars.Read`, what Beacon can delete). CLIENT-API: `links.workingTime`; the workday API checked against spec 198.
- ROADMAP: current state refreshed (1.18, the 1.19 – 1.21 plan); the monitoring text follows the owner decision of
  2026-11-11 (off unless the organisation enables it with a recorded DPIA; the worker sees it first).
- USAGE: "Standard Symfony host" and "DDD / hexagonal host" sections (REQ-ARCH-001, partial); `docs/FRANKENPHP-WORKER-AUDIT.md`
  re-audited for kernel reset false (REQ-RUNTIME-002) with the new worker test.

### Tests

- `WorkerModeNoResetTest`: one kernel, no services resetter, two people in turn (1,300 requests): no cross-user data,
  and 2,000 hot-path requests grow the memory by less than 64 KB.
- `DddXmlHostTest`: a plain PHP `user_class` mapped in XML plus a wrapper security user drive the services and the
  client API.

## [1.18.0] - 2026-11-09

Target **1.18.0**: a hexagonal host can drive the bundle with a security user that is not the `user_class` entity, and
its listeners can keep ids only (DDD adapter audit 2026-11-09, gaps G1, G2, G3). Additive; no schema change, no
contract change.

### Added

- **`Nowo\TimeTrackBundle\Integration\UserReferenceResolverInterface`** (`reference(UserInterface): object`), aliased to
  **`Doctrine\DoctrineUserReferenceResolver`**: a `user_class` instance is stored as it is (as before); any other
  security user becomes `getReference($userClass, $user->getId())` in the bundle's entity manager instead of failing at
  flush. It is injected (optional last argument `$userReferences`) into `TimerService`, `ManualEntryService`,
  `ImportedTimeEntryWriter`, `TimesheetService`, `BalanceLedger`, `ClientAuthService`, `DeviceAuthorizationService`,
  `CalendarConnectionService` and `CalendarRuleService`; built by hand without it they use
  `PassThroughUserReferenceResolver` (the previous behaviour).
- Scalar accessors on the events: `getUserId()` on `TimerStartEvent`, `TimerStopEvent`, `TimeEntryAccessCheckEvent`,
  `IdleResolvedEvent`, `TaskEffortAccessCheckEvent`, `TimeEntryCreatedEvent`, `TimeEntryImportedEvent`;
  `getViewerId()` on `TimeEntryListQueryEvent`, `TimesheetAccessCheckEvent`; `getTaskId()` / `getBoardId()` on
  `TimerStartEvent`; `getEntryId()` / `getEntryView()` on the four events that carry a `TimeEntry`.
- **`Dto\TimeEntryView`**: read-only scalar snapshot of an entry (`TimeEntryView::fromEntry()`).
- `getUserId(): ?string` on `TimeEntry`, `ActiveTimer`, `Timesheet`; `UserIdResolver::idOf(?object): ?string`.

### Changed

- `DoctrineUserReferenceResolver` refuses `0`, `'0'` and negative ids (audit JV-T3). USAGE states the wrapper
  requirements (same id space and string form, JV-T1 / JV-T4), that the `user_class` entity must keep implementing
  `UserInterface` for the former-person listener (JV-T2, documented rather than enforced, so existing setups keep
  booting) and which user each event's `getUserId()` means (JV-T5).

### Documentation

- USAGE: new section "Using it from a DDD / hexagonal application" (where the bundle sits in a hexagonal host, the ports, events and DTOs to build an anti-corruption layer on, the user reference port and the known limitations).

## [1.17.1] - 2026-11-08

The ledger purge follows the chain's links (HT-L1) and `verify` reports lines not linked behind a two-starts alarm (HT-L2).

Audited: Appendix IE, Pass (good) / Info. No schema change, no contract change.

### Fixed

- HT-L1: the retention purge takes each ledger chain's prefix in chain order, following the links from each start, instead of in `created_at, id` order. A line written on a clock behind sorts before the line it follows; when only that line was effective before the cut-off, the purge deleted it and kept its predecessor, and `verify` then reported the next line as not linked. Lines that no start reaches (a line deleted by hand before them) are still taken in sort order, as before.
- HT-L2: on a chain with several starts, `verify` now also follows the links from each start. A line deleted by hand behind the "the chain has N starts" alarm used to change nothing in the output; it now reads `the chain has N starts, and K line(s) are not linked to them`. A chain with several starts and no other break gives the same message as before.

## [1.17.0] - 2026-11-08

Target **1.17.0**. Audit status: Audit ID (2026-11-08): Pass (good) / Info at `41e0ef2`. No schema change; nothing changes for hosts that do not call the new service.

### Added

- **Task time follows a board move** (Beacon spec 217 FR-008; owner decision: the time follows the task, also across tenants). `TaskMove\TaskTimeMover::moveTasks($taskIds, $targetBoardId, $targetScopeKey, ?$actorId)` (public service, no route) sets `board_id_snapshot` and `scope_key` of every `TimeEntry` of the tasks, and `board_id_snapshot` of their running `ActiveTimer`s, so reports and billing count the hours where the task now is. Nothing else changes: duration, times, user, person, task, source and read-only state stay, and no entry is created or deleted. Batches of 500 entries, one transaction each, rows locked by primary key, loaded entities detached; calling again with the same arguments finishes a call that failed half way, and an entry already at the target is counted in `alreadyAtTarget`, not written.
- With timesheets on, on a move into another scope, an entry in a locked, approved or submitted period, in its current scope or in the target scope, stays where it is and is reported in `TaskTimeMoveResult::$kept` with a `TaskTimeKeptReason` (`source_period_locked`, `target_period_locked`). On a move within one scope such an entry follows in `board_id_snapshot` only (owner decision 2026-11-08): its scope, hours, totals and `entries_hash` do not change. Anonymised entries (`anonymised`) and, with timesheets on, entries with no user and no person reference (`owner_unknown`) stay too. The hashed timesheet snapshots and the balance ledger are never rewritten.
- Optional `fromBoardId` (the board the move left): only entries and timers on that board move, others stay as `on_another_board`, so a stale or replayed call cannot pull the time back to a board the task left (audit ID-M1). USAGE resolves the target at handling time.
- `TaskTimeMovedEvent` fires after each committed batch, also when its entries were all already at the target, so a retry reports what an earlier call committed (audit ID-L1). A listener that throws does not stop the move: the call finishes, then throws `TaskTimeMoveEventException` with the full result.
- `TaskTimeMoveResult` (`moved`, `alreadyAtTarget`, `secondsMoved`, `kept`, `secondsKept`, `timersMoved`, `keptIds()`, `keptEntryIds()`, `isComplete()`, `hasChanges()`), `TaskTimeMovedEvent` (`TimeTrackEvents::TASK_TIME_MOVED`, after the last commit), and the optional store capabilities `TaskTimeMoveRepositoryInterface` and `TaskTimerMoveRepositoryInterface` (the Doctrine stores implement them).

## [1.16.2] - 2026-11-07

Audit status: Appendix HT: Pass (good) / Low at `ad1de5c`. No schema change, no contract change. A new chain's first line stores a different value in `prev_hash`: see UPGRADING.

### Fixed

- HQ-L3: `findLast()` follows the chain: the head is the line no other line follows. Sorting by time and id could pick an older line (two lines in one millisecond, or a clock behind), after which every later line and approval was refused with `conflict` while `verify` said intact. A chain already stuck goes on after the upgrade.
- HQ-L4: a new chain's first line stores a marker in `prev_hash`, so two concurrent first lines collide on the unique key and the second is refused, instead of both committing as two starts. `getPrevHash()` and the hash read the marker as NULL. Chains that already have two starts keep them.

### Changed

- `testAMoveLeavesARowThatLeftBetweenItsReadAndItsLock` replaces a test that passed with the recheck removed (HQ-I1).

## [1.16.1] - 2026-11-07

Audit status: Appendix HQ: Pass (good) / Low at `1fe4dd1`. No schema change, no contract change.

### Fixed

- HM-L7: a move reads the ids with no lock, then locks the rows by primary key and checks them again. On MySQL 8 its locking read also locked the gaps of the source's index, so a sync into that source, or next to it, waited for the whole move or deadlocked. Now that wait is rare instead of constant: on MySQL and MariaDB a sync's insert can still occasionally wait for the rest of a move (audit HQ-L1).
- HM-L8: with `retention.anonymise_on_user_delete`, on a database without foreign key enforcement, rows that were not loaded are anonymised too.
- HM-I7: a move no longer refreshes a managed `Timesheet`, which dropped its pending changes.

### Changed

- `testResolveKeys` uses a frozen clock and proves the exact limit again (HM-I6).

## [1.16.0] - 2026-11-07

Audit status: Appendix HM (2026-11-07): Pass (good) / Low at `6848136`; residuals in `docs/SECURITY.md`. No schema change.

### Added

- `ImportedTimeEntryWriter::moveToPerson()`, `moveToUser()`, `moveBetweenUsers()`: move a source's imported entries between owners in one transaction, never deleting time. Locked rows stay (`keptLocked`), rows whose scope is not exactly the target's stay (`refusedScope`). `ImportedTimeEntryMoveResult`, `TimeEntriesMovedEvent` (`TimeTrackEvents::TIME_ENTRIES_MOVED`), `ImportedTimeEntryOwnerRepositoryInterface` and `LockingGuardTimesheetRepositoryInterface` (optional store capabilities; the Doctrine stores have them). With a scope provider a move or a claim also needs the row's scope among `scopesFor()` of the new owner.
- `upsertMany($rows, skipMismatches: true)`: a row naming another owner gets `ImportedTimeEntryOutcome::Mismatch` and the rest are written; `ImportedTimeEntryBatchResult::$mismatched`. The default is unchanged.

### Fixed

- A claim (`upsert`, `upsertMany`) with a scope provider is refused unless the entry's scope is among `scopesFor()` of the user: the provider's task answer says where the task is, not whether this user is there (audit HM-M1).
- HA-L18: the listener's `postFlush` runs first (priority 1000), so a host `postFlush` that flushes cannot cancel the settling.
- HA-L19: the listener settles only if the user row is gone, so a `remove($user)` in another listener's `onFlush`, with nothing else to flush, leaves that living user's rows alone.
- HM-L1: settling after a user's delete leaves alone a row that found another owner meanwhile.

## [1.15.0] - 2026-11-06

Entries with no user, and `privacy` on `GET me`. **Schema migration required for every host** (`docs/UPGRADING.md`).
Audit status: Appendix HA (2026-11-06): Pass (conditional) / Medium at `38f5230`. The only open Medium was accepted by the owner on 2026-11-06; residuals in `docs/SECURITY.md`.

### Added

- **Entries with no user** (Beacon request 2026-11-06 §2, owner rule 2026-11-06: tracked time is not lost, including when
  a user is deleted). `ImportedTimeEntryWriter::upsertForPerson(ImportedTimeEntryPerson, ImportedTimeEntryData)` and person rows
  in `upsertMany()` store a former person's hours: no user, source `import`, read-only, with `person_name_snapshot` and the
  opaque `person_ref`. A user takes such an entry only through an explicit `ImportedTimeEntryClaim` naming the reference, in
  the same scope.
- **`Retention\FormerPersonTimeEntries`** (public service): `release($user, ?$name)` stops a running timer and keeps a leaver's
  entries under their name; `anonymiseUser($user)` stops the timer and covers released entries too; `anonymisePerson($ref)`,
  `anonymiseUnidentified()`, `anonymiseOwnerlessEndedBefore($cutOff)`; `export($ref)` for GDPR art. 15 / 20; `personRefOf($user)`;
  `isAvailable()`. Anonymising keeps the time and clears name, reference, description and task title, hashes the external
  id, and keeps only the `closed_by` mark in `metadata`. An anonymised entry is never written again.
- **`retention.anonymise_on_user_delete`** (default false): a user removed through the entity manager has their entries anonymised.
- `TimerService::stopForUserDeletion($user)`: a timer still running when its user is removed through the entity manager is stopped
  and saved, ending at the deletion time and marked (`TimeEntry::isClosedByUserDeletion()`). A locked period does not refuse it.
- `TimeEntriesRemovedEvent::getPersonRefs()` and `getKeptCount()`; an optional `$actorId` on `removeForTasks()`, `removeForBoard()`,
  `removeForSource()`, `removeForUser()`.
- `TimeEntryListQueryEvent::includeOwnerless(?array $scopeKeys)`: lists ownerless entries for a viewer the host allows, limited to the
  viewer's tenant scopes. Off by default, and never on the client API (`listEntries*(..., $allowOwnerless)`). The reports page shows
  the name beside the task (`reports.former_person` when there is none). `TimerService::hiddenTaskEntryIds($entries, ?$viewer)` judges
  an ownerless entry by the viewer.
- `FormerPersonTimeEntryRepositoryInterface`, `FormerOwnerTimesheetRepositoryInterface` (optional store capabilities; the Doctrine stores
  have them); `TimeEntry::isOwnerless()`, `getPersonName()`, `getPersonRef()`, `getFormerUserId()`, `isAnonymised()`, `getAnonymisedAt()`;
  `toArray()` has `person` for an ownerless entry only.
- **`privacy` on `GET me` (TT-1)**: `ClientUserPrivacyProviderInterface::getPrivacy(UserInterface $user, ?string $locale): ?array`,
  optional extra of `client_api.profile_provider`. Without it the key is absent. The locale is the best match of `Accept-Language`
  among `framework.enabled_locales`, or null without the header. A `url` that is not a plain absolute `http(s)` URL is sent as null.
- `.scripts/check-rollback-clients.sh`: the reference SQL through the native clients, in CI. The functional suite also runs on MariaDB 10.6.

### Changed

- **`time_track_entries.user_id` is nullable and `ON DELETE SET NULL`** (was `NOT NULL`, `ON DELETE CASCADE`): deleting a user keeps their
  entries. `TimeEntry::getUser()` returns `?object`. A flush that deletes a user writes the name snapshot on their entries
  (`FormerPersonSnapshotListener`: after the commit, the profile provider's display name, never with an `@`, and `user:<id>`).
- `PeriodLockGuard`: an ownerless entry is judged by the deleted user's timesheets, found by `owner_ref` (a store without the capability
  fails closed); a former person's is writable; one with no identity has an unknown owner. A sync leaves an entry of a deleted user
  or with no identity alone (`Unchanged`).
- `FilteredImportedTimeEntryRepositoryInterface::removeImportedWhere()`: the row has `personRef`, and `userId` may be null.
- `ownersWithEntries()` and `findScopelessAfter()` skip ownerless entries.
- `ImportedEntryOwnerMismatchException::$externalId`: the row that stopped a batch, kept out of the message.
- `ImportedTimeEntryWriter::removeForUser()` is for re-association only, and now respects the period lock.
- `TimerService::start()` drops a reserved `closed_by` metadata key.
- Demo: the 1.11 migration's rollback no longer deletes imported time.

## [1.14.0] - 2026-11-06

Calendar suggestions, manual entries and the meetings API (TimeTrack 1.14.0, contract `timetrack-next` v1.1 §6, §10.3,
§22, built as stacked PRs). Every feature is off until configured; the schema change is required for every host
(always-mapped tables).

### Added

- **Calendar model** (always mapped, contract §3.5–§3.7): `Entity\CalendarConnection`
  (`time_track_calendar_connections`: opt-in per user, scope and provider, `mode` server / local, opaque host
  `credential_ref`, `consent_version` / `consented_at`, error **codes** only), `Entity\CalendarSuggestion`
  (`time_track_calendar_suggestions`: keyed HMAC `event_ref` / `series_ref`, `group_key`, task, `match_kind`, time range,
  status, entry link, expiry — no title, attendee, location, link or provider id), `Entity\CalendarRule`
  (`time_track_calendar_rules`: literal case-insensitive patterns for keyless events, `group_matches`). Reference SQL in
  `docs/sql/1.14.0/` and the demo migration `Version20261107000000`.
- **Provider adapters** (read-only, primary calendar, contract §6.2): `Calendar\GoogleCalendarEventSource` (`fields`
  mask `items(id,summary,start,end,status,recurringEventId,transparency,eventType,attendees(self,responseStatus)),nextPageToken`)
  and `Calendar\MicrosoftCalendarEventSource` (`$select=subject,start,end,isAllDay,isCancelled,showAs,type,seriesMasterId,responseStatus`,
  UTC), behind `Calendar\CalendarEventSourceInterface`; one HTTP path `Calendar\CalendarHttpClient` (GET only, constant
  base URIs, no redirects, 10 s timeout, 2 MB per page, bounded inline wait on 429, then `rate_limited`; error codes only).
- **Host interfaces**: `Calendar\TaskKeyResolverInterface` (task keys, Q-O8) and
  `Calendar\CalendarCredentialProviderInterface` (tokens, revoke, connect URL; Q-B9). `Calendar\CalendarCredential`
  and `Calendar\CalendarEventView` are redacted in debug output and refuse serialization.
- **Matching and sync** (contract §6.3–§6.7): `Calendar\CalendarEventMatcher` (drops cancelled / all-day / free /
  declined / non-default / too long / outside the look-back or not yet ended events; keys first, then user rules; one
  resolver call per sync; the user must be able to track the task), `Calendar\CalendarSyncService` (upsert by
  `event_ref`, decisions kept, dailies grouped per series or rule and ISO week, expiry purge, `status = error` + code),
  `Calendar\CalendarConnectionService` (connect / reconnect / pause / revoke with the host revoke first, owner only) and
  `Calendar\CalendarRuleService`; command `nowo:time-track:calendar:sync` (inside working hours only, AC-19).
- **Events** `CALENDAR_CONNECTION_CHANGED` and `CALENDAR_SYNC_FINISHED` (ids, codes and counts only; counts include
  `stale`).
- Sync hardening (audit GG): pending suggestions of events that are gone are deleted after a complete read (GG-L3);
  connections carry `last_attempt_at`, `next_attempt_at` and `failure_count` (backoff, least recently attempted first,
  outside-hours rows do not use the run limit, every failure is a per-connection code, GG-L5); titles cut to 1024
  characters before the host key extractor (GG-I4); `CalendarConnectionService::revokeAllFor()` (GG-I8).
- `Calendar\CalendarPersonalDataExporter` (contract §12.4): connections, rules and suggestions of a person for the
  host's GDPR export.
- **Configuration** `calendar.*` (`enabled`, `credential_provider`, `task_key_resolver`, `providers`, `modes`,
  `lookback_days`, `suggestion_ttl_days`, `sync_interval_minutes`, `max_event_seconds`, `max_rules_per_user`,
  `max_pages`); incoherent combinations are refused at compile time.

- **Entries without a timer** (contract §10.3, Q-O7): `Service\ManualEntryService` and `POST /api/time-track/entries`
  (`manual_entries.enabled`, off by default; sources `manual` / `calendar`; `Idempotency-Key`; ≤ 24 h, not in the
  future, ≤ `manual_entries.max_age_days` old, no overlap with a live entry or the running timer, period lock 409;
  120 / h per token). Optional store capability `OverlappingTimeEntryRepositoryInterface` (the Doctrine store has it).
- **Suggestions client API** (contract §6.4–§6.6, §10.3): `GET calendar/connections`, `DELETE
  calendar/connections/{id}`, `GET suggestions` (groups and singles, `refresh=1` once per 5 minutes),
  `POST suggestions/accept` (one `calendar` entry per occurrence, per-occurrence outcomes), `POST suggestions/dismiss`,
  `POST suggestions/resolve-keys` (local mode; 60 / min) — `Controller\TimeTrackCalendarApiController`,
  `Calendar\CalendarSuggestionService`.
- **Scopes** `entries:write`, `suggestions:read`, `suggestions:write` (explicit grant only); `GET me`
  `features.calendar_suggestions` and `features.manual_entries` are real.
- **Events** `TIME_ENTRY_CREATED` (`TimeEntryCreatedEvent`, for TaskBoard's time totals) and
  `CALENDAR_SUGGESTION_DECIDED` (ids only).
- `Support\Rfc3339`: the strict date-time profile of the 1.14 routes.
- **Meetings API** (contract §22, Beacon spec 210, Q-B13..Q-B24, Q-O16..Q-O22): `GET /api/time-track/me/meetings`
  (`Controller\TimeTrackMeetingsApiController`, route `nowo_time_track_client_me_meetings`) — the caller's own meetings
  from the host `Meeting\MeetingProviderInterface` (DTOs `MeetingQuery`, `MeetingView`, `MeetingAttendeeView`,
  `MeetingTaskLink`, `MeetingPage`, `MeetingSyncState`, `MeetingSyncStatus`); strict query validation (400 with
  `{field}`, never the value; no user parameter), `MeetingResponder` privacy filters (whitelist, private events
  cleared, ≤ 50 internal attendees, trackable task links, cleaned titles), `MeetingLinkDetector` (https allow-list:
  Teams, Meet, Zoom, Jitsi + `meetings.jitsi_hosts`, Webex), `no-store` headers on every answer, 6 / min and 60 / h per
  token (429 + integer `Retry-After`), 503 `meetings_unavailable`; JSON schema `docs/schemas/meetings.v1.json`; host
  guide `docs/MEETINGS.md`. Nothing about meetings is stored, cached or logged by the bundle.
- **Scope** `meetings:read` (explicit grant only). **`GET me`**: `features.meetings`, `meetings: {status,
  refreshAfterSeconds}`, `links.calendars`.
- **Configuration** `meetings.*` (off by default; `enabled` requires `provider`).
- *Track the meeting* (Q-B22): `timer/switch` and `timer/start` accept `metadata: {origin: "meeting"}` without
  `client_api.allow_metadata`; `TimerService::switchTo()` takes the new timer's metadata (optional argument).
- Write hardening (audit GG): `POST entries` and suggestion accept / dismiss run under a per-user row lock
  (`UserSerialisedTimeEntryRepositoryInterface`), with the `Idempotency-Key` re-checked and stored under it (GG-L1);
  one transaction per accepted occurrence, events after the commit, per-occurrence `internal_error` (GG-L2);
  `resolve-keys` leaves out keys of untrackable tasks (GG-L4); `no-store` on the calendar routes (GG-I1); CORS allows
  `Idempotency-Key` (GG-I6).

### Changed

- `symfony/http-client` is a new `suggest` (calendar server mode) and a dev dependency.
- CORS `Access-Control-Allow-Methods` lists `DELETE` and `Access-Control-Allow-Headers` lists `Idempotency-Key` too.

## [1.13.0] - 2026-11-05

Working time, hours balance and timesheets (TimeTrack 1.13.0, contract `timetrack-next` v1.0 §3–§5, built as stacked
PRs). Every feature is off until configured; the schema change is required for every host (always-mapped tables).

### Added

- **Entry tenant scope** `time_track_entries.scope_key` (`VARCHAR(64)` NULL) with the index
  `idx_time_track_entries_user_scope_started (user_id, scope_key, started_at)`: with timesheets on, the timer service
  and the import writer store the scope of the task (`TimesheetScopeProviderInterface::scopeKeyForTask()`).
  `TimeEntry::getScopeKey()`.
- **Timesheet model** (always mapped): `Entity\Timesheet` (`time_track_timesheets`, UUID v7, nullable owner FK
  `ON DELETE SET NULL` + `owner_ref`, optimistic `version`), `Entity\TimesheetEvent` (`time_track_timesheet_events`,
  append-only audit rows chained by SHA-256) and `Entity\BalanceLedgerLine` (`time_track_balance_ledger`, append-only,
  chained per owner and scope), their repositories (`TimesheetRepositoryInterface`, `TimesheetEventRepositoryInterface`
  — append and read only —, `BalanceLedgerRepositoryInterface`, `TimesheetRetentionRepositoryInterface`) and Doctrine
  stores. Reference migrations for MySQL, PostgreSQL and SQLite in `docs/sql/1.13.0/` (tested against all three).
- **`timesheets` configuration** (`enabled: false` by default; `scope_mode: provider|single`, `scope_provider`,
  `approver_provider`, `period: week|month`, `default_timezone`, `submit_requires_period_end`, `auto_lock_after_days`,
  `allow_self_approval` — fixed false —, `admin_roles_view`, `reopen_roles`, `approver_sees_notes`,
  `audit_blocked_edits`, `retention_years` 4–10). Incoherent combinations fail at compile time.
- **`Timesheet\TimesheetScopeProviderInterface`** (host tenant scopes, Beacon `org-<uuid>`), `TimesheetScope`,
  `SingleScopeProvider` (`scope_mode: single`), **`TimesheetApproverProviderInterface`** (who approves whom, per scope).
- **Period lock** `Timesheet\PeriodLockGuard::assertWritable($user, ?$scopeKey, $instant, WriteKind)`: hard lock
  (`PeriodLockedException`, 409 `period_locked` with `lockedThrough`) and submitted soft lock
  (`PeriodSubmittedException`, 409 `period_submitted`); a null scope is checked against every scope of the user.
  Enforced on every bundle write path: `TimerService::startAt()` / `start()` / `switchTo()` / `resolveIdle()`,
  `ImportedTimeEntryWriter` (new outcome `ImportedTimeEntryOutcome::Locked`, `ImportedTimeEntryBatchResult::$locked`),
  imported-entry removals, `TeamAccessGuard::canEditEntry()` / `canDeleteImportedEntry()` (evaluated after the
  listener: a grant is ignored). A stop is always allowed; an entry written into a period locked while its timer ran
  fires `TimesheetLateEntryEvent`. Refusals fire `PeriodLockRefusedEvent` (ids only).
- **Events** constants `TIMESHEET_SUBMITTED`, `TIMESHEET_WITHDRAWN`, `TIMESHEET_APPROVED`, `TIMESHEET_REJECTED`,
  `TIMESHEET_REOPENED`, `TIMESHEET_AUTO_LOCKED`, `TIMESHEET_ACCESS_CHECK`, `TIMESHEET_LATE_ENTRY`,
  `PERIOD_LOCK_REFUSED`, `BALANCE_LEDGER_LINE_ADDED`.
- **`nowo:time-track:entries:backfill-scope [--batch=500] [--dry-run]`**: gives pre-1.13 entries their scope (keyset,
  idempotent, resumable).
- **Working time and hours balance** (`working_time`, off by default; contract §4): `WorkingTime\WorkingCalendarProviderInterface`
  (`CalendarDay`, `CalendarDayKind`; default `DerivedWorkingCalendar` from the 1.12 working hours and days off; every
  day validated by `CalendarDayNormalizer`, failing closed to `none`), `WorkedTimeSourceInterface` (`WorkedDay`; default
  `TaskTimeWorkedTimeSource`: live task time of the scope, overlaps merged, `legalRecord: false`),
  `OvertimePolicyInterface` (`OvertimeInput`, `OvertimeResult`; default `ConfigOvertimePolicy`: daily `expected` /
  `ordinary_max` / `none`, weekly `expected` / `legal_max`, annual counter of ratified minus compensated-within-4-months
  overtime with `overtime_cap_near` / `overtime_cap_exceeded` flags, informational `rest_short`, `break_missing`,
  `long_day` flags), the pure `WorkingTimeBalanceCalculator` and `WorkingTimeBalanceService::report()` →
  `BalanceReport` (days, ISO weeks, totals, running balance, ledger, `source: register|task_time`, `legalRecord`,
  policy id + version). The running balance continues from the last locked timesheet's approved snapshot.
- **Balance ledger** `WorkingTime\BalanceLedger::add()`: `opening` (once), `adjustment`, `overtime_paid` (negative),
  `overtime_compensated` (positive), `reversal` (never edited, chained per owner and scope); approvers only, never the
  owner; reasons required and sanitised; refused in a locked period; `overtime_ratified` only from the approval.
  `BalanceLedgerLineAddedEvent`, `LedgerRefusedException` (stable codes).
- **`Timesheet\TimesheetAccessGuard`**: tenant-aware view / submit / approve / reject / reopen / adjust decisions (no
  self-approval, admin roles only with `timesheets.admin_roles_view` or `reopen_roles`), deny-only
  `TimesheetAccessCheckEvent`; `TimesheetTimezoneResolver` (scope zone → working-hours zone → default).
- **Timesheets** `Timesheet\TimesheetService` (contract §5): `submit` (owner only; refused with `timer_running`,
  `not_started`, `not_ended` with `submit_requires_period_end`), `withdraw`, `approve` (approvers only, never the owner —
  admins included —, entries hash re-checked → `stale`, hard lock, final snapshot, one `overtime_ratified` ledger line
  with `working_time.overtime.ratify_on_approval`), `approveMany` (per-id outcomes), `reject` (comment 10–1000
  characters, kept for the owner until the next submit), `reopen` (approver or `reopen_roles`, reason required, lock
  cleared, ratified overtime reversed), `autoLock`, `queue`, `view` (row created on the owner's first view). Each
  transition is one transaction with its audit row; concurrent decisions fail with `conflict` (optimistic `version`).
  Other users' timesheets answer `not_found` (no existence oracle). `TimesheetException` (stable codes + HTTP status),
  `TimesheetTransitionEvent` (ids only; comment presence flag).
- **Audit trail** `TimesheetAuditTrail` (seq, `prev_hash`, head hash; `edit_blocked` rows with
  `timesheets.audit_blocked_edits`, one per timesheet per minute) and `TimesheetSnapshotBuilder` (snapshot totals of
  contract §5.8 and the canonical entries hash; imported entries counted apart, unassigned entries listed for the owner).
- **Commands** `nowo:time-track:timesheets:auto-lock [--lookback-days=62]` (with `timesheets.auto_lock_after_days`;
  rows only for users with entries), `nowo:time-track:timesheets:purge [--batch=500]` (retention: `retention_years`
  after the end of the period's month; the only delete path) and `nowo:time-track:timesheets:verify [--scope] [--user]`
  (`AuditChainVerifier`: names the first broken link of every timesheet and ledger chain).
- `Repository\EntryOwnersRepositoryInterface` (implemented by `DoctrineOrmTimeEntryRepository`).
- **Client API** (contract §10.1–§10.2, `TimeTrackWorkingTimeApiController`): `GET workday` and
  `POST workday/{clock-in|break-start|break-end|clock-out}` (R-GDPR §8: `Workday\WorkdayProviderInterface`,
  `WorkdayAction`, `WorkdayState`, `WorkdayConflictException`, `WorkdayForbiddenException`; `Idempotency-Key` replays for
  24 h through `ClientIdempotencyStore`, `idempotency_mismatch`, `occurredAt` window), `GET balance`, `GET timesheets`,
  `POST timesheets/{periodKey}/submit|withdraw`. New scopes `workday:read`, `workday:write`, `balance:read`,
  `timesheet:read`, `timesheet:write` (explicit grant only, `ClientScope::defaults()`). `client_api.workday_provider`
  and the `clients.routes` keys `workday`, `workday_action`, `balance`, `timesheets`, `timesheet_submit`,
  `timesheet_withdraw`. Audit actions `workday_action`, `timesheet_submitted`, `timesheet_withdrawn`.
- **`GET me`**: `features.workday` (real), `timesheets`, `balance`, `calendar_suggestions` / `manual_entries` (false
  until 1.14), `task_effort`; `timesheet` summary of the current period (`Client\ClientWorkingTimeContext`).
- **Task effort** (TimeTrackDesktop recommendation 4): `estimateSeconds`, `loggedSeconds` (total of every user, never
  per user) and `remainingSeconds` on `GET tasks` and every timer payload; `TaskReference::$estimateSeconds` /
  `$loggedSeconds` (optional), `Repository\TaskEffortRepositoryInterface` (Doctrine store implements it).
- **Components** (contract §16a, no template overrides): `nowo_time_track_component('TimeTrack:TimesheetWeekGrid' |
  'TimeTrack:BalanceCard' | 'TimeTrack:LedgerTable', props)` backed by `Ui\TimesheetViewBuilder`, with labels, visible
  actions and their host URLs, density and row options; reject / reopen / adjustment / reversal dialogs follow the
  UiKit 2.29 anatomy. `Timesheet\TimesheetFormHandler`, `Form\TimesheetDecisionType`, `Form\LedgerLineType`, the
  `nowo_time_track_duration` filter. Translations in the 7 locales (`timesheet.*`, `balance.*`, `ledger.*`,
  `reports.source_manual` / `source_calendar`).
- `PeriodLockGuard::blockedEntryIds()` (one query per owner for lists).
- `docs/WORKING-TIME.md`.
- `TimeEntrySource::Manual` and `TimeEntrySource::Calendar` (live, editable; write paths in 1.14).
- `Repository\FilteredImportedTimeEntryRepositoryInterface` (bulk removal that keeps locked entries) and
  `Repository\ScopeBackfillRepositoryInterface`, both implemented by `DoctrineOrmTimeEntryRepository`.
- `ValueObject\Uuid::generateV7()`, `Support\CanonicalJson`, `Support\StorageTime`.

### Changed

- **Default client scopes**: a token without stored scopes, a login whose authenticator names none and a device request
  without `scopes` get `ClientScope::defaults()` (the four pre-1.13 scopes) instead of every scope. `ClientScope::all()`
  still lists every case.
- `GET me.features` gains keys and `GET me` gains `timesheet`; `RESERVED_FEATURES` is `['monitoring']`.
- Entries (`GET entries`, `GET export` JSON) gain `scopeKey` (in `TimeEntry::toArray()`) and `locked`; the CSV gains
  the columns `scope_key` and `locked` (appended; `TimeEntryCsv::render()` takes the locked ids as a 4th argument).
- Timer routes answer `409 period_locked` (with `lockedThrough`) / `period_submitted` when timesheets are on.
- `nowo-tech/ui-kit-bundle` constraint `^2.29` (was `^2.20`): the dialogs use the 2.29 anatomy.
- `TimerService`, `ImportedTimeEntryWriter` and `TeamAccessGuard` take an optional `PeriodLockGuard` (and the first two
  a scope provider) as their last constructor arguments; without them (or with timesheets off) they behave as in 1.12.
- `TimeTrackMetadataListener` maps the three new tables (prefix and user class) through three optional arguments.

### Security

- REQ-SEC-004 audit GB (block 1): period keys are limited to a sane window (`Period::MIN_DATE` 2000-01-01 –
  `Period::MAX_DATE` 2099-12-31; `Period::fromKey()` refuses keys outside it, `Period::inWindow()`; periods of stored
  dates are unaffected) (GB-M1).
- The ledger gets the unique index `uniq_time_track_balance_ledger_chain (owner_ref, scope_key, prev_hash)`: two
  concurrent writers can no longer fork a chain (the second append fails) (GB-L4), the index
  `idx_time_track_balance_ledger_reverses (reverses_id)` (GB-I8) and the column `purged_before` (DATE NULL, not hashed).
- The retention purge deletes, per ledger chain, only the chain-order **prefix** of lines effective before the cut-off
  and marks the first line kept (`purged_before`), so `timesheets:verify` keeps verifying the rest of the chain instead
  of reporting it as broken; a timesheet still referenced by a kept ledger line is kept with it (GB-L3).
- `nowo:time-track:entries:backfill-scope` leaves entries of a locked or submitted period without a scope and reports
  them (`… left in a locked or submitted period`): an approved timesheet no longer gains entries after approval (GB-L5).
- CI: the whole functional suite also runs on MySQL 8.0 and PostgreSQL 16 (`functional-databases` job).
- REQ-SEC-004 audit GB (block 2): `WorkingTimeBalanceService::report()` has hard bounds — dates inside the period
  window, the range ending at most `MAX_FUTURE_DAYS` (366) days after today (`InvalidArgumentException` otherwise), and a
  computed span (running-balance start to the end of the range) of at most `MAX_SPAN_DAYS` (3660 days, ten calls):
  beyond it no report is computed (null) and a `balance_span_exceeded` warning is logged; lock timesheets (their
  snapshots restart the running balance) or move `working_time.balance.start_date` forward (GB-M1).
- `BalanceLedger::add()` answers `LedgerRefusedException('conflict')` when a concurrent line was appended to the same
  chain (unique chain index) instead of forking it (GB-L4).
- REQ-SEC-004 audit GB (block 3): `TimesheetService::period()` answers `not_found` for keys outside the period window or
  starting after the next period; `view()` creates the owner's row only for the current and the previous period
  (contract §3.2, lazily on first view) and answers an unstored open draft (`Timesheet::isDraft()`) for any other
  period; the new `peek()` never writes (GB-L1, GB-M1).
- `timesheets:verify` accepts a ledger chain that restarts at the line marked by the retention purge and reports a
  chain with more than one start (GB-L3).
- `Timesheet\TimesheetPersonalDataExporter::export($ownerRef)` (public service): the person's timesheets with their
  audit trail and their ledger lines, by `owner_ref`, without other people's ids, for the host's art. 15 / 20 export
  (contract §12.4, GB-L6).
- REQ-SEC-004 audit GB (block 4): `GET timesheets` and the week-grid component mask imported entries whose task the
  owner can no longer see (title and description), as `GET entries` / `GET export` do since 1.11 (GB-M2).
- **Task effort** (owner decision 2026-11-05, GB-M3): `loggedSeconds` is the caller's own total; the total of every user
  only for users the host lets manage the task — new deny-by-default event `nowo_time_track.task_effort.access_check`
  (`Event\TaskEffortAccessCheckEvent`, `TeamAccessGuard::canSeeTaskEffortTotal()`);
  `TaskEffortRepositoryInterface::loggedSecondsByTask($taskIds, ?$userId)`. Tasks and timers gain
  `loggedSecondsScope` (`own` / `all`, null without a figure) so clients can label the figure.
- `timesheet.error.*` messages for every ledger refusal code (`forbidden`, `reason_required`, `invalid_seconds`,
  `invalid_date`, `opening_exists`, `already_reversed`, `not_reversible`, `ratify_only`; `conflict`, `not_found` and
  `invalid_request` shared, `conflict` reworded for both) in the seven locales.
- `GET me` never creates a timesheet row (`TimesheetService::peek()`); `GET timesheets` answers `id: null` for an
  unstored draft period, 404 for keys after the next period or outside 2000–2099; `GET balance` answers 400 for ranges
  outside the window or ending more than 366 days after today (GB-L1, GB-M1).
- `TimesheetFormHandler` checks the CSRF token itself (fails closed without a token manager) (GB-L2); owner actions
  use the posted `scope` + `period` (new hidden fields of `TimesheetDecisionType`; drafts can be submitted) and a
  foreign timesheet id is `not_found` (GB-I3); ledger minutes beyond ±527 040 are `invalid_seconds` instead of an
  integer overflow (GB-I9).
- The week grid and the ledger table check `canView()` for the viewer; the ledger table drops lines of another owner or
  scope (GB-I2).
- Docs: no web ledger rate limit in 1.13 (hosts must rate-limit), workday providers deduplicate on the idempotency key,
  unscoped timers and submit, `TimesheetPersonalDataExporter`, ledger tail not anchored (GB-L6, GB-I4, GB-I6, GB-L4).
- Demo: `nowo-tech/ui-kit-bundle` 2.30 (lock refreshed with Symfony 8.1.11, no advisories) and a minimal 1.13 showcase
  page `/timesheet` (week grid + balance card, submit / withdraw through `TimesheetFormHandler`).

## [1.12.0] - 2026-11-05

Minor release (additive): days off on `GET me` and one shape for `workingHours`.

### Added

- **`GET me.daysOff`**: the user's holidays and vacations from today to today + 60 days, a list of
  `{date: "YYYY-MM-DD", kind: "holiday"|"vacation", label: ?string}` (dates in the working-hours time zone), or `null`
  when unknown. Always present. TimeTrack Desktop 0.5.0+ (unreleased; desktop PRs #14 / #15) reads it; the browser
  extension ignores it. Labels reach the user's devices: public holiday names only, `null` for vacations.
- **`ClientUserDaysOffProviderInterface::getDaysOff(UserInterface $user, DateTimeImmutable $from, DateTimeImmutable $to): ?array`**:
  optional extra of the `client_api.profile_provider` service (like `ClientUserLocaleProviderInterface`).
  `NullClientUserProfileProvider` implements it and returns `null`.
- **`ClientDaysOffNormalizer`**: validates the provider value entry by entry. An entry with a bad date or an unknown
  kind (`kind` is matched case-insensitively) is dropped alone and logged as a warning; labels are cleaned (control
  characters → space, bidi / zero-width removed, Unicode trim) and cut to 80 grapheme clusters; only the first 400
  entries are read; out-of-range entries dropped, one per date, sorted. A value that is not a list is sent as `null`.
- **`ClientWorkingHoursNormalizer`**: normalises `workingHours` for `GET me`.
- **`Integration\BatchTaskProviderInterface`** (optional): a task provider may answer `filterTrackable(user, taskIds)`
  for many tasks at once; `TimerService::hiddenTaskEntryIds()` then makes one call per entry owner instead of one per
  (owner, task) (audit FJ-I8).
- **`ImportedTimeEntryWriter::MIN_YEAR` / `MAX_FUTURE_SECONDS`** and an optional `$clock` argument (PSR-20, wired to
  `ClockInterface` when the host has one).

### Changed

- **`GET me.workingHours` is always `{timezone, slots: [{day: 1-7, from: "HH:MM", to: "HH:MM"}]}` or `null`.** The
  provider may return the `slots` shape or the documented 1.9 `days` shape (`{timezone, days: {mon: [["09:00","17:00"]]}}`),
  which TimeTrack Desktop 0.4 could not read; both are normalised to `slots`. Overnight ranges are split at midnight, overlapping
  or touching ranges of a day are merged, `24:00` becomes `23:59`. Malformed values (unknown time zone, bad day or time,
  empty range, more than 100 ranges, no `slots` / `days`) fail closed as `{timezone, slots: []}` (never inside working
  hours) with a warning log instead of being passed through; `null` stays "the provider returned null".
- **`ImportedTimeEntryRepositoryInterface` follows the configured store** (audit FJ-L3): the service is now whatever
  `TimeEntryRepositoryInterface` resolves to (a host replacement or decorator included), built by
  `Repository\ImportedTimeEntryRepositoryResolver`, instead of an alias to the raw Doctrine store. When the configured
  store does not implement the interface, getting the service throws `LogicException` instead of silently reading
  another data set.
- **Masked imported entries hide their description too** (audit FJ-I3): with `taskVisible: false`, `toArray()` returns
  `description: null` and the CSV `description` cell is empty (client API `GET entries` / `GET export`).
- **Imported timestamps have a plausibility window** (audit FJ-I6): `startedAt` before 1970 (`startedAt` / `too_old`)
  or `endedAt` more than one day after now (`endedAt` / `future`) is refused before anything is written, instead of
  failing the whole batch at the database (MySQL `DATETIME` range).
- **Task-scope removals bind at most 1 000 task ids per query** (`DoctrineOrmTimeEntryRepository::TASK_IDS_PER_QUERY`,
  driver parameter limits), and removed entries that the entity manager had loaded are detached (audit FJ-I7).
- **Masked titles are translated only into shipped languages** (audit FJ-I9): `de`, `en`, `es`, `fr`, `it`, `nl`,
  `pt` (a region such as `pt_BR` uses its language); any other `me.locale` uses the translator's default locale, so an
  arbitrary tag never makes the translator build a catalogue. `GET me.locale` itself is unchanged. A user
  `getLocale()` that needs arguments (or is not public / is static) is ignored instead of making `GET me` fail.

### Documentation

- USAGE / TASK-BOARD-INTEGRATION: masking asks what the entry **owner** may track, not the viewer (audit FJ-I4).

## [1.11.0] - 2026-11-05

Imported time entries (ClickUp import, Beacon spec 208; contract `clickup-import-1.24/CONTRACT.md` v1.0 §11). Minor:
additive nullable columns, one new enum case, two new indexes.

### Added

- **`TimeEntrySource::Import`** (`import`) with `isLive()` / `isReadOnly()`. Imported entries are history: read-only,
  never timers, never live time.
- **External identity**: nullable `external_source` (≤ 64) and `external_id` (≤ 128) on `time_track_entries`, unique
  together (`uniq_time_track_entries_external`); nullable `billable` and `description` (unbounded text: the full
  description of the source tool is kept, **no length cap**).
- **`Import\ImportedTimeEntryWriter`**: `upsert()` / `upsertMany()` (≤ 500 rows, one flush, all rows validated
  first; unchanged entries are not written), `remove()`, `removeForUser()`, `removeForTasks()`, `removeForBoard()`,
  `removeForSource()` (keyset batches, constant memory). It never starts or stops a timer and never fires timer
  events. It **refuses to move an entry to another owner** (`ImportedEntryOwnerMismatchException`): re-association is
  remove + upsert by the host. Validation: source / id patterns, `0 < duration ≤ end − start + 60 s`, at most
  `imports.max_entry_seconds` (31 days).
- **`Import\ImportedTimeEntryDeleter`** and **`TeamAccessGuard::canDeleteImportedEntry()`**: deleting one imported
  entry is denied by default, the host grants it through `TimeEntryAccessCheckEvent` action `delete_imported`.
- **`Repository\ImportedTimeEntryRepositoryInterface`** (implemented by the Doctrine store): `findByExternal()`,
  `findByExternalSource()`, `findLiveByUserAndPeriod()`, live paging, `saveAll()`, `removeImported()`,
  `canRetryAfterFailedSave()`, `attachUser()`.
- Events **`TIME_ENTRY_IMPORTED`** (`TimeEntryImportedEvent`: created / updated, previous duration and task) and
  **`TIME_ENTRIES_REMOVED`** (`TimeEntriesRemovedEvent`: scope, count, seconds per task, owner ids, actor).
- `TimeEntryListQueryEvent::setIncludeImported()` (default `true`): live-time views list live entries only.
- **`Retention\TimeEntryRetentionPolicy`** and `retention.include_imported` (default `false`): any retention purge
  must skip imported entries (`excludedSources()`); the bundle itself ships no purge yet.
- Index **`time_track_entries_ended_idx` on `(ended_at, id)`** (Beacon request `2026-11-05-timetrack-ended-at-index`,
  PERF-TT-PURGE-FULLSCAN), same name and columns as Beacon's own migration; and `idx_time_track_entries_source`.
- Client API, export (JSON / CSV) and the reports page: `billable`, `description`, `readOnly`, `taskVisible`; CSV
  appends `billable`, `description`, `imported`. The external identity is never exposed.
- Translations `reports.source_import` and `reports.task_not_visible` in the 7 locales.
- **`GET me` has `locale`** (requested by TimeTrack Desktop): the user's preferred locale (`es`, `pt_BR`, …) or `null`.
  It comes from the profile provider when it also implements the new `Client\ClientUserLocaleProviderInterface`, else
  from the user's own `getLocale()` when the user class has one; anything that is not a locale is sent as `null`.
- Reference migration `demo/symfony8/migrations/Version20261105000000.php` (MySQL) for the new columns and indexes.

### Changed

- **Imported entries never clamp a backdated live start**: `findLatestEndedAt()` and the in-memory fallback ignore
  them.
- `TeamAccessGuard::canEditEntry()` is always `false` for an imported entry; no access-check listener can grant it.
- **Masked titles**: an imported entry whose task its owner cannot track (`TaskProviderInterface::canUserTrack()`)
  shows the translated `reports.task_not_visible` (in the user's `me` locale; `TimeEntry::HIDDEN_TASK_TITLE` without a
  translator) with `taskVisible: false` in the client API, the export (JSON / CSV) and the reports page
  (`TimerService::hiddenTaskEntryIds()`).
- **Writer hardening**: two rows for one external identity in one `upsertMany()` call are refused (`rows` /
  `duplicate`); titles and descriptions must be valid UTF-8 (`encoding`); the title is cleaned like the description
  and kept on one line (LF, U+2028 and U+2029 runs become one space).
- **Concurrent imports** (audit FJ-L1): a unique violation on flush (a concurrent run inserted an identity first)
  writes nothing; the whole batch is then read again once, the raced row becomes an update (owner check still
  applied) and new rows in the same batch are created with their owners re-attached to the reset entity manager
  (`ImportedTimeEntryRepositoryInterface::attachUser()`). Without a `ManagerRegistry`, or inside a host transaction
  (`canRetryAfterFailedSave()` false), the unique violation is rethrown instead and the host retries the slice.

### Documentation

- Task title masking is per surface: host overrides of `reports.html.twig` (including `nowo_ui_list_row`) and host
  lists / exports must apply `hiddenTaskEntryIds()` / `toArray(false, …)` (FJ-L2).
- The client API's own `GET entries` and `GET export` always include imported entries (GDPR art. 15 / 20) and ignore
  `setIncludeImported(false)` (FJ-I1).
- `TimeEntryImportedEvent` carries the managed entry (title snapshot and description included); listeners must not
  log or forward them (FJ-I2).

## [1.10.3] - 2026-11-04

Last follow-ups of audit Appendix EF2 (Info). No API shape or schema change.

### Changed

- Notes: the bidi marks U+061C (Arabic letter mark), U+200E (LRM) and U+200F (RLM) are removed too, so every
  bidirectional control is stripped (EF2-I7).
- `TimerService::start()` / `startAt()` clean a `note` in the metadata a host passes directly, like the client API does;
  a note that is not a string or is blank after cleaning is dropped (EF2-I9).

### Documentation

- CLIENT-API: once a timer's note has reached 500 code points, later `keep` and stop / switch notes are dropped and the
  earlier text wins (EF2-I8).

## [1.10.2] - 2026-11-04

Follow-ups of audit Appendix EF2 (Info only). No API shape or schema change.

### Changed

- **One sanitiser and one merge rule for every client note** (EF2-I4): `timer/start` (also a string `metadata.note`
  when free metadata is allowed), `timer/stop`, `timer/switch`, `timer/discard-idle` `keep` and `reassign` all go
  through `Support\NoteSanitizer`. Each run of control characters becomes one space, the text is trimmed, and the cap
  is 500 code points everywhere (`keep` still answers `400` above it; the other notes are cut, as before).
- **A stop / switch note no longer replaces the notes already on the timer**: it is appended after them on a new line
  (start note, keep notes, then the stop note), cut at 500 code points with the earlier text first.
- **Split and reassign `then: continue` no longer copy the note** to the follow-up timer, so it is not repeated on the
  next entry.
- **Invisible characters** (EF2-I2): bidirectional controls U+202A–U+202E and U+2066–U+2069, U+200B and U+FEFF are
  removed from notes; U+200C / U+200D / U+2060 are kept inside the text (emoji and some scripts need them) and trimmed
  at both ends.
- **CSV export** (EF2-I3): the formula guard looks at the first visible character, after leading spaces and invisible
  format characters (U+200B, U+FEFF, …), and also catches the full-width `＝ ＋ － ＠`.

### Documentation

- `IdleResolvedEvent::getNote()` is raw user text: listeners must escape it (EF2-I5).
- Two keeps racing on the same timer can lose one note, and a keep that loses to a concurrent stop answers `200`
  although its note is not recorded (EF2-I1, documented, not changed).

## [1.10.1] - 2026-11-04

Client API contract v0.3.1 (additive; no schema change). See [UPGRADING](UPGRADING.md#to-1101).

### Added

- `POST timer/discard-idle` action `keep` accepts an optional `note` (plain text, control characters replaced by a space,
  trimmed, at most 500 characters, otherwise `400 invalid_request`). It is added to the running timer's
  `metadata.note` (appended on a new line when a note exists, combined note cut at 500 characters, earlier text first),
  so it ends up on the entry at stop. `IdleResolvedEvent::getNote()` carries it (also the reassign note); the
  `timer_idle_keep` log record carries `note_length` only. `ActiveTimer::appendNote()`, `TimerService::NOTE_MAX_LENGTH`.
- `GET me`: `features.idle_keep_note: true` and `limits.idle_check.user_can_disable`.
- `IdleCheckSettings::$userCanDisable` (fourth constructor argument, default `false`), config
  `client_api.idle_check.user_can_disable` (default `false`) and `ClientApiSettings` / `ConfigClientApiSettingsProvider`
  argument `idleCheckUserCanDisable`. A provider that returns `null` or throws falls back to the instance value.

## [1.10.0] - 2026-11-04

Client API contract v0.3 for the desktop app and the browser extension (additive; no schema
change). See [UPGRADING](UPGRADING.md#to-1100).

### Added

- Task details: `TaskReference` gets optional `projectId`, `projectTitle` and `url` (absolute https URL of the task page
  in the host). `GET tasks` returns them; every `timer` object (`GET timer`, `timer/start`, `timer/switch`,
  `timer/discard-idle`, `heartbeat`) also gets `boardTitle`, `projectId`, `projectTitle` and `url`, resolved live through
  the task provider for the token user (`null` when the task is gone, no longer visible, or the provider fails). A URL
  is published only when it is https, without credentials, on the expected origin (`client_api.task_url.origin`, else
  the request origin). `StubTaskProvider` fills a demo project.
- `POST timer/start` accepts `startedAt` (RFC 3339) for offline-started timers: clamped to now (beyond
  `client_api.backdate.clock_skew_seconds` reported as `future`), to the end of the user's latest entry (`overlap`) and
  to `client_api.backdate.max_seconds` back (default and maximum 24 h, `max_backdate`), then reported as
  `startedAtResult {requested, applied, clamped, reason}`. Malformed values answer `400 invalid_request`.
- `POST timer/stop` accepts `endedAt`, clamped to now (`future`) and to the timer start (`before_start`), reported as
  `endedAtResult`.
- `POST timer/discard-idle` action `reassign` with `taskId`, `then` (`continue` | `switch` | `stop`, default
  `continue`), optional `note` and `source`: an entry up to the idle start, an entry on `taskId` for the idle span (same
  unit of work, CL-05), then the follow-up timer. New response key `reassigned`. The target task is checked like
  `timer/start`; an unknown and a foreign task answer the same `403 task_forbidden`. `client_api.idle.allow_reassign`
  turns it off.
- `GET me` returns `features` (object of booleans: `task_details`, `start_backdate`, `stop_backdate`, `idle_reassign`,
  `idle_check`; `workday` and `monitoring` reserved, always `false`) and `limits` (`backdate.max_seconds`,
  `backdate.clock_skew_seconds`, `idle_check.threshold_minutes`, `idle_check.answer_timeout_minutes`).
- Idle-check settings for the clients: `client_api.idle_check.enabled` (default `true`), `threshold_minutes` (5–60,
  default 10), `answer_timeout_minutes` (1–30, default 5), and an optional per-user host service
  `IdleCheckSettingsProviderInterface::forUser()` (`client_api.idle_check.provider`; values clamped, exceptions fall back
  to the instance settings). The server only receives the user's decision; no activity counter is sent or stored.
- `ClientApiSettings` / `ConfigClientApiSettingsProvider`: appended optional arguments for the backdate, reassign and
  idle-check values, so a settings provider can override them per instance (clamped into the same ranges).
- Events: `TimerStartEvent::getStartTime()` and `TimerStopEvent::getEndTime()` (`?ClampedTime`); `TimerStopEvent` is
  also dispatched for the reassigned entry (TaskBoardBundle's time aggregator counts it); new `IdleResolvedEvent`
  (`nowo_time_track.idle.resolved`) for every applied idle decision.
- `LatestTimeEntryRepositoryInterface::findLatestEndedAt()` (implemented by the Doctrine store); `TimerService::startAt()`,
  `stopAt()`, `findTask()`; `IdleResolution::Reassign`, `IdleFollowUp`, `TaskNotTrackableException`.

### Changed

- Audit Appendix EF (Info): task URLs with Unicode spaces or control characters are dropped (EF-I2);
  `client_api.task_url.origin` must be a valid host with a port of 1–65535 and no trailing dot (EF-I3); client
  timestamps accept a lower-case `z` and are no longer trimmed (EF-I4); idle spans are compared on whole seconds, so a
  span starting within the timer's first second writes no 0-second entry (EF-I5). EF-I1 (`Host` header and the
  request-origin fallback) and EF-I6 (fallback overlap lookup on custom stores) are documented in UPGRADING.
- The new `startedAt` / `endedAt` / reassign `from` / `to` inputs get the 1.9.2 EF-M1 handling: they are converted to
  the server time zone before they are compared or stored (also in `TimerService` for direct callers), and offsets
  outside `-12:00`..`+14:00` answer `400 invalid_request`.

## [1.9.2] - 2026-11-04

Security fix, no API or schema change. See [UPGRADING](UPGRADING.md#to-192).

### Security

- **EF-M1 (Medium): client timestamps with a UTC offset were stored shifted by that offset.** `timer/discard-idle`
  kept the offset of `from` / `to`; Doctrine's `datetime_immutable` stores the wall time without the offset and reads
  it back in the server time zone. A user could inflate (or reverse) their own recorded time: with `to` at `-11:00`
  an idle `split` continued the timer 11 h earlier, and the next stop recorded 11 h too much. `from` / `to` are now
  converted to the server time zone, and offsets outside `-12:00`..`+14:00` answer `400 invalid_request`. The
  `from` / `to` query parameters of `GET entries`, `GET export` and the reports page are converted the same way.
  Honest clients sending `Z` were only affected on servers whose PHP time zone is not UTC.

## [1.9.1] - 2026-11-02

Clients: GET me returns minSupportedVersion for the token's client type (new ClientMinSupportedVersionProviderInterface, config client_distribution.min_supported_version.*). Fixed: concurrent stop / switch / idle split record one entry (CL-05); idle split raced by a start answers 409. See [UPGRADING](https://github.com/nowo-tech/TimeTrackBundle/blob/v1.9.1/docs/UPGRADING.md#to-191).

### Added

- `GET /api/time-track/me` returns `minSupportedVersion` for the calling token's client type when the host sets one
  (key absent otherwise). New `ClientMinSupportedVersionProviderInterface::getMinSupportedVersion(ClientType)`;
  default `ConfigClientMinSupportedVersionProvider` reads `client_distribution.min_supported_version.extension|desktop|web`
  (semver-validated) or a host service via `client_distribution.min_supported_version.provider`.
- `ConditionalActiveTimerRepositoryInterface::removeIfPresent()` (implemented by the Doctrine store).

### Fixed

- Concurrent `timer/stop` (or `timer/switch`, idle split) calls no longer record the same time entry twice (CL-05,
  Low, pre-existing in 1.x): the timer row is removed with a conditional delete and the entry is saved only by the call
  whose delete affected one row, in one transaction (MySQL, PostgreSQL, SQLite). A losing `timer/stop` answers
  `404 no_active_timer`. A `timer/switch` that loses its stop to a concurrent `timer/stop` records no entry and then
  starts the new task (`201`); one that loses to a concurrent switch gets `409 timer_running` from the start. Either
  way the data stays consistent: one entry, at most one timer. Host stores that implement only
  `ActiveTimerRepositoryInterface` keep the 1.x race (see UPGRADING).
- An idle split (`timer/discard-idle` `split`) raced by a concurrent `timer/start` answers `409 timer_running` instead
  of a `500` on the unique user index; the idle entry is recorded once and the other request's timer is kept.
- `minSupportedVersion` values from a host provider that are not semver are omitted from `GET me`.

## [1.9.0] - 2026-11-02

Client API for the browser extension and desktop app: scoped tokens linked to host credentials, device flow, per-request credential and task checks, stateless Bearer endpoints with exact-origin CORS, rate limits per token / IP (/64) / username, timer switch / discard-idle / export, audit events. See [UPGRADING](https://github.com/nowo-tech/TimeTrackBundle/blob/v1.9.0/docs/UPGRADING.md#to-190).

Client API 1.9 (contract v0.2) for the extension and the desktop app. Schema change: see
[UPGRADING](UPGRADING.md#to-190).

### Added

- Session tokens linked to a host credential: `ClientToken::credentialId`, `scopes`, `getIssuedAt()`;
  `ClientAuthResult::success($user, ?$credentialId, $scopes)` (new arguments optional).
- Scopes `timer:read`, `timer:write`, `tasks:read`, `entries:read` (`Enum\ClientScope`), checked per endpoint
  (`403 insufficient_scope`).
- Host interfaces: `ClientCredentialStateInterface::isActive(ClientToken)` (per request),
  `ClientCredentialIssuerInterface::issueForDevice()`, `TaskAccessCheckerInterface::canTrack()` (per start / switch, deny
  by default, `403 task_forbidden` for missing and foreign tasks), `ClientUserProfileProviderInterface`,
  `ClientApiSettingsProviderInterface`, `ClientDistributionProviderInterface` — each with a config default
  (`client_api.*`, `client_distribution.*`).
- `ClientTokenRevoker::revokeByCredential()` (public service) and `CredentialClientTokenRepositoryInterface`.
- Endpoints `timer/switch`, `timer/discard-idle` (keep / discard / split), `export` (CSV / JSON, own data), and the
  device flow `device/code`, `device/token` (opt-in `client_api.device_flow.enabled`) with `DeviceAuthorizationService`,
  hashed single-use codes (`DeviceAuthorization`, table `{prefix}_device_codes`), and an optional default verification
  page (`routes.device_verify`, UiKit form / show scaffolds, CSRF bound to action + request).
- Per-IP and per-token rate limits (`client_api.rate_limit.*`, host cache pool), `429 rate_limited` + `Retry-After`.
- Audit event `nowo_time_track.client_api` (`ClientApiEvent`: user id, credential id, client type, IP /24 or /64, reason;
  never the token), denials throttled per credential.
- `GET me` adds `displayName`, `scopes`, `workingHours`; `login` adds `scopes`; `entries` adds `limit` (≤ 100) / `offset`.
- Downloads partial `@NowoTimeTrackBundle/client_distribution/_downloads.html.twig` and Twig functions
  `nowo_time_track_client_distribution()`, `nowo_time_track_client_download_items()`, `nowo_time_track_label()`.
- Docs: [CLIENT-API](CLIENT-API.md) reference, host integration in USAGE, API threat model in SECURITY. Spec
  `specs/002-client-api`. Translations (7 locales) for the device page and the downloads partial.

### Changed

- Client API errors are `{error: code, message}`; stateless on every answer (kernel test asserts no `Set-Cookie` and no
  session start, also on `401` / `429`).
- CORS: exact origins only (`client_api.allowed_origins` + `clients.cors_allowed_origins`); the 1.x `tauri://` prefix
  rule became the exact Tauri webview origins.
- `GET tasks` `limit` capped at 50; `GET entries` returns own entries unless the deprecated `userId` is given.
- `PurgeExpiredClientTokensCommand` also purges expired device requests.

### Deprecated

- `POST heartbeat` (`isIdle` ignored, `Deprecation: true` header), `entries?userId=`,
  `clients.cors_allow_any_extension_origin`, `*` origins, `client_api.allow_tauri_webview_origins` — removed in 2.0.

### Security

- Free client `metadata` on `timer/start` rejected by default (`client_api.allow_metadata`), no server-side idle signal.
- Export CSV neutralises spreadsheet formulas.
- Security audit (Appendix CL) fixes before the release:
  - Scopes fail closed: only an absent list grants every scope. `device/code` answers `400 invalid_scope` and
    `ClientAuthResult::success()` throws `InvalidArgumentException` for an empty list or an unknown scope
    (`ClientScope::fromRequested()`); stored lists are never widened. Tokens without stored scopes (before 1.9) keep
    every scope.
  - Every per-IP limit (API, device flow, client login) keys an IPv6 client by its /64 (`IpPrefix::rateLimitKey()`);
    IPv4-mapped IPv6 addresses (`::ffff:a.b.c.d`) count as their IPv4 address.
  - `device/code` capped per IPv4 /24 or IPv6 /64 (`client_api.device_flow.max_pending_per_prefix`, default 20) and in
    total (`client_api.device_flow.max_pending`, default 10 000) → `429`; `DeviceFlowCapacityExceededException`,
    `DeviceAuthorizationRepositoryInterface::countUnexpired()`.
  - Client login throttled per username across addresses (`clients.login_rate_limit.max_attempts_per_username`,
    default 5 × `max_attempts`); usernames are NFKC-normalised when ext-intl is available.
  - The default verification page sends `X-Frame-Options: DENY`, CSP `frame-ancestors 'none'` and
    `Cache-Control: no-store`, and shows the request time and the requesting network
    (`DeviceAuthorization::getIpPrefix()`, column `ip_prefix`).

## [1.8.0] - 2026-11-02

REQ-100 release: timer and reports pages on the UiKit page contract (layout_template / web_ui.templates.*, 1.x layouts wrapped with a deprecation), PSR clock and logger, paginated reports, 100% coverage without exclusions, demo e2e. Requires nowo-tech/ui-kit-bundle ^2.20. See [UPGRADING](https://github.com/nowo-tech/TimeTrackBundle/blob/v1.8.0/docs/UPGRADING.md#to-180).

### Added

- Reports list pagination (REQ-PERF-001): `nowo_time_track.reports.page_size` (default 50, 1–500), `page` query
  parameter, `TimerService::listEntriesPage()` returning `Dto\TimeEntryPage`; the Doctrine repository pages in the
  database through the new optional `Repository\PaginatedTimeEntryRepositoryInterface`. The reports template receives
  `entry_page`; the page renders the UiKit pagination.
- PSR-3 logging (REQ-OBS-001) on the Monolog channel `time_track`: timer start / stop, client login success / failure /
  throttling, token revocation and the expired-token purge. Records carry the action and the client type only — no
  user, task, duration, token or IP (time entries are personal data). See [SECURITY](SECURITY.md#logging).
- UiKit page contract (REQ-UI-003): `Nowo\TimeTrackBundle\Ui\TimeTrackPages` builds the UiKit view models
  (`ListPage`, `PageHeader`, `Action`, `Pagination`, `EmptyState`) of the timer and reports pages; both templates
  extend `@NowoUiKit/page/list.html.twig` and render inside `nowo_ui_kit.layouts.admin`. New options
  `web_ui.layout_template` (null = UiKit admin layout) and `web_ui.templates.index|reports`. Page contract in
  [TEMPLATES](TEMPLATES.md), enforced by `tests/Integration/Contract/PageContractTest.php` (UiKit
  `PageContractTestCase`, strict missing-translation rule).
- Demo (REQ-DEMO-013): Playwright e2e (`make -C demo/symfony8 test-e2e`: timer page, start / stop, reports) and README
  screenshots `docs/images/demo/timer.webp`, `reports.webp` (`make -C demo/symfony8 demo-screenshots`). The demo
  pages render inside a one-file UiKit bridge (`templates/layouts/admin.html.twig`); the demo is bound to 127.0.0.1;
  demo time entries (`TimeTrackFixtures`) and a migration for the TaskBoardBundle 1.20 schema (demo lock: UiKit 2.21,
  TaskBoard 1.20, FormKit 2.17).
- `make assets` (no-op) and `make validate-phpdoc`; demo targets `restart`, `ensure-up`, `setup`, `test-coverage`,
  `verify`, `release-check` (runs `update-bundle` before `verify`).

### Changed

- **Manage UI markup (REQ-UI-001 / REQ-UI-003):** the timer and reports pages no longer ship local `card` / `btn` /
  `flash` markup or inline styles: page header, actions, table, empty state, flashes and pagination come from the UiKit
  scaffold and theme slots (`nowo_ui_kit.css_framework`). The running timer is shown in the page header (intro
  `Running: %task% (since %started%)`, status badge, Stop action); Start is a row action; durations read
  `%minutes% min`; the
  entry source is translated. Requires `nowo-tech/ui-kit-bundle` `^2.20` (was `^1.4 || ^2.2`).
- Translation keys added: `timer.running_intro`, `timer.status_running`, `tasks.task`, `tasks.board`, `tasks.actions`,
  `reports.period`, `reports.minutes`, `reports.source_web|extension|desktop` (7 locales).

### Deprecated

- `nowo_time_track.templates.layout`, `.css_framework`, `.index`, `.reports` (removed in 2.0): use
  `web_ui.layout_template`, `nowo_ui_kit.css_framework`, `web_ui.templates.index|reports`. They keep working (mapped
  onto `web_ui.*`); a 1.x layout without the UiKit canonical blocks is wrapped in the deprecated
  `_legacy_layout.html.twig` bridge, and `layout.html.twig` is now a shim that renders 1.x templates extending
  `nowo_time_track_layout` inside the UiKit admin layout. See [UPGRADING](UPGRADING.md#unreleased).

- Time comes from an injected `Psr\Clock\ClockInterface` (REQ-DI-001) in `TimerService`, `ClientAuthService`, the
  client API and manage controllers and `DoctrineOrmClientTokenRepository` (optional trailing constructor arguments,
  default: the system clock). Entities accept an optional time argument (`ActiveTimer::__construct(..., $startedAt)`,
  `heartbeat(..., $at)`, `TimeEntry` / `ClientToken` `$createdAt`, `ClientToken::isExpired($now)` / `touch($at)`).
- New runtime requirements: `psr/clock`, `psr/log`, `symfony/clock` (already installed by FrameworkBundle).
- The demo `make up` ends with the canonical `Demo started at: http://localhost:<PORT>` line (the timer page URL is
  printed on the line before it).

### Tests

- REQ-TEST-003: the `src/Controller`, `src/Repository` and `src/Doctrine` coverage exclusions are gone. A functional
  kernel (`tests/Support/FunctionalKernel.php`: Doctrine on in-memory SQLite, SecurityBundle entity provider, FormKit,
  UiKit, the route loader) covers the client API end to end (preflight / CORS, Bearer auth, login validation and
  throttling, timer lifecycle, 409 conflict, entries ACL, logout), the manage UI (start / stop forms, flashes, CSRF,
  access contract, `allow_unauthenticated`, paginated reports) and the Doctrine repositories / metadata listener.
  Only pure interfaces stay excluded.

### Documentation

- USAGE: overridable template subpaths, override procedure and freeze rule (REQ-TWIG-001); translation override
  procedure (REQ-I18N-001). CONFIGURATION: routes and the documented route-loader exemption (REQ-SF-004), reports
  pagination. SECURITY: secrets & cryptography, logging, dependency and updates. DEMO-FRANKENPHP: production start and
  troubleshooting. PHPDoc on every public class and method (REQ-CS-001).

- [ROADMAP](ROADMAP.md): planned browser extension (MV3) and Tauri desktop app with a scoped token API, compliant by
  design with RGPD / LOPDGDD (working-time records, not monitoring); target 1.8 / 2.0.

## [1.7.0] - 2026-10-30

The manage UI form is a FormKit Type (REQ-TWIG-005 rule 11). See [UPGRADING](UPGRADING.md#to-170).

### Changed

- **FormKit is a required dependency** (`nowo-tech/form-kit-bundle` `^2.14`). `TimerActionType` carries
  `#[FormKitConfig('time_track')]` + `FormOptionsTrait` and adds its fields through FormKit's option merger.
- **FormKit profile `nowo_form_kit.profiles.time_track`** (REQ-UI-003 "Form theme" row), prepended by the extension when
  FormKitBundle is registered and the host does not define it: translation domain `NowoTimeTrackBundle`, no classes, no
  convention label / placeholder / help keys — the form renders exactly as in 1.6 (same flat `_token` / `action` /
  `task_id` names and ids). When the host has no `nowo_form_kit` config at all, FormKit's implicit `default` profile
  stays the default one. Without FormKitBundle in the kernel the form keeps its explicit options.

## [1.6.0] - 2026-10-26

Forms in the bundle views are Symfony Form Types rendered with form_start / child loop / form_end, no raw form controls (REQ-TWIG-005). See [UPGRADING](UPGRADING.md#to-160).

### Added

- `make check-no-raw-html-form` (`.scripts/check-no-raw-html-form.sh`) fails on raw `<form>` / `<input>` / `<textarea>` / `<select>` in bundle views and demo templates, and runs in `release-check` (REQ-TWIG-005).

### Changed

- Manage timer page: start/stop buttons are rendered through the new `TimerActionType` Symfony Form (`form_start` / child loop / `form_end`) instead of a raw `<form>` with hand-rolled `_token` / `action` / `task_id` inputs; the controller validates with `handleRequest` + `isSubmitted()` / `isValid()` and always passes `stop_form` / `start_forms`. Same URL, field names and CSRF id (REQ-TWIG-005). Requires `symfony/form`.

- Developer tooling: `make release-check` runs the full REQ-MAKE-002 chain with `ensure-up` first, then the Cursor co-author, open-PR and Twig `extra` gates, composer-sync, the new `cs-fix` step, cs-check, rector-dry, phpstan, igor, translations, coverage-check and the demo release-check.
- Dependencies refreshed (Composer and pnpm/npm lock files of the bundle and its demos, campaign 2026-10-19).
- `.php-cs-fixer.dist.php`: `new ParallelConfig(2)` (REQ-CS-001).
- Documentation: wave-1 REQ compliance — README documentation list order (extra docs under Additional documentation), CONTRIBUTING Cursor Agent settings and post-release-commit trailer check, canonical issue templates, `demo/README.md`, demo `.env.test` / `.gitignore` cleanup, SPEC-DRIVEN-DEVELOPMENT relationship to Engram, doc TOCs, Spec Kit inventory refresh (60/60).
- Internal: applied the pending Rector suggestions (`DefaultClientAuthenticator` private static helper made non-static, `RecoveringFlusher` null check as `instanceof`); no behaviour change. `make rector-dry` is clean again.

## [1.5.2] - 2026-10-18

Allows nowo-tech/ui-kit-bundle 2.x (^1.4 || ^2.2) so hosts can move to UiKit 2.2. See [UPGRADING](UPGRADING.md#to-152).

### Changed

- Allows nowo-tech/ui-kit-bundle 2.x (`^1.4 || ^2.2`); the dev lock uses 2.2.0. Templates are unchanged (compatibility release). The demo lock stays on UiKit 1.x until a TaskBoardBundle release that allows UiKit 2.x is published (the demo requires it). With UiKit 2.x `nowo-ui.css` ships in `@layer nowo-ui`, so unlayered host/framework CSS wins equal-specificity conflicts with kit rules; to keep the 1.x cascade, override the page `stylesheets` blocks and link `css/nowo-ui.unlayered.css` (UiKit 2.x only).

## [1.5.1] - 2026-10-13

### Changed

- `services.yaml` no longer scans `Bridge/`: `StubTaskProvider` and `NullTeamContextProvider` are registered only by `TimeTrackExtension` when selected as `task_provider` / `team_context_provider`, instead of having the explicit definitions overwritten by the resource scan. With custom providers configured, the unused Bridge defaults are no longer registered as (removed) private services. A DI test asserts the final definitions and aliases.

## [1.5.0] - 2026-10-13

### Security

- Client login throttle: new IP-only bucket (`clients.login_rate_limit.max_attempts_per_ip`, default 5 × `max_attempts`, like Symfony's `DefaultLoginRateLimiter`) blocks an IP that rotates usernames; a successful login no longer clears that budget. Usernames are trimmed + lower-cased. Both counters are read with one `getItems()` and committed together (PSR-6 has no atomic increment; documented).
- Client API CORS: `Access-Control-Allow-Credentials: true` is no longer sent (the API uses Bearer tokens, not cookies), and arbitrary `chrome-extension://` / `moz-extension://` origins are no longer allowed by default. List your extension in `clients.cors_allowed_origins` or opt in with `clients.cors_allow_any_extension_origin: true`. `tauri://` stays allowed.

### Deprecated

- `security.manager_can_edit_entries`: the bundle ships no time-entry edit route, so the option only affects `TeamAccessGuard::canEditEntry()` called from host code. Setting it triggers a deprecation; use a `nowo_time_track.time_entry.access_check` listener.

### Fixed

- 100% coverage gate: `TimeTrackExtension::prepend()` UiKit defaults are now tested (unreachable `is_array()` guard removed).

## [1.4.0] - 2026-10-13

### Security

- Manage `reports` page: invalid `from` / `to` query values no longer reach the lenient `DateTimeImmutable` parser (HTTP 500); only ATOM or `Y-m-d` are accepted, anything else falls back to the defaults.
- Client API: `tasks` `limit` is clamped to 1–100 (`TaskListQuery::MAX_LIMIT`, new `TaskListQuery::bounded()`), and the `from`/`to` span of `entries` and `reports` is capped at 366 days.
- `DefaultClientAuthenticator` verifies a dummy bcrypt hash when the username is unknown, so login response time no longer reveals whether an account exists.

### Fixed

- Two concurrent timer starts for the same user now return `ActiveTimerConflictException` (HTTP 409 in the client API) instead of a unique-constraint error (new `ActiveTimerConflictException::concurrentStart()`).
- Null bytes in `from` / `to` no longer raise a `ValueError` in the client `entries` API.
- Manage UI flash messages are translated (`flash.*` keys in the `NowoTimeTrackBundle` domain, 7 locales).

## [1.3.7] - 2026-10-13

### Changed

- Development: `composer.json` pins `config.platform.php` to 8.2.0 so the committed lock stays installable on the minimum PHP; CI overrides the platform per matrix cell.
- Repository: `.twig-cs-fixer.cache` is now git-ignored.

### Dependencies

- `nowo-tech/ui-kit-bundle` 1.9.2 → 1.11.0 (development lock; #39).
- `doctrine/orm` 3.7.4 → 3.7.5 (development lock; #40).

## [1.3.6] - 2026-10-09

### Dependencies

- Dependabot: `doctrine/orm` 3.7.3, `nowo-tech/ui-kit-bundle` 1.8.5, `igor-php/igor-php` `^0.10.0` (dev), `friendsofphp/php-cs-fixer` 3.95.27, phpstan group, `nowo-tech/phpstan-frankenphp`.
- Composer refresh: `nowo-tech/ui-kit-bundle` 1.9.2, `doctrine/orm` 3.7.4, Symfony 7.4.20 components (lockfile); dev tooling `phpstan/phpstan` 2.3.1, `phpstan/phpstan-symfony` 2.1.0, `rector/rector` 2.7.0.
- Demo: Symfony 8.1.8, `doctrine/orm` 3.7.4, `doctrine/dbal` 4.5.0, `nowo-tech/task-board-bundle` 1.5.8, `twig/twig` 3.30.0; regenerated `config/reference.php`.

## [1.3.5] - 2026-09-28

### Security

- Empty `security.access_roles` is fail-closed (deny) unless `allow_unauthenticated` or a custom `access_checker` is set.

### Changed

- **Doctrine ORM SortDirection:** replace string `'ASC'`/`'DESC'` in `#[ORM\OrderBy]` and QueryBuilder `orderBy`/`addOrderBy` with `SortDirection::Ascending`/`Descending` (doctrine/orm deprecation, https://github.com/doctrine/orm/issues/11313); require `doctrine/orm` `^3.7` where applicable.

## [1.3.4] - 2026-09-27

### Added

- **REQ-CS-008:** `igor-php/igor-php` (require-dev only), root `igor.json`, Composer/`Makefile` `igor` target, and `release-check` wiring for FrankenPHP worker-state audit.

### Changed

- **Worker safety (Igor):** justified `// @igor-ignore` annotations and/or `ResetInterface` / request-scoped fixes so `make igor` passes on package `src/`.

### Fixed

- **FrankenPHP worker mode (no kernel reset):** `TeamAccessGuard` refreshes the user from the database before checking `admin_roles`, and `ClientAuthService::resolveUser()` refreshes the token owner, so a role revoked in another worker is honoured on the next request even when the identity map was not cleared (`Doctrine\ManagedEntityRefresher`).
- Repositories reset a closed EntityManager through `ManagerRegistry` when `flush()` fails (e.g. two concurrent `timer/start` calls hitting the unique active-timer index) before rethrowing, so the worker does not stay on a closed manager (`Doctrine\RecoveringFlusher`).
- `TimeTrackRouteLoader` can be loaded more than once per process (removed the "routes already loaded" guard).
- **Docs:** `docs/FRANKENPHP-WORKER-AUDIT.md` updated with the remediation.

### Notes

- New optional `?ManagerRegistry` constructor argument on `TeamAccessGuard`, `ClientAuthService` and the three Doctrine repositories; wired automatically. No configuration changes.

[1.3.5]: https://github.com/nowo-tech/TimeTrackBundle/releases/tag/v1.3.5
[1.3.4]: https://github.com/nowo-tech/TimeTrackBundle/releases/tag/v1.3.4

## [1.3.3] - 2026-08-24

### Changed

- **Demos:** MySQL env policy in FrankenPHP stack (REQ-DEMO-011).
- **QA:** add `phpstan-frankenphp` extension (REQ-CS-005).
- **Docs:** PHP-FIG PSR evaluation (REQ-CS-007).

### Notes

- **No API or configuration changes** for integrators unless noted above.

[1.3.3]: https://github.com/nowo-tech/TimeTrackBundle/releases/tag/v1.3.3

## [1.3.2] - 2026-08-19

### Changed

- **Maintainer:** demo reference PHP CS Fixer alignment.

[1.3.2]: https://github.com/nowo-tech/TimeTrackBundle/releases/tag/v1.3.2

## [1.3.1] - 2026-08-18

### Changed

- **Demos:** pin `nowo-tech/hot-reload-bundle` to `^1.4` with FrankenPHP Mercure/`hot_reload` (`dev`/`test` only).

[1.3.1]: https://github.com/nowo-tech/TimeTrackBundle/releases/tag/v1.3.1

## [1.3.0] - 2026-08-04

### Added
- **REQ-TWIG-004:** require `twig/extra-bundle` + `twig/string-extra`; `make check-twig-extra` in `release-check`; demos register `TwigExtraBundle`.
- **Twig-CS-Fixer:** `vincentlanglet/twig-cs-fixer`, `.twig-cs-fixer.php`, `composer twig:lint` / `twig:fix`.

### Changed

- **REQ-UI-001-kit:** Requires `nowo-tech/ui-kit-bundle` `^1.4`. Layout/pages load `asset('css/nowo-ui.css', 'nowo_ui_kit')` and import `@NowoUiKitBundle/macros/ui.html.twig`. Extension seeds `nowo_ui_kit` from `templates.css_framework` when the host has not configured UiKit. Demo registers `NowoUiKitBundle` + `nowo_ui_kit.yaml`.

[1.3.0]: https://github.com/nowo-tech/TimeTrackBundle/releases/tag/v1.3.0

## [1.2.0] - 2026-08-03

### Added

- **REQ-UI-002** — `security.access_checker`, `security.allow_unauthenticated` (default `false`), and `TimeTrackAccessCheckerInterface` (`ConfigurableTimeTrackAccessChecker` / `AllowAllTimeTrackAccessChecker`).
- Compile-time guard: when `allow_unauthenticated` is `false`, `symfony/security-bundle` is required (`LogicException` otherwise).
- Manage controller enforces the access checker instead of a hard-coded `#[IsGranted('ROLE_USER')]`.

### Changed

- Demo Composer: TaskBoard / TipTap / TagInput / TwigInspector from Packagist (no sibling path mounts).
- Docs: CONFIGURATION / SECURITY document the dual-layer manage ACL.
- Dev deps: `friendsofphp/php-cs-fixer` 3.95.18, `rector/rector` 2.5.9.

### Compatibility

- PHP `>=8.2`, `<8.6`; Symfony `^7.4 || ^8.0`.
- Manage UI with default security settings requires **SecurityBundle** (or set `allow_unauthenticated: true` for trusted local demos).

## [1.1.0] - 2026-07-30

### Added

- **REQ-UI-001 `templates.css_framework`** — default `tabler`; Twig global `nowo_time_track_css_framework`. Validated enum (`bootstrap` / `bootstrap4` / `bootstrap5` / `tabler` / `tailwind` / `foundation` / `custom` / `none`). Documented in CONFIGURATION.md / USAGE.md / UPGRADING.md.
- **REQ-UI-001 layout wiring** — `templates.layout` → Twig global `nowo_time_track_layout` via `TimeTrackTwigExtension`; pages `{% extends %}` config and stack assets with `{{ parent() }}`.

### Changed

- Dev / CI dependency bumps (Dependabot): php-cs-fixer, Rector, PHPStan group, `nowo-tech/phpstan-frankenphp`, `actions/checkout` v7, `actions/stale` v11.
- Demo Symfony 8 `reference.php` refresh for new config keys.

## [1.0.6] - 2026-07-29

### Security

- Manage UI POST start/stop require CSRF (`nowo_time_track_manage`); index route accepts GET+POST.
- `docs/SECURITY.md` — 12.4.1 checklist + SEC-004 row; CSRF control documented.

### Added

- `make check-open-prs`, `coverage-check`, `demo-smoke`, `validate-translations`, `down-dev`.
- `SYMFONY_DEPRECATIONS_HELPER=max[direct]=0` (REQ-SF-005).
- FrankenPHP Friendly banner + `docs/DEMO-FRANKENPHP.md` (REQ-DOCS-017 / DEMO-002).
- Spec inventory locales de/fr/it/nl/pt; PHPStan `ignoreErrors: []` (baseline removed).
- **REQ-CS-005:** `nowo-tech/phpstan-frankenphp` in `require-dev` with classic + worker rulesets.
- GitHub automation: `dependabot.yml`, `pr-lint.yml`, `stale.yml`, `copilot-instructions.md`.
- `ClientAuthResult::requireUser()` for typed success path.

### Changed

- Demo image `frankenphp:1-php8.5`; CI matrix Symfony **7.4 / 8.0 / 8.1** (dropped 7.0).

## [1.0.5] - 2026-07-16

### Added

- **Translations** — UI locales **de**, **fr**, **it**, **nl**, **pt** (in addition to **en** / **es**).
- **CODE_OF_CONDUCT.md** — Contributor Covenant; linked from README and CONTRIBUTING.
- **docs/GITHUB_CI.md** — GitHub Actions CI requirements, including REQ-GIT-001 (no Cursor co-author trailers).
- **Git hygiene** — `.githooks/commit-msg`, `.scripts/check-no-cursor-coauthor.sh`, `.scripts/strip-cursor-coauthor-from-history.sh`, Cursor rule `01-git-commits.mdc`, and CI job `git-hygiene`.
- **Makefile** — `check-no-cursor-coauthor`, `setup-hooks`, `strip-cursor-coauthor-from-history`; `release-check` runs the co-author check first.

### Changed

- **docs/CONTRIBUTING.md** — Code of Conduct and git-hook setup for REQ-GIT-001.
- **docs/RELEASE.md** — Reminder to re-run `make check-no-cursor-coauthor` before pushing the release commit/tag.
- **es** translations — nav/page strings fully localized.

## [1.0.4] - 2026-07-13

### Added

- **`.scripts/php-coverage-percent.sh`** — prints colored PHP line-coverage percentage after `make test-coverage` (REQ-TEST-008).
- **`.cursor/rules/`** — Cursor agent rules for PHP/Symfony bundle, tests, docs, and release workflow.
- **`.cursorignore`** — reduces indexer noise (vendor, caches, locks, secrets).

### Changed

- **Makefile** — `test-coverage` pipes PHPUnit output to `coverage-php.txt` and runs the coverage-percent script.
- **docs/SECURITY.md** — scope, attack surface, threat model, release security checklist (12.4.1), and private reporting contact.
- **`.gitignore`** — ignores `.cursor/sandbox.json` and `/coverage-php.txt`.
- **composer.lock** / **demo/symfony8/composer.lock** — PHP CS Fixer 3.95.13.

### Fixed

- **demo/symfony8** — `config/reference.php` keeps `declare(strict_types=1)` (post-release CS Fixer alignment).

## [1.0.3] - 2026-07-08

### Added

- **GitHub Spec Kit** scaffolding — `.specify/` templates and scripts, Cursor `/speckit-*` skills, baseline feature `specs/001-baseline/` (spec + code inventory).
- **docs/SPEC-KIT.md** — installation, folder layout, Cursor Agent usage, and maintainer checklist.

### Changed

- **docs/SPEC-DRIVEN-DEVELOPMENT.md** — expanded workflow aligned with Spec Kit and baseline traceability.
- **README** — link to [GitHub Spec Kit](docs/SPEC-KIT.md) documentation.
- **composer.lock** / **demo/symfony8/composer.lock** — PHP CS Fixer 3.95.12 and aligned PHPUnit dev dependencies.

### Documentation

- Baseline specification documents current bundle scope under `specs/001-baseline/` (repository only; excluded from integrator runtime).

## [1.0.2] - 2026-07-07

### Changed

- **demo/symfony8** — `config/reference.php` includes `declare(strict_types=1)` (demo only; no Composer package API change).

## [1.0.1] - 2026-07-06

### Fixed

- **CI** — Code Style job scopes auto-commit to CS Fixer paths (`src`, `tests`, `.php-cs-fixer.dist.php`, `demo`) and skips empty commits when only transient files (e.g. `composer.json`) are dirty.
- **demo/Makefile** — `release-check` reads `PORT` from `.env` (falls back to `.env.example`) so the healthcheck matches the running container.

## [1.0.0] - 2026-07-06

### Added

- **Symfony bundle** for personal and team time tracking (PHP **8.2+**, Symfony **7.4+ / 8.0 / 8.1+**).
- **Web UI** — timer at `/tools/time-track`, reports at `/tools/time-track/reports` (EN/ES translations, Twig templates).
- **Doctrine entities** — `TimeEntry`, `ActiveTimer` (one active timer per user), `ClientToken` (hashed Bearer tokens).
- **TimerService** — start/stop/heartbeat, task listing, entry queries; **409** on active-timer conflict.
- **REST client API** — Bearer auth, CORS, login rate limiting for browser extension and desktop agent (`clients.enabled`).
- **Integration interfaces** — `TaskProviderInterface`, `TeamContextProviderInterface`.
- **Default bridges** — `StubTaskProvider` (in-memory demo tasks), `NullTeamContextProvider`.
- **TeamAccessGuard** — admin/manager ACL for viewing team entries.
- **Symfony events** — `timer.start`, `timer.stop`, `time_entry.list_query`, `time_entry.access_check`.
- **Console command** — `nowo:time-track:client-tokens:purge` (expired Bearer cleanup).
- **Symfony 8.1 Docker demo** integrated with **TaskBoardBundle** (`make -C demo up-symfony8`, port **8024**).
- **Chrome MV3 extension MVP** (`extension/chrome/`, v0.1.0) — login, task list, start/stop timer.
- **Tauri 2 desktop agent scaffold** (`desktop/`, v0.1.0) — system tray, idle heartbeat, start/stop timer.

### Configuration

- `nowo_time_track` tree: `user_class`, `table_prefix`, `route_prefix`, task/team providers, customizable routes and templates, security ACL, clients API (`token_ttl`, `idle_threshold_seconds`, CORS, rate limit).

### Testing

- **100%** PHPUnit line coverage on included bundle code (controllers, Doctrine repositories/listeners, and integration interfaces excluded by design).
- Unit tests for services, DI, routing, client auth, entities, events, and commands.

### Documentation

- Installation, configuration, usage, TaskBoard integration, browser extension, desktop agent, security, and spec-driven development guides.

[Unreleased]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.21.0...HEAD
[1.21.0]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.20.0...v1.21.0
[1.20.0]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.19.1...v1.20.0
[1.19.1]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.19.0...v1.19.1
[1.19.0]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.18.0...v1.19.0
[1.18.0]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.17.1...v1.18.0
[1.17.1]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.17.0...v1.17.1
[1.17.0]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.16.2...v1.17.0
[1.16.2]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.16.1...v1.16.2
[1.16.1]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.16.0...v1.16.1
[1.16.0]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.15.0...v1.16.0
[1.15.0]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.14.0...v1.15.0
[1.14.0]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.13.0...v1.14.0
[1.13.0]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.12.0...v1.13.0
[1.12.0]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.11.0...v1.12.0
[1.11.0]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.10.3...v1.11.0
[1.10.3]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.10.2...v1.10.3
[1.10.2]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.10.1...v1.10.2
[1.10.1]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.10.0...v1.10.1
[1.10.0]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.9.2...v1.10.0
[1.9.2]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.9.1...v1.9.2
[1.9.1]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.9.0...v1.9.1
[1.9.0]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.8.0...v1.9.0
[1.8.0]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.7.0...v1.8.0
[1.7.0]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.6.0...v1.7.0
[1.6.0]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.5.2...v1.6.0
[1.5.2]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.5.1...v1.5.2
[1.5.1]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.5.0...v1.5.1
[1.5.0]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.4.0...v1.5.0
[1.4.0]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.3.7...v1.4.0
[1.3.7]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.3.6...v1.3.7
[1.3.6]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.3.5...v1.3.6
[1.2.0]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.0.6...v1.1.0
[1.0.6]: https://github.com/nowo-tech/TimeTrackBundle/compare/v1.0.5...v1.0.6
[1.0.5]: https://github.com/nowo-tech/TimeTrackBundle/releases/tag/v1.0.5
[1.0.4]: https://github.com/nowo-tech/TimeTrackBundle/releases/tag/v1.0.4
[1.0.3]: https://github.com/nowo-tech/TimeTrackBundle/releases/tag/v1.0.3
[1.0.2]: https://github.com/nowo-tech/TimeTrackBundle/releases/tag/v1.0.2
[1.0.1]: https://github.com/nowo-tech/TimeTrackBundle/releases/tag/v1.0.1
[1.0.0]: https://github.com/nowo-tech/TimeTrackBundle/releases/tag/v1.0.0
