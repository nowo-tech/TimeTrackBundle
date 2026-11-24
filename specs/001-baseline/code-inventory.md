# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/time-track-bundle`  
**Last audited**: 2026-11-23 (1.40.0 writes by id mapped; 2026-11-22 v1.37.0 files mapped; 2026-11-20 v1.31.0; full `src/` recount; units added since 1.2 mapped to semantic requirement IDs; 1.29 and 1.30 units added)

## PHP classes (`src/**/*.php`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `TimeTrackBundle.php` | Bundle entry | FR-BUNDLE-001 |
| `DependencyInjection/Configuration.php` | Config tree | FR-CFG-001 |
| `DependencyInjection/TimeTrackExtension.php` | DI extension | FR-CFG-002 |
| `DependencyInjection/Compiler/TwigPathsPass.php` | Twig namespace | FR-TWIG-001 |
| `DependencyInjection/Compiler/ClientUserCheckerPass.php` | Client API (1.29 user checker) | FR-CLIENT-007 |
| `Routing/TimeTrackRouteLoader.php` | Route loader (1.29: v1 twins, `nowo_time_track_api` type) | FR-ROUTE-001, FR-ROUTE-002, FR-ROUTE-003 |
| `Routing/ControllerAttributeRouteLoader.php` | Controller `#[Route]` reader (internal) | FR-ROUTE-002 |
| `Doctrine/TimeTrackMetadataListener.php` | Table prefix | FR-DOCTRINE-001 |
| `Doctrine/TimeTrackExactKeyColumns.php` | Table prefix | FR-DOCTRINE-001 (REQ-SEC-006 exact-key columns) |
| `Entity/ActiveTimer.php` | Active timer entity | FR-ENTITY-001 |
| `Entity/TimeEntry.php` | Time entry entity | FR-ENTITY-002 |
| `Entity/ClientToken.php` | Client token entity | FR-ENTITY-003 |
| `Enum/ClientType.php` | Client type enum | FR-ENUM-001 |
| `Enum/TimeEntrySource.php` | Entry source enum | FR-ENUM-001 |
| `ValueObject/Uuid.php` | UUID helper | FR-VO-001 |
| `Dto/TaskReference.php` | Task DTO | FR-DTO-001 |
| `Dto/TaskListQuery.php` | Task list query | FR-DTO-001 |
| `Dto/TimeEntryPage.php` | Reports page of entries | FR-DTO-002 |
| `Integration/TaskProviderInterface.php` | Task provider contract | FR-INT-001 |
| `Integration/TeamContextProviderInterface.php` | Team context contract | FR-INT-002 |
| `Bridge/StubTaskProvider.php` | Demo task provider | FR-BRIDGE-001 |
| `Bridge/NullTeamContextProvider.php` | Null team provider | FR-BRIDGE-001 |
| `Repository/ActiveTimerRepositoryInterface.php` | Active timer repo IF | FR-REPO-001 |
| `Repository/TimeEntryRepositoryInterface.php` | Time entry repo IF | FR-REPO-001 |
| `Repository/ClientTokenRepositoryInterface.php` | Client token repo IF | FR-REPO-001 |
| `Repository/PaginatedTimeEntryRepositoryInterface.php` | Paged time entry repo IF | FR-REPO-003 |
| `Repository/DoctrineOrmActiveTimerRepository.php` | Active timer ORM | FR-REPO-002 |
| `Repository/DoctrineOrmTimeEntryRepository.php` | Time entry ORM | FR-REPO-002 |
| `Repository/DoctrineOrmClientTokenRepository.php` | Client token ORM | FR-REPO-002 |
| `Service/TimerService.php` | Timer domain service | FR-SVC-001 |
| `Service/ClientAuthService.php` | Client auth service | FR-SVC-002, FR-CLIENT-007 |
| `Service/TeamAccessGuard.php` | Manager access guard | FR-SVC-003 |
| `Support/UserIdResolver.php` | User id helper | FR-SUP-001 |
| `Client/ClientAuthenticatorInterface.php` | Client auth IF | FR-CLIENT-001 |
| `Client/DefaultClientAuthenticator.php` | Default client auth | FR-CLIENT-001 |
| `Client/ClientLoginRateLimiter.php` | Login rate limit | FR-CLIENT-002 |
| `Client/ClientResponseFactory.php` | JSON responses | FR-CLIENT-003 |
| `Client/ClientAuthResult.php` | Auth result DTO | FR-CLIENT-004 |
| `Controller/TimeTrackManageController.php` | Web UI | FR-CTRL-001 |
| `Controller/TimeTrackClientApiController.php` | Client JSON API | FR-CTRL-002 |
| `Command/PurgeExpiredClientTokensCommand.php` | Token purge CLI | FR-CLI-001 |
| `Event/TimeTrackEvents.php` | Event names | FR-EVT-001 |
| `Event/TimerStartEvent.php` | Timer start event | FR-EVT-002 |
| `Event/TimerStopEvent.php` | Timer stop event | FR-EVT-002 |
| `Event/TimeEntryAccessCheckEvent.php` | Entry ACL event | FR-EVT-003 |
| `Event/TimeEntryListQueryEvent.php` | Entry list filter | FR-EVT-003 |
| `Exception/ActiveTimerConflictException.php` | Conflict exception | FR-EXC-001 |
| `Form/TimerActionType.php` | Start / stop CSRF form type (FormKit profile `time_track`) | FR-CTRL-001 |
| `Doctrine/ManagedEntityRefresher.php` | Worker-safe entity reload before security checks | FR-WORKER-001 |
| `Doctrine/RecoveringFlusher.php` | Flush with closed-EntityManager recovery | FR-WORKER-001 |
| `Security/AllowAllTimeTrackAccessChecker.php` | Permissive checker (allow_unauthenticated) | FR-SEC-001 |
| `Security/ConfigurableTimeTrackAccessChecker.php` | Role-based default checker | FR-SEC-001 |
| `Security/TimeTrackAccessCheckerInterface.php` | Access checker contract | FR-SEC-001 |
| `Support/QueryPeriodResolver.php` | from/to query parsing | FR-SUP-002 |
| `Twig/TimeTrackTwigExtension.php` | Web UI Twig globals | FR-TWIG-002 |
| `Ui/TimeTrackPages.php` | UiKit view models + layout resolution of the manage pages (REQ-UI-003) | FR-TPL-001 |

## Symfony config

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/config/services.yaml` | Service wiring | FR-DI-001 |

## Twig views

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/views/layout.html.twig` | Deprecated 1.x layout shim (maps to the UiKit admin layout) | FR-TPL-001 |
| `Resources/views/_legacy_layout.html.twig` | Deprecated bridge for 1.x host layouts | FR-TPL-001 |
| `Resources/views/time_track/index.html.twig` | Timer index | FR-TPL-001 |
| `Resources/views/time_track/reports.html.twig` | Reports page | FR-TPL-001 |
| `Resources/views/components/dialog_form.html.twig` | Timesheet dialog content (1.39, `TimesheetDialogResponder`) | FR-TS-003 |
| `Resources/views/components/dialog_page.html.twig` | Timesheet dialog page (1.39, `TimesheetDialogResponder`) | FR-TS-003 |

