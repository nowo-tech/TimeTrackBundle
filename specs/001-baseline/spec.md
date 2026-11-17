# Feature Specification: TimeTrackBundle baseline (100% code coverage)

**Feature Branch**: `001-baseline`  
**Created**: 2026-07-07  
**Status**: Active  
**Input**: Backfill GitHub Spec Kit baseline documenting 100% of production code in `src/`.

**Related docs**: [`docs/SPEC-DRIVEN-DEVELOPMENT.md`](../../docs/SPEC-DRIVEN-DEVELOPMENT.md), [`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md), [`docs/USAGE.md`](../../docs/USAGE.md)  
**Code inventory (traceability)**: [`code-inventory.md`](code-inventory.md)

---

## User Scenarios & Testing

### User Story 1 — Start/stop timer (Priority: P1)

As a user, I start a timer on an allowed task and stop it to create a time entry with duration.

**Independent Test**: POST start on task → active timer row; POST stop → `TimeEntry` persisted, active timer cleared.

**Acceptance Scenarios**:

1. **Given** no active timer, **When** `TimerService::start()` with valid task, **Then** `ActiveTimer` saved and `TIMER_START` event dispatched.
2. **Given** active timer exists, **When** start requested again, **Then** `ActiveTimerConflictException` (409 on API).
3. **Given** active timer, **When** stop, **Then** `TimeEntry` created with elapsed seconds and `TIMER_STOP` event fired.

---

### User Story 2 — Web UI and reports (Priority: P1)

As a user, I use `/tools/time-track` to control timers and view personal/team reports.

**Acceptance Scenarios**:

1. **Given** authenticated user with access roles, **When** index loads, **Then** `TimeTrackManageController` shows active timer and recent entries.
2. **Given** manager with `manager_can_view_entries`, **When** reports page loads, **Then** team entries visible per `TeamAccessGuard`.

---

### User Story 3 — Client API (Priority: P1)

As a browser extension or Tauri agent, I authenticate with Bearer tokens and sync timer state via JSON API.

**Acceptance Scenarios**:

1. **Given** `clients.enabled=true`, **When** login with valid credentials, **Then** `ClientAuthService` issues token stored in `ClientToken`.
2. **Given** Bearer token, **When** API start/stop called, **Then** `TimeTrackClientApiController` delegates to `TimerService` with `ClientType` metadata.
3. **Given** expired tokens, **When** purge command runs, **Then** `PurgeExpiredClientTokensCommand` removes stale rows.

---

### User Story 4 — TaskBoard integration (Priority: P2)

As an integrator with TaskBoardBundle, I wire `TaskProviderInterface` and `TeamContextProviderInterface` so tasks and teams resolve from the board.

**Acceptance Scenarios**:

1. **Given** `task_provider: nowo_task_board.task_provider`, **When** timer starts, **Then** task title/board id come from TaskBoard bridge.
2. **Given** bundle alone, **When** no custom provider, **Then** `StubTaskProvider` and `NullTeamContextProvider` allow demo operation.

---

### Edge Cases

- User cannot track task: `TaskProviderInterface::canUserTrack` returns false → 400/403.
- Sub-request timer API: uses authenticated user from token only.
- Table prefix: `TimeTrackMetadataListener` rewrites entity table names.
- Entry list filtering: `TimeEntryListQueryEvent` allows host ACL plugins.

---

## Requirements

### Bundle & DI

- **FR-BUNDLE-001**: `TimeTrackBundle` registers extension alias `nowo_time_track` and `TwigPathsPass`.
- **FR-CFG-001**: `Configuration` MUST require `user_class`; define `table_prefix`, `task_provider`, `team_context_provider`, `route_prefix`, `database.entity_manager`, `security.*`, `routes.*`, `clients.*`.
- **FR-CFG-002**: `TimeTrackExtension` loads services and conditional client routes.
- **FR-DI-001**: `services.yaml` wires repositories, services, controllers, commands, and bridge defaults.
- **FR-TWIG-001**: `TwigPathsPass` registers bundle views namespace.
- **FR-TWIG-002**: `TimeTrackTwigExtension` exposes the Web UI globals (`templates.layout`, `templates.css_framework`) to the bundle templates (REQ-UI-001).
- **FR-ROUTE-001**: `TimeTrackRouteLoader` (`type: nowo_time_track`) MUST expose configurable manage and API routes.
- **FR-ROUTE-002** (1.28, REQ-SF-004): the routes MUST be declared with `#[Route]` attributes on the controllers; `TimeTrackRouteLoader` reads them through the internal `ControllerAttributeRouteLoader` and applies the configured names, paths, `route_prefix` and feature switches, keeping the 1.27 collection (fixture `tests/Fixtures/Routing/route-collections.json`). The controllers MUST NOT be autoconfigured (rule 7).
- **FR-ROUTE-003** (1.29, REQ-API-001): every client API route MUST also be served at `<route_prefix><client_api.prefix>/time-track/v1/<suffix>` (default `/api/time-track/v1/…`, name `nowo_time_track_api_v1_<key>`) as the twin of its unversioned route (same controller, methods, requirements, defaults). The unversioned paths and names are permanent aliases (installed clients). `type: nowo_time_track_api` loads the client routes only. An unversioned name or path that collides with a v1 route MUST fail the container build.

### Persistence

- **FR-DOCTRINE-001**: `TimeTrackMetadataListener` MUST apply `table_prefix` to `ActiveTimer`, `TimeEntry`, `ClientToken` mappings.
- **FR-ENTITY-001**: `ActiveTimer` — one row per user with task reference and client metadata.
- **FR-ENTITY-002**: `TimeEntry` — completed intervals with source enum and optional edit metadata.
- **FR-ENTITY-003**: `ClientToken` — hashed bearer tokens with expiry for client apps.
- **FR-ENUM-001**: `ClientType`, `TimeEntrySource` classify timer origin.
- **FR-VO-001**: `Uuid` value object for primary keys where used.
- **FR-REPO-001**: Repository interfaces for active timer, time entry, client token.
- **FR-REPO-002**: Doctrine ORM implementations with save/find/query methods.
- **FR-REPO-003**: Optional `PaginatedTimeEntryRepositoryInterface` (page + count in the database) for the reports list (REQ-PERF-001).

### Integration bridges

- **FR-INT-001**: `TaskProviderInterface` MUST resolve tasks, list query, and track permission for a user.
- **FR-INT-002**: `TeamContextProviderInterface` MUST expose team membership for manager reports.
- **FR-BRIDGE-001**: `StubTaskProvider` and `NullTeamContextProvider` MUST provide no-op/demo defaults.
- **FR-DTO-001**: `TaskReference`, `TaskListQuery` DTOs for provider contracts.
- **FR-DTO-002**: `TimeEntryPage` — one page of time entries with totals (`reports.page_size`).

### Domain services

- **FR-SVC-001**: `TimerService` — start/stop/getActive/list entries; dispatches timer events; enforces single active timer.
- **FR-SVC-002**: `ClientAuthService` — login, token validation, revocation.
- **FR-SVC-003**: `TeamAccessGuard` — manager view/edit gates using team context and config roles.
- **FR-SUP-001**: `UserIdResolver` extracts stable user id from `UserInterface`.
- **FR-SUP-002**: `QueryPeriodResolver` parses the `from` / `to` query parameters of the reports page and the client entries API; only strict formats (ATOM / RFC 3339 or `Y-m-d`) are accepted, anything else falls back to the default period.
- **FR-SEC-001**: Manage UI access goes through `TimeTrackAccessCheckerInterface`: `ConfigurableTimeTrackAccessChecker` (default, `security.access_roles`) or `AllowAllTimeTrackAccessChecker` only when `security.allow_unauthenticated` is true (demo/dev).
- **FR-WORKER-001**: Safe without kernel reset (FrankenPHP worker): `RecoveringFlusher` reopens a closed EntityManager after a failed flush, `ManagedEntityRefresher` reloads managed entities before security decisions so a stale identity map is never trusted.

### Client layer

- **FR-CLIENT-001**: `ClientAuthenticatorInterface` / `DefaultClientAuthenticator` validate client credentials.
- **FR-CLIENT-002**: `ClientLoginRateLimiter` throttles failed logins.
- **FR-CLIENT-003**: `ClientResponseFactory` builds consistent JSON error/success payloads.
- **FR-CLIENT-004**: `ClientAuthResult` encapsulates auth outcome.

### HTTP & CLI

- **FR-CTRL-001**: `TimeTrackManageController` — web timer UI and reports.
- **FR-CTRL-002**: `TimeTrackClientApiController` — Bearer JSON API for extensions/desktop.
- **FR-CLI-001**: `nowo:time-track:purge-client-tokens` removes expired tokens.

### Events & security

- **FR-EVT-001**: `TimeTrackEvents` name constants for timer and entry hooks.
- **FR-EVT-002**: `TimerStartEvent`, `TimerStopEvent` carry user, task, client type.
- **FR-EVT-003**: `TimeEntryAccessCheckEvent`, `TimeEntryListQueryEvent` for host ACL extension.
- **FR-EXC-001**: `ActiveTimerConflictException` signals 409 conflict.

### UI & i18n

- **FR-TPL-001**: `time_track/index.html.twig` and `time_track/reports.html.twig` render the manage UI on the UiKit list scaffold with view models from `Ui/TimeTrackPages` (REQ-UI-003, see docs/TEMPLATES.md); `layout.html.twig` / `_legacy_layout.html.twig` are deprecated 1.x shims (removed in 2.0).
- **FR-I18N-001**: `NowoTimeTrackBundle.en.yaml`, `.es.yaml` translation catalogs.

### Device authorization (client apps, v0.2 contract §2)

- **FR-DEV-001**: `DeviceAuthorizationService` implements the RFC 8628-shaped device flow (issue device / user code, look up, approve or deny, poll); entity `DeviceAuthorization` with `DeviceAuthorizationStatus`; `DeviceCodeGrant` / `DevicePollResult` DTOs; refusals `DeviceAuthorizationNotPendingException`, `DeviceCodeAttemptsExceededException`, `DeviceFlowCapacityExceededException`.
- **FR-DEV-002**: Controllers `TimeTrackClientDeviceController` (client side) and `TimeTrackDeviceVerificationController` (signed-in user), forms `DeviceUserCodeType` / `DeviceDecisionType`, templates `time_track/device_code` and `device_confirm`.

### Client API settings and idle check (1.10-1.13)

- **FR-CLIENT-005**: Client API plumbing: `ClientApiGuard` (stateless request checks: rate limit, Bearer token, scope), `ClientApiRateLimiter`, `ClientApiAuditor` + `ClientApiEvent` + `ClientApiAuditAction`, `ClientApiPrincipal`, `ClientApiSettings` with its provider port, `ClientIdempotencyStore` (`Idempotency-Key` replays), `ClientScope`, `ClientTokenRevoker`, credential issuer / minter / state ports (`OpaqueClientCredentialIssuer` default), profile / locale / privacy / days-off / leave provider and source ports with their normalisers, `ClientDistribution` (download links, minimum supported version), `ClientTaskPresenter`, `ClientWorkingTimeContext` and the `client_distribution/_downloads` partial.
- **FR-CLIENT-006**: Idle-check settings of a user (`IdleCheckSettings`, `IdleCheckSettingsResolver`, provider and policy ports, `IdleFollowUp`, `IdleResolution`, `IdleResolvedEvent`).
- **FR-CLIENT-007** (1.29, `client_api.user_checker`, Beacon C5): every Bearer token use MUST run the Symfony user checker (`checkPreAuth()` + `checkPostAuth()`) for the token's user, on by default (`ClientAccountChecker`, `ClientUserCheckerPass` resolving `security.user_checker.<firewall>`); a refused account gets `401 invalid_token` and, with `revoke_tokens` (default), its token is deleted; password login (`401 invalid_credentials`) and the device exchange (`400 access_denied`) refuse it before issuing a token. A checker that refuses nobody MUST be logged at build time and flagged by `nowo_time_track.client_api.user_checker.effective` (false), and fail the build with `required: true`. Configurable checker service id or firewall; `false` turns it off.

### Timesheets (period lock, approval; 1.13)

- **FR-TS-001**: `Timesheet` and `TimesheetEvent` entities, `TimesheetStatus` / `TimesheetPeriodKind` / `TimesheetAction` / `TimesheetActorRole` / `TimesheetLockReason`, `Period`, `TimesheetSnapshot` and builder, `TimesheetService` (state machine).
- **FR-TS-002**: `PeriodLockGuard` and `WriteKind` refuse writes into a locked or submitted period (`PeriodLockedException`, `PeriodSubmittedException`, `PeriodWriteRefusedException`, `TimesheetException`); `ScopeKeys` / `TimesheetScope` + provider and policy ports with `SingleScopeProvider` default.
- **FR-TS-003**: UI: `TimesheetViewBuilder`, `TimeTrackComponentExtension` / `Runtime`, `TimesheetFormHandler`, `TimesheetDecisionType`, `LedgerLineType` and the `components/*` templates (week grid, balance card, ledger table).
- **FR-TS-004**: Events `TimesheetTransitionEvent`, `TimesheetAccessCheckEvent`, `TimesheetLateEntryEvent`, `PeriodLockRefusedEvent`.
- **FR-TS-005**: Commands `timesheets-auto-lock`, `timesheets-purge`, `timesheets-verify`; `AuditChainVerifier` names the first broken link of the audit chains and `CanonicalJson` canonicalises them; `TimesheetAuditTrail`, `TimesheetTimezoneResolver`, `TimesheetAccessGuard`, approver ports.

### Working time and balances (1.13)

- **FR-WT-001**: `WorkingTimeBalanceService` / `WorkingTimeBalanceCalculator` compute the hours balance of a user in a scope from the working calendar and the worked time (`WorkingCalendarProviderInterface`, `DerivedWorkingCalendar`, `CalendarDay` + normaliser, `WorkedDay`, `TaskTimeWorkedTimeSource`, `OvertimePolicyInterface` with `ConfigOvertimePolicy`, `BalanceQuery`, `BalanceReport`).
- **FR-WT-003**: `BalanceLedgerLine` entity, `BalanceLedger`, `LedgerKind`, `BalanceLedgerLineAddedEvent`, `LedgerRefusedException`.
- **FR-WT-004**: `TimeTrackWorkingTimeApiController` serves the balance to clients.

### Workday

- **FR-WDAY-001**: The clients' access to the host's working-time store: `WorkdayProviderInterface` / `WorkdaySourceInterface`, `WorkdayAction`, `WorkdayState`, `WorkdayConflictException`, `WorkdayForbiddenException`.

### Calendar integration (1.14+, spec 004)

- **FR-CAL-001**: Calendar connections (`CalendarConnection`, `CalendarRule`, `CalendarSuggestion` entities, status / mode / provider / match-kind enums, `CalendarConnectionService`, `CalendarSettings`, `CalendarWorkingHours`, `CalendarRefHasher`, `CalendarFields`).
- **FR-CAL-002**: OAuth and provider access: `CalendarOAuthApp*`, `CalendarOAuthState`, `CalendarPkce`, `CalendarTokenEndpoint`, `CalendarTokens`, `CalendarCredential*`, `CalendarHttpClient`, Google and Microsoft event sources and OAuth clients (secrets wrapped in `SensitiveValue`).
- **FR-CAL-003**: `CalendarSyncService` (server-mode sync), `CalendarSuggestionService` (list, accept, dismiss), `CalendarEventMatcher`, `CalendarRuleService`, `CalendarMatch`, `CalendarMeetingBatch` / views.
- **FR-CAL-004**: `TimeTrackCalendarApiController` and the `nowo:time-track:calendar-sync` command (`CalendarSyncCommand`).
- **FR-CAL-005**: Events `CalendarConnectionChangedEvent`, `CalendarSuggestionDecidedEvent`, `CalendarSyncFinishedEvent`; `CalendarSyncException`, `CalendarException`.

### Meetings (1.14)

- **FR-MEET-001**: The host's meeting read model: `MeetingProviderInterface` / `MeetingSourceInterface`, `MeetingQuery` + `MeetingQueryParser`, `MeetingView`, `MeetingAttendeeView`, `MeetingPage`, `MeetingContext`, `MeetingSettings`, `MeetingSyncState`, `MeetingLinkDetector`, `MeetingTaskLink`, `MeetingResponder`.
- **FR-MEET-002**: `TimeTrackMeetingsApiController`; `MeetingRequestException` maps to a client error.

### Monitoring (opt-in, 1.26)

- **FR-MON-001**: `MonitoringGate` runs the refusal checks before any byte is accepted; `MonitoringPolicy` + policy source port, `MonitoringSettings`, `MonitoringContext`, acknowledgement (`MonitoringAcknowledgement`, store port), upload intent and claim, signer (`HmacMonitoringUploadSigner`), image inspector, evidence store port, activity budget and items.
- **FR-MON-002**: `TimeTrackMonitoringApiController`.
- **FR-MON-003**: `MonitoringSpanDiscardedEvent` and `MonitoringSpanDiscardListener`.

### Imported time entries (1.11)

- **FR-IMP-001**: `ImportedTimeEntryWriter` / `ImportedTimeEntryDeleter` write and remove entries idempotently by claim; DTOs, outcomes and results, `ImportedDescriptionSanitizer`, `ImportedTimeEntryOwnerMismatchException`, `InvalidImportedTimeEntryException`, events `TimeEntryImportedEvent`, `TimeEntryCreatedEvent`, `TimeEntriesRemovedEvent`.

### Task time move (1.17)

- **FR-MOVE-001**: `TaskTimeMover` makes the time of tasks moved to another board follow them, also across tenants; `TaskTimeKeptReason`, `TaskTimeMoveResult`, `TaskTimeMoveEventException`, events `TaskTimeMovedEvent`, `TimeEntriesMovedEvent`.

### Host timer API (1.25)

- **FR-TIMER-001**: `HostTimerApi` (start, stop, current for the host's own controllers) with `HostTimerStart`, `TimerChannel`, `TimerSnapshot`, `TimerEntrySnapshot`.

### Retention, former people and GDPR (1.11-1.15)

- **FR-RET-001**: `TimeEntryRetentionPolicy` (imported entries are history and stay out of a purge), `FormerPersonTimeEntries` + `FormerPersonSnapshotListener`: tracked time is kept when a user is deleted.
- **FR-GDPR-001**: Personal-data export of timesheets and calendar meetings (`TimesheetPersonalDataExporter`, `CalendarPersonalDataExporter`, `CalendarMeetingPersonalData`), CSV own-data export (`TimeEntryCsv`).

### Host users and user references (1.23)

- **FR-USER-001**: `nowo_time_track.user_mapping`: `UserMapping`, `ExactUserIds`, `HostUserRows`, entity `HostUser` with `doctrine-host-users/HostUser.orm.xml`, `DoctrineUserReferenceResolver`, `PassThroughUserReferenceResolver`, `UserReferenceProviderInterface` / `UserReferenceResolverInterface`.

### Framework-free ports (1.23, REQ-ARCH-001 rule 3)

- **FR-PORT-001**: Gates in `Port\*` (`Ports`, `TimeTrackUserRefs`, `TimeTrackUserRefFactory` and one gate per host port) serve the deprecated Symfony-typed interfaces with the host's framework-free twin.
- **FR-PORT-002**: Value objects `TimeTrackUserRef`, `CalendarConnectionRef`, host directory ports (`TaskDirectoryInterface`, `BatchTaskDirectoryInterface`, `TeamDirectoryInterface`), `ServiceAccountPolicyInterface` (default `NoServiceAccountPolicy`), `TimeEntryBillableDefaultProviderInterface`; `Internal\HiddenValues`.
- **FR-PORT-003**: `TimeTrackPortsPass` wires the twins and refuses a port service implementing neither interface.

### Private entity manager and transactions (1.23+)

- **FR-PEM-001**: `TimeTrackEntityManager`, `TimeTrackManagerRegistry`, `TimeTrackRepositoryFactory`: the bundle's own entity manager so it never flushes the host's (REQ-ARCH-001 rule 6).
- **FR-PEM-002**: `PrivateEntityManagerMiddleware`, `Driver`, `Connection`, `Subscriber` report commits, rollbacks and savepoints to the private manager.
- **FR-PEM-003**: `PrivateEntityManagerPass` wires the mode.
- **FR-TX-001**: `TimeTrackTransactionRunner` and `TimeTrackFlushObserver`.
- **FR-TX-002**: `TimeTrackSavepoint`, `TimeTrackRows` and `UnitOfWorkBoundaries` run DBAL writes inside a savepoint and act only at the outermost unit-of-work boundary.
- **FR-TX-004**: `StorageFailureSubscriber` answers a storage failure with 503 + `Retry-After`.

### Contracts, persistence and support classes

- **FR-REPO-004**: Repository ports and Doctrine implementations of the entities above (timesheets, ledger, calendar, device authorization, monitoring, imported entries, task-time moves, scope backfill, retention) and `ImportedTimeEntryRepositoryResolver`, `TimeEntryOriginFilter`.
- **FR-ORM-002**: `Resources/config/doctrine/*.orm.xml` XML mappings for hosts that map entities by XML (DDD opt-in).
- **FR-SEC-002**: `TaskAccessCheckerInterface` / `TaskAccessPolicyInterface` with `TaskProviderTaskAccessChecker` as default.
- **FR-SVC-004**: `ManualEntryService` and `TimeEntryBillableService` (manual entries, billable flag with `BillableChangeRefusedException`).
- **FR-SUP-003**: `Support\*` helpers: `CanonicalJson`, `IpPrefix`, `NoteSanitizer`, `Rfc3339`, `StorageTime`, `TimeEntryCsv`.
- **FR-CLI-002**: Further commands: `BackfillEntryScopeCommand`, `CalendarSyncCommand`, timesheet commands (FR-TS-005).
- **FR-DTO-003**: `BackdateLimits`, `ClampedTime`, `TimeEntryView`.
- **FR-ENUM-002**, **FR-EVT-004**, **FR-EXC-002**: the enums, events and exceptions not owned by a subsystem above.

---

## Success Criteria

- **SC-001**: 458/458 production files mapped in [`code-inventory.md`](code-inventory.md).
- **SC-002**: Documented routes match `Configuration` defaults and route loader.
- **SC-003**: PHPUnit + PHPStan pass; demo timer flow works with TaskBoard.
- **SC-004**: Client API returns 409 on double-start and 401 on invalid token.

---

## Explicit non-goals

- Billing/invoicing from time entries.
- Offline-first sync conflict resolution beyond single active timer rule.
- Built-in task CRUD (delegated to `TaskProviderInterface`).

---

## Validation

| Check | Command |
| --- | --- |
| Full QA | `composer qa` |
| Demo | `make -C demo up-symfony8` → port 8024 |
