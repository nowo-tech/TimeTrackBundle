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
- **FR-ROUTE-001**: `TimeTrackRouteLoader` (`type: nowo_time_track`) MUST expose configurable manage and API routes.

### Persistence

- **FR-DOCTRINE-001**: `TimeTrackMetadataListener` MUST apply `table_prefix` to `ActiveTimer`, `TimeEntry`, `ClientToken` mappings.
- **FR-ENTITY-001**: `ActiveTimer` — one row per user with task reference and client metadata.
- **FR-ENTITY-002**: `TimeEntry` — completed intervals with source enum and optional edit metadata.
- **FR-ENTITY-003**: `ClientToken` — hashed bearer tokens with expiry for client apps.
- **FR-ENUM-001**: `ClientType`, `TimeEntrySource` classify timer origin.
- **FR-VO-001**: `Uuid` value object for primary keys where used.
- **FR-REPO-001**: Repository interfaces for active timer, time entry, client token.
- **FR-REPO-002**: Doctrine ORM implementations with save/find/query methods.

### Integration bridges

- **FR-INT-001**: `TaskProviderInterface` MUST resolve tasks, list query, and track permission for a user.
- **FR-INT-002**: `TeamContextProviderInterface` MUST expose team membership for manager reports.
- **FR-BRIDGE-001**: `StubTaskProvider` and `NullTeamContextProvider` MUST provide no-op/demo defaults.
- **FR-DTO-001**: `TaskReference`, `TaskListQuery` DTOs for provider contracts.

### Domain services

- **FR-SVC-001**: `TimerService` — start/stop/getActive/list entries; dispatches timer events; enforces single active timer.
- **FR-SVC-002**: `ClientAuthService` — login, token validation, revocation.
- **FR-SVC-003**: `TeamAccessGuard` — manager view/edit gates using team context and config roles.
- **FR-SUP-001**: `UserIdResolver` extracts stable user id from `UserInterface`.

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

- **FR-TPL-001**: `layout.html.twig`, `time_track/index.html.twig`, `time_track/reports.html.twig` render manage UI.
- **FR-I18N-001**: `NowoTimeTrackBundle.en.yaml`, `.es.yaml` translation catalogs.

---

## Success Criteria

- **SC-001**: 48/48 production files mapped in [`code-inventory.md`](code-inventory.md).
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
