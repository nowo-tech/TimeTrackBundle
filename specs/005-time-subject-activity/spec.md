# Feature Specification: Time subject and activity type (Beacon spec 256)

**Feature Branch**: `feat/2.1.0-time-subject`  
**Created**: 2026-11-25  
**Status**: Implemented (2.1.0, unreleased)  
**Input**: Beacon spec 256 (owner-approved D-20261123-16); design and owner decisions Q-TS-1..22 (2026-11-23) in
[`docs/design/TIME_SUBJECT_ACTIVITY.md`](../../docs/design/TIME_SUBJECT_ACTIVITY.md).

**Related docs**: [`docs/USAGE.md`](../../docs/USAGE.md#time-subjects-and-activity-types-21-beacon-spec-256),
[`docs/CLIENT-API.md`](../../docs/CLIENT-API.md#changes-in-21-subjects-and-activity-types-spec-256),
[`docs/UPGRADING.md`](../../docs/UPGRADING.md#to-210), [`docs/CONCURRENCY.md`](../../docs/CONCURRENCY.md),
[`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md)  
**Code inventory (traceability)**: [`code-inventory.md`](code-inventory.md)

## User Scenarios & Testing

### User Story 1 — Log a customer call without a task (P1)

A person logs time on a customer (subject alone) with an activity type. **Acceptance**: `timer/start {subject,
activityTypeKey}` starts a timer in one of the person's scopes after `canLog`; the stop records the entry with both;
unknown type, unknown id and no access answer the same `403 subject_forbidden`. Tests: `AllocationClientApiTest`,
`TimerServiceAllocationTest`.

### User Story 2 — Customer time on a task (P1)

**Acceptance**: `taskId` + `subject` is accepted only when the type's provider `allowsTask()` (else `422
allocation_conflict`); task access is checked first, then `canLog`. Tests: `AllocationClientApiTest`, `AllocationGuardTest`.

### User Story 3 — Allocation required at stop (P1)

**Acceptance**: with `entries.require_allocation` an interactive stop of an unallocated timer answers `409
allocation_required` with `{timer}`, writes nothing and keeps the timer; system stops store the entry and dispatch
`TimeEntryUnallocatedEvent`. Tests: `AllocationRequiredClientApiTest`, `AllocationRequiredDatabaseTest`.

### User Story 4 — Reports and totals by subject (P2)

**Acceptance**: `TimeEntryCriteria` filters by subject / type / activity / allocation; `TimeEntryTotalsQueryInterface`
groups in one statement over at most 366 days; labels via `TimeSubjectLabelResolver` (one `describe()` per type per page);
masked subjects carry no id. Tests: `AllocationDatabaseTest`, `TimeSubjectLabelResolverTest`.

### User Story 5 — Back-fill and check (P2)

**Acceptance**: the host back-fills with its own SQL; `nowo:time-track:entries:check-allocation` reports every broken row
and exits non-zero. Tests: `CheckAllocationCommandTest`.

### Edge Cases

- No provider: every subject refused, `GET me` features empty, columns NULL, version tokens byte-identical to 2.0.
- Provider exception: logged by class, denied (never a 500).
- Races of design §13 (double stop, stop vs allocate, correct vs correct / back-fill): `AllocationRaceTest` with a second
  real connection.
- Worker mode (kernel reset false): two viewers in one kernel see nothing of each other (`AllocationWorkerModeTest`).

## Requirements

### Functional Requirements

- **FR-SUBJ-001**: Value objects `TimeSubjectRef`, `TimeSubjectContext`, `TimeSubjectLabel(s)`, `TimeSubjectSearch`,
  `ActivityType`, `ActivityTypeList`, `TimeAllocation`, `TimeAllocationPatch`, framework-free, validated on construction.
- **FR-SUBJ-002**: Ports `TimeSubjectProviderInterface` (tagged, one per type) and `ActivityTypeProviderInterface`
  (single slot); registry and compiler pass refuse a bad type, a duplicate type, an unknown class.
- **FR-SUBJ-003**: Allocation checks (`AllocationGuard`) on every write path before the owner's lock; deny by default,
  one answer for unknown / foreign subjects.
- **FR-SUBJ-004**: Write paths: timer start / start-replacing / switch / stop / idle reassign / allocate, manual entries,
  corrections, `HostTimerApi`; requirements on interactive writes only.
- **FR-SUBJ-005**: Schema (three exact-key columns on entries and timers, two tenant indexes), reference SQL and
  the read-only `check-allocation` command.
- **FR-SUBJ-006**: Reads: criteria filters, totals port, views and records with subject / activity type, version token.
- **FR-SUBJ-007**: Masking with `canRead` on every bundle read surface (client API, CSV, web UI, GDPR export).
- **FR-SUBJ-008**: Client API v0.4 (`GET me` features, allocation bodies, `timer/allocate`, `subjects/search`,
  `activity-types`, errors, audit `subject_allocated`), no new client scope.
- **FR-SUBJ-009**: Events `TimerAllocatedEvent`, `TimeEntryUnallocatedEvent`; subject / activity getters on the timer and
  entry events.
- **FR-SUBJ-010**: Export, GDPR and timesheet totals keep both fields; erasure keeps them; hash unchanged.

## Success Criteria

- 100% line coverage, PHPStan, PER-CS, Igor and the REQ catalogue green on the PR head; the reference scripts match the
  mapping on SQLite, MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 (CI matrix).