## Translations

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/translations/NowoTimeTrackBundle.en.yaml` | English | FR-I18N-001 |
| `Resources/translations/NowoTimeTrackBundle.es.yaml` | Spanish | FR-I18N-001 |
| `Resources/translations/NowoTimeTrackBundle.de.yaml` | German | FR-I18N-001 |
| `Resources/translations/NowoTimeTrackBundle.fr.yaml` | French | FR-I18N-001 |
| `Resources/translations/NowoTimeTrackBundle.it.yaml` | Italian | FR-I18N-001 |
| `Resources/translations/NowoTimeTrackBundle.nl.yaml` | Dutch | FR-I18N-001 |
| `Resources/translations/NowoTimeTrackBundle.pt.yaml` | Portuguese | FR-I18N-001 |

## Calendar integration (1.20+)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Calendar/CalendarAttendee.php` | Calendar integration (1.20+) | FR-CAL-001 |
| `Calendar/CalendarConnectionService.php` | Calendar integration (1.20+) | FR-CAL-001 |
| `Calendar/CalendarCredential.php` | Calendar integration (1.20+) | FR-CAL-002 |
| `Calendar/CalendarCredentialProviderInterface.php` | Calendar integration (1.20+) | FR-CAL-002 |
| `Calendar/CalendarCredentialSourceInterface.php` | Calendar integration (1.20+) | FR-CAL-002 |
| `Calendar/CalendarDescriptor.php` | Calendar integration (1.20+) | FR-CAL-001 |
| `Calendar/CalendarEventSourceInterface.php` | Calendar integration (1.20+) | FR-CAL-001 |
| `Calendar/CalendarEventView.php` | Calendar integration (1.20+) | FR-CAL-001 |
| `Calendar/CalendarException.php` | Calendar integration (1.20+) | FR-CAL-001 |
| `Calendar/CalendarFields.php` | Calendar integration (1.20+) | FR-CAL-001 |
| `Calendar/CalendarHttpClient.php` | Calendar integration (1.20+) | FR-CAL-001 |
| `Calendar/CalendarMeetingBatch.php` | Calendar integration (1.20+) | FR-CAL-001 |
| `Calendar/CalendarMeetingView.php` | Calendar integration (1.20+) | FR-CAL-001 |
| `Calendar/CalendarOAuthApp.php` | Calendar integration (1.20+) | FR-CAL-002 |
| `Calendar/CalendarOAuthAppProviderInterface.php` | Calendar integration (1.20+) | FR-CAL-002 |
| `Calendar/CalendarOAuthAppSource.php` | Calendar integration (1.20+) | FR-CAL-002 |
| `Calendar/CalendarOAuthClientInterface.php` | Calendar integration (1.20+) | FR-CAL-002 |
| `Calendar/CalendarOAuthClients.php` | Calendar integration (1.20+) | FR-CAL-002 |
| `Calendar/CalendarOAuthState.php` | Calendar integration (1.20+) | FR-CAL-002 |
| `Calendar/CalendarPkce.php` | Calendar integration (1.20+) | FR-CAL-002 |
| `Calendar/CalendarReaderInterface.php` | Calendar integration (1.20+) | FR-CAL-001 |
| `Calendar/CalendarRefHasher.php` | Calendar integration (1.20+) | FR-CAL-001 |
| `Calendar/CalendarSettings.php` | Calendar integration (1.20+) | FR-CAL-001 |
| `Calendar/CalendarSyncException.php` | Calendar integration (1.20+) | FR-CAL-001 |
| `Calendar/CalendarSyncResult.php` | Calendar integration (1.20+) | FR-CAL-001 |
| `Calendar/CalendarSyncService.php` | Calendar integration (1.20+) | FR-CAL-001 |
| `Calendar/CalendarTokenEndpoint.php` | Calendar integration (1.20+) | FR-CAL-002 |
| `Calendar/CalendarTokens.php` | Calendar integration (1.20+) | FR-CAL-002 |
| `Calendar/CalendarWorkingHours.php` | Calendar integration (1.20+) | FR-CAL-001 |
| `Calendar/GoogleCalendarEventSource.php` | Calendar integration (1.20+) | FR-CAL-002 |
| `Calendar/GoogleCalendarOAuthClient.php` | Calendar integration (1.20+) | FR-CAL-002 |
| `Calendar/MicrosoftCalendarEventSource.php` | Calendar integration (1.20+) | FR-CAL-002 |
| `Calendar/MicrosoftCalendarOAuthClient.php` | Calendar integration (1.20+) | FR-CAL-002 |
| `Calendar/SensitiveValue.php` | Calendar integration (1.20+) | FR-CAL-001 |
| `Calendar/TaskKeyLookupInterface.php` | Calendar integration (1.20+) | FR-CAL-001 |
| `Calendar/TaskKeyResolverInterface.php` | Calendar integration (1.20+) | FR-CAL-001 |
| `Controller/TimeTrackCalendarApiController.php` | Calendar integration (1.20+) | FR-CAL-004 |
| `Entity/CalendarConnection.php` | Calendar integration (1.20+) | FR-CAL-001 |
| `Entity/CalendarRule.php` | Calendar integration (1.20+) | FR-CAL-001 |
| `Entity/CalendarSuggestion.php` | Calendar integration (1.20+) | FR-CAL-001 |
| `Enum/CalendarConnectionMode.php` | Calendar integration (1.20+) | FR-CAL-001 |
| `Enum/CalendarConnectionStatus.php` | Calendar integration (1.20+) | FR-CAL-001 |
| `Enum/CalendarMatchKind.php` | Calendar integration (1.20+) | FR-CAL-001 |
| `Enum/CalendarProvider.php` | Calendar integration (1.20+) | FR-CAL-001 |
| `Enum/CalendarSuggestionStatus.php` | Calendar integration (1.20+) | FR-CAL-001 |
| `Event/CalendarConnectionChangedEvent.php` | Calendar integration (1.20+) | FR-CAL-005 |
| `Event/CalendarSuggestionDecidedEvent.php` | Calendar integration (1.20+) | FR-CAL-005 |
| `Event/CalendarSyncFinishedEvent.php` | Calendar integration (1.20+) | FR-CAL-005 |

## Calendar suggestions (spec 004)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Calendar/CalendarEventMatcher.php` | Calendar suggestions (spec 004) | FR-CAL-003 |
| `Calendar/CalendarMatch.php` | Calendar suggestions (spec 004) | FR-CAL-003 |
| `Calendar/CalendarRuleService.php` | Calendar suggestions (spec 004) | FR-CAL-003 |
| `Calendar/CalendarSuggestionService.php` | Calendar suggestions (spec 004) | FR-CAL-003 |

