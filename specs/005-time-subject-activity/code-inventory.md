# Code inventory — 100% traceability

**Spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/time-track-bundle`  
**Last audited**: 2026-11-25 (2.1.0)

## New PHP classes (`src/**/*.php`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Subject/TimeSubjectRef.php` | Subject reference VO | FR-SUBJ-001 |
| `Subject/TimeSubjectContext.php` | Actor + tenant VO | FR-SUBJ-001 |
| `Subject/TimeSubjectLabel.php` | Subject label VO (cleaned, https URL) | FR-SUBJ-001, FR-SUBJ-007 |
| `Subject/TimeSubjectLabels.php` | Batched describe answer | FR-SUBJ-001 |
| `Subject/TimeSubjectSearch.php` | Bounded search VO | FR-SUBJ-001 |
| `Subject/ActivityType.php` | Activity type VO | FR-SUBJ-001 |
| `Subject/ActivityTypeList.php` | Activity types of a tenant | FR-SUBJ-001 |
| `Subject/TimeAllocation.php` | Write allocation VO | FR-SUBJ-001, FR-SUBJ-004 |
| `Subject/TimeAllocationPatch.php` | Tri-state allocation change | FR-SUBJ-001, FR-SUBJ-004 |
| `Subject/TimeSubjectProviderInterface.php` | Subject provider port (tagged) | FR-SUBJ-002 |
| `Subject/ActivityTypeProviderInterface.php` | Activity type port | FR-SUBJ-002 |
| `Subject/TimeSubjectRegistry.php` | Provider registry (internal, deny by default) | FR-SUBJ-002 |
| `Subject/EmptySubjectProviders.php` | Empty provider locator (internal) | FR-SUBJ-002 |
| `Subject/ActivityTypeCatalog.php` | Activity type provider wrapper (internal) | FR-SUBJ-002 |
| `Subject/ScopeTimeSubjectProvider.php` | Built-in "tenant itself" provider (Q-TS-13) | FR-SUBJ-002 |
| `Subject/AllocationGuard.php` | Allocation checks (internal) | FR-SUBJ-003 |
| `Subject/TimeSubjectLabelResolver.php` | Public label resolver (canRead) | FR-SUBJ-007 |
| `Subject/ScopedSubjectLabels.php` | Labels per tenant (internal) | FR-SUBJ-007 |
| `Subject/AllocationLabeler.php` | Page labeler of the read surfaces (internal) | FR-SUBJ-007 |
| `Subject/AllocationLabels.php` | Masked / readable wire shapes (internal) | FR-SUBJ-007 |
| `DependencyInjection/Compiler/TimeSubjectProvidersPass.php` | Provider locator + activity slot | FR-SUBJ-002 |
| `Exception/TimeAllocationException.php` | Allocation refusal codes | FR-SUBJ-003 |
| `Exception/TimeAllocationRequiredException.php` | Interactive stop refusal | FR-SUBJ-004 |
| `Event/TimerAllocatedEvent.php` | Running timer allocated | FR-SUBJ-009 |
| `Event/TimeEntryUnallocatedEvent.php` | System stop stored unallocated | FR-SUBJ-009 |
| `Query/AllocationFilter.php` | Allocation filter / group key | FR-SUBJ-006 |
| `Query/TimeEntryGrouping.php` | Totals grouping | FR-SUBJ-006 |
| `Query/TimeEntryGroupKey.php` | Totals group key | FR-SUBJ-006 |
| `Query/TimeEntryTotal.php` | One totals group | FR-SUBJ-006 |
| `Query/TimeEntryTotals.php` | Totals answer | FR-SUBJ-006 |
| `Query/TimeEntryTotalsQueryInterface.php` | Totals port | FR-SUBJ-006 |
| `Command/CheckAllocationCommand.php` | Read-only column contract check | FR-SUBJ-005 |
| `Client/ClientAllocationInput.php` | Client API allocation fields | FR-SUBJ-008 |
| `Client/ClientAllocationPresenter.php` | Client API subject / activity output | FR-SUBJ-007, FR-SUBJ-008 |
| `Controller/TimeTrackClientAllocationApiController.php` | timer/allocate, subjects/search, activity-types | FR-SUBJ-008 |

## Files changed for 2.1.0 (also listed in the baseline inventory)

| Source file | Change | Requirement IDs |
| --- | --- | --- |
| `Entity/TimeEntry.php` | Allocation columns, indexes | FR-SUBJ-005 |
| `Entity/ActiveTimer.php` | Allocation columns | FR-SUBJ-005 |
| `Service/TimerService.php` | Allocation on every timer path, allocateRunning | FR-SUBJ-004 |
| `Service/ManualEntryService.php` | Allocation on manual entries | FR-SUBJ-004 |
| `Entry/TimeEntryOperations.php` | Allocation corrections | FR-SUBJ-004 |
| `Timer/HostTimerApi.php` | startAllocated, stop patch | FR-SUBJ-004 |
| `Query/DoctrineTimeTrackQuery.php` | Filters and totals | FR-SUBJ-006 |
| `Query/TimeEntryCriteria.php` | Allocation filters | FR-SUBJ-006 |
| `Query/TimeEntryRecord.php` | Version covers allocation | FR-SUBJ-006 |
| `Controller/TimeTrackClientApiController.php` | v0.4 bodies | FR-SUBJ-008 |
| `Controller/TimeTrackCalendarApiController.php` | POST entries allocation | FR-SUBJ-008 |
| `Controller/TimeTrackManageController.php` | Interactive stop, reports column | FR-SUBJ-007 |
| `Support/TimeEntryCsv.php` | Allocation columns | FR-SUBJ-010 |
| `PersonalData/DoctrineTimeTrackPersonalData.php` | GDPR export fields | FR-SUBJ-010 |
| `Timesheet/TimesheetSnapshotBuilder.php` | bySubject / byActivityType | FR-SUBJ-010 |
| `Doctrine/TimeTrackMetadataListener.php` | Exact collation of the allocation columns | FR-SUBJ-005 |
| `Doctrine/TimeTrackExactKeyColumns.php` | Allocation exact keys | FR-SUBJ-005 |

## Other files

| File | Purpose | Requirement IDs |
| --- | --- | --- |
| `Resources/config/doctrine/TimeEntry.orm.xml`, `ActiveTimer.orm.xml` | DDD XML mapping of the new columns | FR-SUBJ-005 |
| `docs/sql/2.1.0/*.sql` | Reference migrations (MySQL, MariaDB, PostgreSQL, SQLite) | FR-SUBJ-005 |
| `Resources/views/time_track/reports.html.twig` | Subject / activity column | FR-SUBJ-007 |