## GDPR export and erasure

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Calendar/CalendarMeetingPersonalData.php` | GDPR export and erasure | FR-GDPR-001 |
| `Calendar/CalendarPersonalDataExporter.php` | GDPR export and erasure | FR-GDPR-001 |

## Client API (spec 002)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Client/AlwaysActiveClientCredentialState.php` | Client API (spec 002) | FR-CLIENT-005 |
| `Client/ClientApiAuditor.php` | Client API (spec 002) | FR-CLIENT-005 |
| `Client/ClientAccountChecker.php` | Client API (1.29 user checker) | FR-CLIENT-007 |
| `Client/ClientApiGuard.php` | Client API (spec 002) | FR-CLIENT-005, FR-CLIENT-007 |
| `Client/ClientApiPrincipal.php` | Client API (spec 002) | FR-CLIENT-005 |
| `Client/ClientApiRateLimiter.php` | Client API (spec 002) | FR-CLIENT-005 |
| `Client/ClientApiSettings.php` | Client API (spec 002) | FR-CLIENT-005 |
| `Client/ClientApiSettingsProviderInterface.php` | Client API (spec 002) | FR-CLIENT-005 |
| `Client/ClientCredentialIssuerInterface.php` | Client API (spec 002) | FR-CLIENT-005 |
| `Client/ClientCredentialMinterInterface.php` | Client API (spec 002) | FR-CLIENT-005 |
| `Client/ClientCredentialStateInterface.php` | Client API (spec 002) | FR-CLIENT-005 |
| `Client/ClientDaysOffNormalizer.php` | Client API (spec 002) | FR-CLIENT-005 |
| `Client/ClientDistribution.php` | Client API (spec 002) | FR-CLIENT-005 |
| `Client/ClientDistributionProviderInterface.php` | Client API (spec 002) | FR-CLIENT-005 |
| `Client/ClientIdempotencyStore.php` | Client API (spec 002) | FR-CLIENT-005 |
| `Client/ClientMinSupportedVersionProviderInterface.php` | Client API (spec 002) | FR-CLIENT-005 |
| `Client/ClientPrivacyNormalizer.php` | Client API (spec 002) | FR-CLIENT-005 |
| `Client/ClientTaskPresenter.php` | Client API (spec 002) | FR-CLIENT-005 |
| `Client/ClientTokenRevoker.php` | Client API (spec 002) | FR-CLIENT-005 |
| `Client/ClientUserDaysOffProviderInterface.php` | Client API (spec 002) | FR-CLIENT-005 |
| `Client/ClientUserDaysOffSourceInterface.php` | Client API (spec 002) | FR-CLIENT-005 |
| `Client/ClientUserLeave.php` | Client API (spec 002) | FR-CLIENT-005 |
| `Client/ClientUserLeaveProviderInterface.php` | Client API (spec 002) | FR-CLIENT-005 |
| `Client/ClientUserLocaleProviderInterface.php` | Client API (spec 002) | FR-CLIENT-005 |
| `Client/ClientUserLocaleSourceInterface.php` | Client API (spec 002) | FR-CLIENT-005 |
| `Client/ClientUserPrivacyProviderInterface.php` | Client API (spec 002) | FR-CLIENT-005 |
| `Client/ClientUserPrivacySourceInterface.php` | Client API (spec 002) | FR-CLIENT-005 |
| `Client/ClientUserProfileProviderInterface.php` | Client API (spec 002) | FR-CLIENT-005 |
| `Client/ClientUserProfileSourceInterface.php` | Client API (spec 002) | FR-CLIENT-005 |
| `Client/ClientWorkingHoursNormalizer.php` | Client API (spec 002) | FR-CLIENT-005 |
| `Client/ClientWorkingTimeContext.php` | Client API (spec 002) | FR-CLIENT-005 |
| `Client/ConfigClientApiSettingsProvider.php` | Client API (spec 002) | FR-CLIENT-005 |
| `Client/ConfigClientDistributionProvider.php` | Client API (spec 002) | FR-CLIENT-005 |
| `Client/ConfigClientMinSupportedVersionProvider.php` | Client API (spec 002) | FR-CLIENT-005 |
| `Client/NullClientUserProfileProvider.php` | Client API (spec 002) | FR-CLIENT-005 |
| `Client/OpaqueClientCredentialIssuer.php` | Client API (spec 002) | FR-CLIENT-005 |
| `Enum/AuditClient.php` | Client API (spec 002) | FR-CLIENT-005 |
| `Enum/ClientApiAuditAction.php` | Client API (spec 002) | FR-CLIENT-005 |
| `Enum/ClientScope.php` | Client API (spec 002) | FR-CLIENT-005 |
| `Resources/views/client_distribution/_downloads.html.twig` | Client API (spec 002) | FR-CLIENT-005 |

## Client API settings and idle check

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Client/IdleCheckSettings.php` | Client API settings and idle check | FR-CLIENT-006 |
| `Client/IdleCheckSettingsPolicyInterface.php` | Client API settings and idle check | FR-CLIENT-006 |
| `Client/IdleCheckSettingsProviderInterface.php` | Client API settings and idle check | FR-CLIENT-006 |
| `Client/IdleCheckSettingsResolver.php` | Client API settings and idle check | FR-CLIENT-006 |
| `Enum/IdleFollowUp.php` | Client API settings and idle check | FR-CLIENT-006 |
| `Enum/IdleResolution.php` | Client API settings and idle check | FR-CLIENT-006 |

## CLI

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Command/BackfillEntryScopeCommand.php` | CLI | FR-CLI-002 |
| `Command/CalendarSyncCommand.php` | CLI | FR-CLI-002 |

## Timesheets (period lock, approval)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Command/TimesheetsAutoLockCommand.php` | Timesheets (period lock, approval) | FR-TS-005 |
| `Command/TimesheetsPurgeCommand.php` | Timesheets (period lock, approval) | FR-TS-005 |
| `Command/TimesheetsVerifyCommand.php` | Timesheets (period lock, approval) | FR-TS-005 |
| `Entity/Timesheet.php` | Timesheets (period lock, approval) | FR-TS-001 |
| `Entity/TimesheetEvent.php` | Timesheets (period lock, approval) | FR-TS-001 |
| `Enum/TimesheetAction.php` | Timesheets (period lock, approval) | FR-TS-001 |
| `Enum/TimesheetActorRole.php` | Timesheets (period lock, approval) | FR-TS-001 |
| `Enum/TimesheetLockReason.php` | Timesheets (period lock, approval) | FR-TS-001 |
| `Enum/TimesheetPeriodKind.php` | Timesheets (period lock, approval) | FR-TS-001 |
| `Enum/TimesheetStatus.php` | Timesheets (period lock, approval) | FR-TS-001 |
| `Enum/WriteKind.php` | Timesheets (period lock, approval) | FR-TS-002 |
| `Event/PeriodLockRefusedEvent.php` | Timesheets (period lock, approval) | FR-TS-004 |
| `Event/TimesheetAccessCheckEvent.php` | Timesheets (period lock, approval) | FR-TS-004 |
| `Event/TimesheetLateEntryEvent.php` | Timesheets (period lock, approval) | FR-TS-004 |
| `Event/TimesheetTransitionEvent.php` | Timesheets (period lock, approval) | FR-TS-004 |
| `Exception/PeriodLockedException.php` | Timesheets (period lock, approval) | FR-TS-002 |
| `Exception/PeriodSubmittedException.php` | Timesheets (period lock, approval) | FR-TS-002 |
| `Exception/PeriodWriteRefusedException.php` | Timesheets (period lock, approval) | FR-TS-002 |
| `Exception/TimesheetException.php` | Timesheets (period lock, approval) | FR-TS-002 |
| `Form/TimesheetDecisionType.php` | Timesheets (period lock, approval) | FR-TS-003 |
| `Resources/views/components/_action.html.twig` | Timesheets (period lock, approval) | FR-TS-003 |
| `Resources/views/components/balance_card.html.twig` | Timesheets (period lock, approval) | FR-TS-003 |
| `Resources/views/components/ledger_table.html.twig` | Timesheets (period lock, approval) | FR-TS-003 |
| `Resources/views/components/timesheet_week_grid.html.twig` | Timesheets (period lock, approval) | FR-TS-003 |
| `Timesheet/AuditChainVerifier.php` | Timesheets (period lock, approval) | FR-TS-001 |
| `Timesheet/BlockedEditRecorderInterface.php` | Timesheets (period lock, approval) | FR-TS-001 |
| `Timesheet/Period.php` | Timesheets (period lock, approval) | FR-TS-001 |
| `Timesheet/PeriodLockGuard.php` | Timesheets (period lock, approval) | FR-TS-001 |
| `Timesheet/ScopeKeys.php` | Timesheets (period lock, approval) | FR-TS-001 |
| `Timesheet/SingleScopeProvider.php` | Timesheets (period lock, approval) | FR-TS-001 |
| `Timesheet/TimesheetAccessGuard.php` | Timesheets (period lock, approval) | FR-TS-001 |
| `Timesheet/TimesheetApproverPolicyInterface.php` | Timesheets (period lock, approval) | FR-TS-001 |
| `Timesheet/TimesheetApproverProviderInterface.php` | Timesheets (period lock, approval) | FR-TS-001 |
| `Timesheet/TimesheetAuditTrail.php` | Timesheets (period lock, approval) | FR-TS-001 |
| `Timesheet/TimesheetFormHandler.php` | Timesheets (period lock, approval) | FR-TS-001 |
| `Timesheet/TimesheetFormResult.php` | Timesheets (period lock, approval) | FR-TS-003 |
| `Ui/TimesheetDialogResponder.php` | Timesheets (period lock, approval) | FR-TS-003 |
| `Timesheet/TimesheetPersonalDataExporter.php` | Timesheets (period lock, approval) | FR-TS-001 |
| `Timesheet/TimesheetScope.php` | Timesheets (period lock, approval) | FR-TS-001 |
| `Timesheet/TimesheetScopePolicyInterface.php` | Timesheets (period lock, approval) | FR-TS-001 |
| `Timesheet/TimesheetScopeProviderInterface.php` | Timesheets (period lock, approval) | FR-TS-001 |
| `Timesheet/TimesheetService.php` | Timesheets (period lock, approval) | FR-TS-001 |
| `Timesheet/TimesheetSnapshot.php` | Timesheets (period lock, approval) | FR-TS-001 |
| `Timesheet/TimesheetSnapshotBuilder.php` | Timesheets (period lock, approval) | FR-TS-001 |
| `Timesheet/TimesheetTimezoneResolver.php` | Timesheets (period lock, approval) | FR-TS-001 |
| `Twig/TimeTrackComponentExtension.php` | Timesheets (period lock, approval) | FR-TS-003 |
| `Twig/TimeTrackComponentRuntime.php` | Timesheets (period lock, approval) | FR-TS-003 |
| `Ui/TimesheetViewBuilder.php` | Timesheets (period lock, approval) | FR-TS-003 |

## Device authorization

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Controller/TimeTrackClientDeviceController.php` | Device authorization | FR-DEV-002 |
| `Controller/TimeTrackDeviceVerificationController.php` | Device authorization | FR-DEV-002 |
| `Dto/DeviceCodeGrant.php` | Device authorization | FR-DEV-001 |
| `Dto/DevicePollResult.php` | Device authorization | FR-DEV-001 |
| `Entity/DeviceAuthorization.php` | Device authorization | FR-DEV-001 |
| `Enum/DeviceAuthorizationStatus.php` | Device authorization | FR-DEV-001 |
| `Exception/DeviceAuthorizationNotPendingException.php` | Device authorization | FR-DEV-001 |
| `Exception/DeviceCodeAttemptsExceededException.php` | Device authorization | FR-DEV-001 |
| `Exception/DeviceFlowCapacityExceededException.php` | Device authorization | FR-DEV-001 |
| `Form/DeviceDecisionType.php` | Device authorization | FR-DEV-002 |
| `Form/DeviceUserCodeType.php` | Device authorization | FR-DEV-002 |
| `Resources/views/time_track/device_code.html.twig` | Device authorization | FR-DEV-002 |
| `Resources/views/time_track/device_confirm.html.twig` | Device authorization | FR-DEV-002 |
| `Service/DeviceAuthorizationService.php` | Device authorization | FR-DEV-001, FR-CLIENT-007 |

## Meetings

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Controller/TimeTrackMeetingsApiController.php` | Meetings | FR-MEET-002 |
| `Meeting/MeetingAttendeeView.php` | Meetings | FR-MEET-001 |
| `Meeting/MeetingContext.php` | Meetings | FR-MEET-001 |
| `Meeting/MeetingLinkDetector.php` | Meetings | FR-MEET-001 |
| `Meeting/MeetingPage.php` | Meetings | FR-MEET-001 |
| `Meeting/MeetingProviderInterface.php` | Meetings | FR-MEET-001 |
| `Meeting/MeetingQuery.php` | Meetings | FR-MEET-001 |
| `Meeting/MeetingQueryParser.php` | Meetings | FR-MEET-001 |
| `Meeting/MeetingRequestException.php` | Meetings | FR-MEET-001 |
| `Meeting/MeetingResponder.php` | Meetings | FR-MEET-001 |
| `Meeting/MeetingSettings.php` | Meetings | FR-MEET-001 |
| `Meeting/MeetingSourceInterface.php` | Meetings | FR-MEET-001 |
| `Meeting/MeetingSyncState.php` | Meetings | FR-MEET-001 |
| `Meeting/MeetingSyncStatus.php` | Meetings | FR-MEET-001 |
| `Meeting/MeetingTaskLink.php` | Meetings | FR-MEET-001 |
| `Meeting/MeetingView.php` | Meetings | FR-MEET-001 |

## Monitoring (opt-in)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Controller/TimeTrackMonitoringApiController.php` | Monitoring (opt-in) | FR-MON-002 |
| `Entity/MonitoringAcknowledgementRecord.php` | Monitoring (opt-in) | FR-MON-001 |
| `Entity/MonitoringUploadIntent.php` | Monitoring (opt-in) | FR-MON-001 |
| `Event/MonitoringPersonDetailViewedEvent.php` | Monitoring (opt-in) | FR-MON-005 |
| `Event/MonitoringSpanDiscardedEvent.php` | Monitoring (opt-in) | FR-MON-003 |
| `Monitoring/ActivityItem.php` | Monitoring (opt-in) | FR-MON-001 |
| `Monitoring/HmacMonitoringUploadSigner.php` | Monitoring (opt-in) | FR-MON-001 |
| `Monitoring/MonitoringAcknowledgement.php` | Monitoring (opt-in) | FR-MON-001 |
| `Monitoring/MonitoringAcknowledgementStoreInterface.php` | Monitoring (opt-in) | FR-MON-001 |
| `Monitoring/MonitoringActivityBudget.php` | Monitoring (opt-in) | FR-MON-001 |
| `Monitoring/MonitoringContext.php` | Monitoring (opt-in) | FR-MON-001, FR-MON-004 |
| `Monitoring/MonitoringEvidenceRejectedException.php` | Monitoring (opt-in) | FR-MON-001 |
| `Monitoring/MonitoringEvidenceStoreInterface.php` | Monitoring (opt-in) | FR-MON-001 |
| `Monitoring/MonitoringGate.php` | Monitoring (opt-in) | FR-MON-001, FR-MON-006 |
| `Monitoring/MonitoringImageInspector.php` | Monitoring (opt-in) | FR-MON-001 |
| `Monitoring/MonitoringPolicy.php` | Monitoring (opt-in) | FR-MON-001, FR-MON-004, FR-MON-005 |
| `Monitoring/MonitoringPolicySourceInterface.php` | Monitoring (opt-in) | FR-MON-001 |
| `Monitoring/MonitoringSettings.php` | Monitoring (opt-in) | FR-MON-001 |
| `Monitoring/MonitoringSpanDiscardListener.php` | Monitoring (opt-in) | FR-MON-001 |
| `Monitoring/MonitoringTeamDetailDeniedException.php` | Monitoring (opt-in) | FR-MON-005 |
| `Monitoring/MonitoringTeamViewGuard.php` | Monitoring (opt-in) | FR-MON-005 |
| `Monitoring/MonitoringUploadClaim.php` | Monitoring (opt-in) | FR-MON-001 |
| `Monitoring/MonitoringUploadSignerInterface.php` | Monitoring (opt-in) | FR-MON-001 |
| `Monitoring/ScreenshotUpload.php` | Monitoring (opt-in) | FR-MON-001 |

## Working time and balances

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Controller/TimeTrackWorkingTimeApiController.php` | Working time and balances | FR-WT-004 |
| `Entity/BalanceLedgerLine.php` | Working time and balances | FR-WT-003 |
| `Enum/LedgerKind.php` | Working time and balances | FR-WT-003 |
| `Event/BalanceLedgerLineAddedEvent.php` | Working time and balances | FR-WT-003 |
| `Form/LedgerLineType.php` | Working time and balances | FR-WT-003 |
| `WorkingTime/BalanceLedger.php` | Working time and balances | FR-WT-001 |
| `WorkingTime/BalanceQuery.php` | Working time and balances | FR-WT-001 |
| `WorkingTime/BalanceReport.php` | Working time and balances | FR-WT-001 |
| `WorkingTime/CalendarDay.php` | Working time and balances | FR-WT-001 |
| `WorkingTime/CalendarDayKind.php` | Working time and balances | FR-WT-001 |
| `WorkingTime/CalendarDayNormalizer.php` | Working time and balances | FR-WT-001 |
| `WorkingTime/ConfigOvertimePolicy.php` | Working time and balances | FR-WT-001 |
| `WorkingTime/DerivedWorkingCalendar.php` | Working time and balances | FR-WT-001 |
| `WorkingTime/OvertimeInput.php` | Working time and balances | FR-WT-001 |
| `WorkingTime/OvertimePolicyInterface.php` | Working time and balances | FR-WT-001 |
| `WorkingTime/OvertimeResult.php` | Working time and balances | FR-WT-001 |
| `WorkingTime/TaskTimeWorkedTimeSource.php` | Working time and balances | FR-WT-001 |
| `WorkingTime/WorkedDay.php` | Working time and balances | FR-WT-001 |
| `WorkingTime/WorkedTimeRecordInterface.php` | Working time and balances | FR-WT-001 |
| `WorkingTime/WorkedTimeSourceInterface.php` | Working time and balances | FR-WT-001 |
| `WorkingTime/WorkingCalendarProviderInterface.php` | Working time and balances | FR-WT-001 |
| `WorkingTime/WorkingCalendarSourceInterface.php` | Working time and balances | FR-WT-001 |
| `WorkingTime/WorkingTimeBalanceCalculator.php` | Working time and balances | FR-WT-001 |
| `WorkingTime/WorkingTimeBalanceService.php` | Working time and balances | FR-WT-001 |

## Private entity manager

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `DependencyInjection/Compiler/PrivateEntityManagerPass.php` | Private entity manager | FR-PEM-003 |
| `Doctrine/PrivateEntityManagerConnection.php` | Private entity manager | FR-PEM-002 |
| `Doctrine/PrivateEntityManagerDriver.php` | Private entity manager | FR-PEM-002 |
| `Doctrine/PrivateEntityManagerMiddleware.php` | Private entity manager | FR-PEM-002 |
| `Doctrine/PrivateEntityManagerSubscriber.php` | Private entity manager | FR-PEM-002 |
| `Doctrine/TimeTrackEntityManager.php` | Private entity manager | FR-PEM-001 |
| `Doctrine/TimeTrackManagerRegistry.php` | Private entity manager | FR-PEM-001 |
| `Doctrine/TimeTrackRepositoryFactory.php` | Private entity manager | FR-PEM-001 |

## Framework-free ports

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `DependencyInjection/Compiler/TimeTrackPortsPass.php` | Framework-free ports | FR-PORT-003 |
| `Integration/BatchTaskDirectoryInterface.php` | Framework-free ports | FR-PORT-002 |
| `Integration/BatchTaskProviderInterface.php` | Framework-free ports | FR-PORT-002 |
| `Integration/NoServiceAccountPolicy.php` | Framework-free ports | FR-PORT-002 |
| `Integration/NullTimeEntryBillableDefaultProvider.php` | Framework-free ports | FR-PORT-002 |
| `Integration/PassThroughUserReferenceResolver.php` | Framework-free ports | FR-PORT-002 |
| `Integration/ServiceAccountPolicyInterface.php` | Framework-free ports | FR-PORT-002 |
| `Integration/TaskDirectoryInterface.php` | Framework-free ports | FR-PORT-002 |
| `Integration/TeamDirectoryInterface.php` | Framework-free ports | FR-PORT-002 |
| `Integration/TimeEntryBillableDefaultProviderInterface.php` | Framework-free ports | FR-PORT-002 |
| `Integration/UserReferenceProviderInterface.php` | Framework-free ports | FR-PORT-002 |
| `Integration/UserReferenceResolverInterface.php` | Framework-free ports | FR-PORT-002 |
| `Internal/HiddenValues.php` | Framework-free ports | FR-PORT-002 |
| `Port/BatchTaskDirectoryGate.php` | Framework-free ports | FR-PORT-001 |
| `Port/CalendarCredentialSourceGate.php` | Framework-free ports | FR-PORT-001 |
| `Port/ClientCredentialMinterGate.php` | Framework-free ports | FR-PORT-001 |
| `Port/ClientUserDaysOffSourceGate.php` | Framework-free ports | FR-PORT-001 |
| `Port/ClientUserLocaleSourceGate.php` | Framework-free ports | FR-PORT-001 |
| `Port/ClientUserPrivacySourceGate.php` | Framework-free ports | FR-PORT-001 |
| `Port/ClientUserProfileSourceGate.php` | Framework-free ports | FR-PORT-001 |
| `Port/EntryOwnerRefsGate.php` | Framework-free ports | FR-PORT-001 |
| `Port/IdleCheckSettingsPolicyGate.php` | Framework-free ports | FR-PORT-001 |
| `Port/MeetingSourceGate.php` | Framework-free ports | FR-PORT-001 |
| `Port/Ports.php` | Framework-free ports | FR-PORT-001 |
| `Port/TaskAccessPolicyGate.php` | Framework-free ports | FR-PORT-001 |
| `Port/TaskDirectoryGate.php` | Framework-free ports | FR-PORT-001 |
| `Port/TaskKeyLookupGate.php` | Framework-free ports | FR-PORT-001 |
| `Port/TeamDirectoryGate.php` | Framework-free ports | FR-PORT-001 |
| `Port/TimeTrackUserRefFactory.php` | Framework-free ports | FR-PORT-001 |
| `Port/TimeTrackUserRefs.php` | Framework-free ports | FR-PORT-001 |
| `Port/TimesheetApproverPolicyGate.php` | Framework-free ports | FR-PORT-001 |
| `Port/TimesheetScopePolicyGate.php` | Framework-free ports | FR-PORT-001 |
| `Port/UserReferenceProviderGate.php` | Framework-free ports | FR-PORT-001 |
| `Port/WorkdaySourceGate.php` | Framework-free ports | FR-PORT-001 |
| `Port/WorkedTimeRecordGate.php` | Framework-free ports | FR-PORT-001 |
| `Port/WorkingCalendarSourceGate.php` | Framework-free ports | FR-PORT-001 |
| `ValueObject/CalendarConnectionRef.php` | Framework-free ports | FR-PORT-002 |
| `ValueObject/TimeTrackUserRef.php` | Framework-free ports | FR-PORT-002 |

## Host users and user references

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Doctrine/DoctrineUserReferenceResolver.php` | Host users and user references | FR-USER-001 |
| `Resources/config/doctrine-host-users/HostUser.orm.xml` | Host users and user references | FR-USER-001 |
| `UserRef/Entity/HostUser.php` | Host users and user references | FR-USER-001 |
| `UserRef/ExactUserIds.php` | Host users and user references | FR-USER-001 |
| `UserRef/HostUserRows.php` | Host users and user references | FR-USER-001 |
| `UserRef/UserMapping.php` | Host users and user references | FR-USER-001 |

## Retention and former people

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Doctrine/FormerPersonSnapshotListener.php` | Retention and former people | FR-RET-001 |
| `Retention/FormerPersonTimeEntries.php` | Retention and former people | FR-RET-001 |
| `Retention/TimeEntryRetentionPolicy.php` | Retention and former people | FR-RET-001 |

## Transactions, locks and savepoints

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Doctrine/TimeTrackRows.php` | Transactions, locks and savepoints | FR-TX-002 |
| `Doctrine/TimeTrackSavepoint.php` | Transactions, locks and savepoints | FR-TX-002 |
| `Doctrine/UnitOfWorkBoundaries.php` | Transactions, locks and savepoints | FR-TX-002 |
| `EventSubscriber/StorageFailureSubscriber.php` | Transactions, locks and savepoints | FR-TX-004 |
| `EventSubscriber/StorageFailureConflictFormatter.php` | Transactions, locks and savepoints | FR-TX-004 |
| `Transaction/TimeTrackFlushObserver.php` | Transactions, locks and savepoints | FR-TX-001 |
| `Transaction/TimeTrackTransactionRunner.php` | Transactions, locks and savepoints | FR-TX-001 |

## Domain services

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Dto/BackdateLimits.php` | Domain services | FR-DTO-003 |
| `Dto/ClampedTime.php` | Domain services | FR-DTO-003 |
| `Dto/TimeEntryView.php` | Domain services | FR-DTO-003 |
| `Service/ManualEntryService.php` | Domain services | FR-SVC-004 |
| `Service/TimeEntryBillableService.php` | Domain services | FR-SVC-004 |

## Events

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Event/ClientApiEvent.php` | Events | FR-EVT-004 |
| `Event/IdleResolvedEvent.php` | Events | FR-EVT-004 |
| `Event/TaskEffortAccessCheckEvent.php` | Events | FR-EVT-004 |
| `Event/TaskTimeMovedEvent.php` | Events | FR-EVT-004 |
| `Event/TimeEntriesMovedEvent.php` | Events | FR-EVT-004 |
| `Event/TimeEntriesRemovedEvent.php` | Events | FR-EVT-004 |
| `Event/TimeEntryBillableChangedEvent.php` | Events | FR-EVT-004 |
| `Event/TimeEntryCreatedEvent.php` | Events | FR-EVT-004 |
| `Event/TimeEntryImportedEvent.php` | Events | FR-EVT-004 |

## Exceptions

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Exception/BillableChangeRefusedException.php` | Exceptions | FR-EXC-002 |
| `Exception/IdempotentReplayException.php` | Exceptions | FR-EXC-002 |
| `Exception/LedgerRefusedException.php` | Exceptions | FR-EXC-002 |
| `Exception/ManualEntryException.php` | Exceptions | FR-EXC-002 |
| `Exception/TaskNotTrackableException.php` | Exceptions | FR-EXC-002 |

## Imported time entries

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Import/ImportedDescriptionSanitizer.php` | Imported time entries | FR-IMP-001 |
| `Import/ImportedEntryOwnerMismatchException.php` | Imported time entries | FR-IMP-001 |
| `Import/ImportedTimeEntryBatchResult.php` | Imported time entries | FR-IMP-001 |
| `Import/ImportedTimeEntryClaim.php` | Imported time entries | FR-IMP-001 |
| `Import/ImportedTimeEntryData.php` | Imported time entries | FR-IMP-001 |
| `Import/ImportedTimeEntryDeleter.php` | Imported time entries | FR-IMP-001 |
| `Import/ImportedTimeEntryMoveResult.php` | Imported time entries | FR-IMP-001 |
| `Import/ImportedTimeEntryOutcome.php` | Imported time entries | FR-IMP-001 |
| `Import/ImportedTimeEntryPerson.php` | Imported time entries | FR-IMP-001 |
| `Import/ImportedTimeEntryRemoval.php` | Imported time entries | FR-IMP-001 |
| `Import/ImportedTimeEntryResult.php` | Imported time entries | FR-IMP-001 |
| `Import/ImportedTimeEntryScope.php` | Imported time entries | FR-IMP-001 |
| `Import/ImportedTimeEntryWriter.php` | Imported time entries | FR-IMP-001 |
| `Import/InvalidImportedTimeEntryException.php` | Imported time entries | FR-IMP-001 |

## Repositories

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Repository/BalanceLedgerRepositoryInterface.php` | Repositories | FR-REPO-004 |
| `Repository/CalendarConnectionRepositoryInterface.php` | Repositories | FR-REPO-004 |
| `Repository/CalendarRuleRepositoryInterface.php` | Repositories | FR-REPO-004 |
| `Repository/CalendarSuggestionRepositoryInterface.php` | Repositories | FR-REPO-004 |
| `Repository/ConditionalActiveTimerRepositoryInterface.php` | Repositories | FR-REPO-004 |
| `Repository/CredentialClientTokenRepositoryInterface.php` | Repositories | FR-REPO-004 |
| `Repository/DeviceAuthorizationRepositoryInterface.php` | Repositories | FR-REPO-004 |
| `Repository/DoctrineOrmBalanceLedgerRepository.php` | Repositories | FR-REPO-004 |
| `Repository/DoctrineOrmCalendarRepository.php` | Repositories | FR-REPO-004 |
| `Repository/DoctrineOrmDeviceAuthorizationRepository.php` | Repositories | FR-REPO-004 |
| `Repository/DoctrineOrmMonitoringAcknowledgementStore.php` | Repositories | FR-REPO-004 |
| `Repository/DoctrineOrmMonitoringUploadIntentRepository.php` | Repositories | FR-REPO-004 |
| `Repository/DoctrineOrmTimesheetEventRepository.php` | Repositories | FR-REPO-004 |
| `Repository/DoctrineOrmTimesheetRepository.php` | Repositories | FR-REPO-004 |
| `Repository/EntryOwnerRefsRepositoryInterface.php` | Repositories | FR-REPO-004 |
| `Repository/EntryOwnersRepositoryInterface.php` | Repositories | FR-REPO-004 |
| `Repository/FilteredImportedTimeEntryRepositoryInterface.php` | Repositories | FR-REPO-004 |
| `Repository/FilteredImportedTimeEntryStoreInterface.php` | Repositories | FR-REPO-004 |
| `Repository/FindableTimeEntryRepositoryInterface.php` | Repositories | FR-REPO-004 |
| `Repository/FormerOwnerTimesheetRepositoryInterface.php` | Repositories | FR-REPO-004 |
| `Repository/FormerPersonTimeEntryRepositoryInterface.php` | Repositories | FR-REPO-004 |
| `Repository/ImportedTimeEntryOwnerRepositoryInterface.php` | Repositories | FR-REPO-004 |
| `Repository/ImportedTimeEntryRepositoryInterface.php` | Repositories | FR-REPO-004 |
| `Repository/ImportedTimeEntryRepositoryResolver.php` | Repositories | FR-REPO-004 |
| `Repository/ImportedTimeEntryStoreInterface.php` | Repositories | FR-REPO-004 |
| `Repository/LatestTimeEntryRepositoryInterface.php` | Repositories | FR-REPO-004 |
| `Repository/LockingFindableTimeEntryRepositoryInterface.php` | Repositories | FR-REPO-004 |
| `Repository/LockingGuardTimesheetRepositoryInterface.php` | Repositories | FR-REPO-004 |
| `Repository/MonitoringUploadIntentRepositoryInterface.php` | Repositories | FR-REPO-004 |
| `Repository/OriginFilteredTimeEntryRepositoryInterface.php` | Repositories | FR-REPO-004 |
| `Repository/OverlappingTimeEntryRepositoryInterface.php` | Repositories | FR-REPO-004 |
| `Repository/ScopeBackfillRepositoryInterface.php` | Repositories | FR-REPO-004 |
| `Repository/TaskEffortRepositoryInterface.php` | Repositories | FR-REPO-004 |
| `Repository/TaskTimeMoveRepositoryInterface.php` | Repositories | FR-REPO-004 |
| `Repository/TaskTimerMoveRepositoryInterface.php` | Repositories | FR-REPO-004 |
| `Repository/TimeEntryOriginFilter.php` | Repositories | FR-REPO-004 |
| `Repository/TimesheetEventRepositoryInterface.php` | Repositories | FR-REPO-004 |
| `Repository/TimesheetRepositoryInterface.php` | Repositories | FR-REPO-004 |
| `Repository/TimesheetRetentionRepositoryInterface.php` | Repositories | FR-REPO-004 |
| `Repository/UserSerialisedTimeEntryRepositoryInterface.php` | Repositories | FR-REPO-004 |

## XML mapping (DDD hosts)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/config/doctrine/ActiveTimer.orm.xml` | XML mapping (DDD hosts) | FR-ORM-002 |
| `Resources/config/doctrine/BalanceLedgerLine.orm.xml` | XML mapping (DDD hosts) | FR-ORM-002 |
| `Resources/config/doctrine/CalendarConnection.orm.xml` | XML mapping (DDD hosts) | FR-ORM-002 |
| `Resources/config/doctrine/CalendarRule.orm.xml` | XML mapping (DDD hosts) | FR-ORM-002 |
| `Resources/config/doctrine/CalendarSuggestion.orm.xml` | XML mapping (DDD hosts) | FR-ORM-002 |
| `Resources/config/doctrine/ClientToken.orm.xml` | XML mapping (DDD hosts) | FR-ORM-002 |
| `Resources/config/doctrine/DeviceAuthorization.orm.xml` | XML mapping (DDD hosts) | FR-ORM-002 |
| `Resources/config/doctrine/MonitoringAcknowledgementRecord.orm.xml` | XML mapping (DDD hosts) | FR-ORM-002 |
| `Resources/config/doctrine/MonitoringUploadIntent.orm.xml` | XML mapping (DDD hosts) | FR-ORM-002 |
| `Resources/config/doctrine/TimeEntry.orm.xml` | XML mapping (DDD hosts) | FR-ORM-002 |
| `Resources/config/doctrine/Timesheet.orm.xml` | XML mapping (DDD hosts) | FR-ORM-002 |
| `Resources/config/doctrine/TimesheetEvent.orm.xml` | XML mapping (DDD hosts) | FR-ORM-002 |

## Task access

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Security/TaskAccessCheckerInterface.php` | Task access | FR-SEC-002 |
| `Security/TaskAccessPolicyInterface.php` | Task access | FR-SEC-002 |
| `Security/TaskProviderTaskAccessChecker.php` | Task access | FR-SEC-002 |

## Support helpers

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Support/CanonicalJson.php` | Support helpers | FR-SUP-003 |
| `Support/IpPrefix.php` | Support helpers | FR-SUP-003 |
| `Support/NoteSanitizer.php` | Support helpers | FR-SUP-003 |
| `Support/Rfc3339.php` | Support helpers | FR-SUP-003 |
| `Support/StorageTime.php` | Support helpers | FR-SUP-003 |
| `Support/TimeEntryCsv.php` | Support helpers | FR-SUP-003 |

## Task time move

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `TaskMove/TaskTimeKeptReason.php` | Task time move | FR-MOVE-001 |
| `TaskMove/TaskTimeMoveEventException.php` | Task time move | FR-MOVE-001 |
| `TaskMove/TaskTimeMoveResult.php` | Task time move | FR-MOVE-001 |
| `TaskMove/TaskTimeMover.php` | Task time move | FR-MOVE-001 |

## Host timer API

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Timer/HostTimerApi.php` | Host timer API | FR-TIMER-001 |
| `Timer/HostTimerStart.php` | Host timer API | FR-TIMER-001 |
| `Timer/TimerChannel.php` | Host timer API | FR-TIMER-001 |
| `Timer/TimerEntrySnapshot.php` | Host timer API | FR-TIMER-001 |
| `Timer/TimerSnapshot.php` | Host timer API | FR-TIMER-001 |

## Workday

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Workday/WorkdayAction.php` | Workday | FR-WDAY-001 |
| `Workday/WorkdayConflictException.php` | Workday | FR-WDAY-001 |
| `Workday/WorkdayForbiddenException.php` | Workday | FR-WDAY-001 |
| `Workday/WorkdayHistorySourceInterface.php` | Workday | FR-WDAY-001, FR-MON-006 |
| `Workday/WorkdayProviderInterface.php` | Workday | FR-WDAY-001 |
| `Workday/WorkdaySourceInterface.php` | Workday | FR-WDAY-001 |
| `Workday/WorkdayState.php` | Workday | FR-WDAY-001 |

## Id-based access, read ports, personal data and client sessions (1.33-1.37)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Access/TimeEntryAccessInterface.php` | Entry access by ids (port) | FR-ACC-001 |
| `Access/TimeEntryAccess.php` | Default entry access by ids | FR-ACC-001 |
| `Access/ExactTimeEntryLookup.php` | One entry by exact id, id re-checked with `===` | FR-ACC-001 |
| `Access/IdActor.php` | Stand-in actor for an id-based check | FR-ACC-001 |
| `Event/TimeEntryIdAccessCheckEvent.php` | Ids-only twin of the entry access event | FR-ACC-001 |
| `Query/TimeEntryQueryInterface.php` | Time entry read port | FR-QRY-001 |
| `Query/TimeEntryCriteria.php` | Keyed filters of a listing | FR-QRY-001 |
| `Query/TimeEntrySort.php` | Listing order ending on the entry id | FR-QRY-001 |
| `Query/TimeEntryRecord.php` | One entry as the read port returns it | FR-QRY-001 |
| `Query/RunningTimerQueryInterface.php` | Running timer read port | FR-QRY-001 |
| `Query/RunningTimerView.php` | A running timer with owner and heartbeat | FR-QRY-001 |
| `Query/ClientAccessQueryInterface.php` | Client sessions and device sign-ins read port | FR-QRY-001 |
| `Query/ClientSessionView.php` | One client session without its token hash | FR-QRY-001 |
| `Query/DeviceSignInView.php` | One device sign-in without its codes | FR-QRY-001 |
| `Query/DoctrineTimeTrackQuery.php` | Doctrine implementation of the three read ports | FR-QRY-001 |
| `Repository/TasklessScopedTimeEntryRepositoryInterface.php` | Store capability: taskless entries kept in the viewer's tenant scopes | FR-QRY-002 |
| `Repository/TimeEntryListing.php` | One scoped listing | FR-QRY-002 |
| `PersonalData/TimeTrackPersonalDataInterface.php` | GDPR export and erasure by user id (port) | FR-PD-001 |
| `PersonalData/DoctrineTimeTrackPersonalData.php` | Doctrine implementation of the personal data port | FR-PD-001 |
| `PersonalData/TimeEntryErasure.php` | What erasure does to the user's entries | FR-PD-001 |
| `PersonalData/TimeTrackErasureResult.php` | Erasure counts | FR-PD-001 |
| `Exception/PersonalDataErasureRefusedException.php` | Erasure refused with nothing written | FR-PD-001 |
| `Doctrine/OwnedRows.php` | Rows a user owns, matched byte for byte | FR-PD-002 |
| `Doctrine/OwnerIdColumn.php` | Whether a user id fits the owner column | FR-PD-002 |
| `Repository/ClientSessionRepositoryInterface.php` | One client session by id | FR-CLIENT-008 |
| `Repository/UserClientTokenRepositoryInterface.php` | Bulk revocation of one user's client sessions | FR-CLIENT-008 |
| `Dto/ClientSessionRecord.php` | Client session scalars for revocation by id | FR-CLIENT-008 |
| `ValueObject/ClientSessionId.php` | Client session id (lower-cased UUID) | FR-CLIENT-008 |
| `Enum/ClientSessionRevocation.php` | Result of revocation by id | FR-CLIENT-008 |
| `Event/ClientSessionAccessCheckEvent.php` | Grant or deny a client session revocation | FR-CLIENT-008 |
| `Event/TimerStartedWithoutTaskEvent.php` | A timer without a task was started | FR-EVT-005 |
| `Doctrine/TimeTrackHostFlushProbe.php` | Host flush migration list (shared manager mode) | FR-PEM-004 |

## Writes by id (1.40)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Entry/TimeEntryOperationsInterface.php` | Entry writes by ids (port) | FR-OPS-001 |
| `Entry/TimeEntryOperations.php` | Default entry writes by ids | FR-OPS-001 |
| `Entry/TimeEntryCorrection.php` | What a correction changes (VO) | FR-OPS-001 |
| `Entry/TimeEntryCorrectionResult.php` | Before / after of a correction (VO) | FR-OPS-001 |
| `Entry/ManualTimeEntryDraft.php` | A manual entry to create by owner id (VO) | FR-OPS-001 |
| `Exception/TimeEntryCorrectionException.php` | Refusals of a correction | FR-OPS-001 |
| `Event/TimeEntryCorrectedEvent.php` | An entry was corrected (read models) | FR-OPS-001 |
| `Support/EntryOverlap.php` | Overlap with live entries and the running timer | FR-OPS-001 |
| `Client/ClientSessionRevokerInterface.php` | Client sessions revoked by ids (port) | FR-OPS-002 |
| `Entry/EntryMetadataRules.php` | Metadata and age rules of the writes by id (1.40.1) | FR-OPS-001 |
| `Repository/LockingOverlappingTimeEntryRepositoryInterface.php` | Overlap query under a shared lock (1.40.1) | FR-OPS-001 |
| `Repository/SharedLockGuardTimesheetRepositoryInterface.php` | Period-lock query under a shared lock (1.40.1) | FR-OPS-001 |

## Coverage summary

Every file tracked under `src/` (PHP classes, Twig views, XML mapping, YAML config, translations) has one row above.

| Category | Files | Mapped |
| --- | ---: | ---: |
| PHP classes (`src/**/*.php`) | 480 | 480 |
| Other production files (Twig, XML mapping, YAML, translations) | 34 | 34 |
| **Total production sources** | **514** | **514** |

Audit command: `git ls-files src | wc -l` → 514 at 1.41.0 (refreshed with the monitoring privacy decisions D4, D9,
D11; the 1.38–1.39 files are mapped too).
