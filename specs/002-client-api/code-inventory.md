# Code inventory — 002-client-api (100% of `src/`)

**Spec**: [`spec.md`](spec.md) · **Baseline**: [`../001-baseline/code-inventory.md`](../001-baseline/code-inventory.md)  
**Last audited**: 2026-11-02 (TimeTrackBundle 1.9.1 unreleased, client API contract v0.2)

Every file under `src/` is listed: `new` / `changed` files map to this spec; `unchanged` files keep their baseline
requirement ids.

| Source file | State | Role in 1.9 | Requirement IDs |
| --- | --- | --- | --- |
| `Bridge/NullTeamContextProvider.php` | unchanged | Unchanged (see 001-baseline) | — |
| `Bridge/StubTaskProvider.php` | unchanged | Unchanged (see 001-baseline) | — |
| `Client/AlwaysActiveClientCredentialState.php` | new | Default credential state | FR-API-002 |
| `Client/ClientApiAuditor.php` | new | Audit trail | FR-API-010 |
| `Client/ClientApiGuard.php` | new | Request checks (IP limit, token, credential state, token limit, scope) | FR-API-001, FR-API-002, FR-API-003, FR-API-008, FR-API-009 |
| `Client/ClientApiPrincipal.php` | new | Authenticated caller | FR-API-001 |
| `Client/ClientApiRateLimiter.php` | new | Fixed-window counters | FR-API-009 |
| `Client/ClientApiSettings.php` | new | Instance settings DTO | FR-API-011 |
| `Client/ClientApiSettingsProviderInterface.php` | new | Settings provider (host) | FR-API-011 |
| `Client/ClientAuthResult.php` | changed | Login result + credential + scopes (BC) | FR-API-001, FR-API-013 |
| `Client/ClientAuthenticatorInterface.php` | unchanged | Unchanged (see 001-baseline) | — |
| `Client/ClientCredentialIssuerInterface.php` | new | Device credential issuer (host) | FR-API-007 |
| `Client/ClientCredentialStateInterface.php` | new | Per-request credential state (host) | FR-API-002 |
| `Client/ClientDistribution.php` | new | Download links DTO (safe URLs) | FR-API-012 |
| `Client/ClientDistributionProviderInterface.php` | new | Distribution provider (host) | FR-API-012 |
| `Client/ClientLoginRateLimiter.php` | changed | IPv6 /64 keys, per-username bucket | FR-API-009 |
| `Client/ClientMinSupportedVersionProviderInterface.php` | new | me.minSupportedVersion provider (host) — 1.9.1 | FR-API-015 |
| `Client/ClientResponseFactory.php` | changed | JSON / error responses, exact-origin CORS | FR-API-008 |
| `Client/ClientTokenRevoker.php` | new | Revoke by credential | FR-API-002 |
| `Client/ClientUserProfileProviderInterface.php` | new | me.displayName / workingHours (host) | FR-API-004 |
| `Client/ClientUserDaysOffProviderInterface.php` | new (1.12) | me.daysOff (host, optional extra of the profile provider) | FR-API-017 |
| `Client/ClientDaysOffNormalizer.php` | new (1.12) | strict validation of me.daysOff | FR-API-017 |
| `Client/ClientWorkingHoursNormalizer.php` | new (1.12) | me.workingHours `days` / `slots` → `slots` | FR-API-017 |
| `Client/ConfigClientApiSettingsProvider.php` | new | Settings from config | FR-API-011 |
| `Client/ConfigClientDistributionProvider.php` | new | Distribution from config | FR-API-012 |
| `Client/ConfigClientMinSupportedVersionProvider.php` | new | Min supported versions from config — 1.9.1 | FR-API-015 |
| `Client/DefaultClientAuthenticator.php` | unchanged | Unchanged (see 001-baseline) | — |
| `Client/NullClientUserProfileProvider.php` | new | Default profile provider | FR-API-004 |
| `Client/OpaqueClientCredentialIssuer.php` | new | Default device credential issuer | FR-API-007 |
| `Command/PurgeExpiredClientTokensCommand.php` | changed | Purge tokens + device codes | FR-API-007 |
| `Controller/TimeTrackClientApiController.php` | changed | Client API endpoints (1.9.1: me.minSupportedVersion) | FR-API-001..006, FR-API-014, FR-API-015 |
| `Controller/TimeTrackClientDeviceController.php` | new | device/code, device/token | FR-API-007 |
| `Controller/TimeTrackDeviceVerificationController.php` | new | Default verification page | FR-API-007 |
| `Controller/TimeTrackManageController.php` | unchanged | Unchanged (see 001-baseline) | — |
| `DependencyInjection/Compiler/TwigPathsPass.php` | unchanged | Unchanged (see 001-baseline) | — |
| `DependencyInjection/Configuration.php` | changed | client_api.*, client_distribution.* (1.9.1: min_supported_version), routes | FR-API-011, FR-API-012, FR-API-015 |
| `DependencyInjection/TimeTrackExtension.php` | changed | Client API wiring | FR-API-011, FR-API-015 |
| `Doctrine/ManagedEntityRefresher.php` | unchanged | Unchanged (see 001-baseline) | — |
| `Doctrine/RecoveringFlusher.php` | unchanged | Unchanged (see 001-baseline) | — |
| `Doctrine/TimeTrackMetadataListener.php` | changed | Device codes table prefix | FR-API-007 |
| `Dto/DeviceCodeGrant.php` | new | device/code answer | FR-API-007 |
| `Dto/DevicePollResult.php` | new | device/token answer | FR-API-007 |
| `Dto/TaskListQuery.php` | unchanged | Unchanged (see 001-baseline) | — |
| `Dto/TaskReference.php` | unchanged | Unchanged (see 001-baseline) | — |
| `Dto/TimeEntryPage.php` | unchanged | Unchanged (see 001-baseline) | — |
| `Entity/ActiveTimer.php` | changed | touchHeartbeat (no idle signal) | FR-API-006 |
| `Entity/ClientToken.php` | changed | credentialId, scopes, issuedAt | FR-API-001, FR-API-003 |
| `Entity/DeviceAuthorization.php` | new | Hashed single-use device request | FR-API-007 |
| `Entity/TimeEntry.php` | unchanged | Unchanged (see 001-baseline) | — |
| `Enum/ClientApiAuditAction.php` | new | Audit actions | FR-API-010 |
| `Enum/ClientScope.php` | new | Scopes | FR-API-003 |
| `Enum/ClientType.php` | unchanged | Unchanged (see 001-baseline) | — |
| `Enum/DeviceAuthorizationStatus.php` | new | Device request states | FR-API-007 |
| `Enum/IdleResolution.php` | new | keep / discard / split | FR-API-005 |
| `Enum/TimeEntrySource.php` | unchanged | Unchanged (see 001-baseline) | — |
| `Event/ClientApiEvent.php` | new | Audit event | FR-API-010 |
| `Event/TimeEntryAccessCheckEvent.php` | unchanged | Unchanged (see 001-baseline) | — |
| `Event/TimeEntryListQueryEvent.php` | unchanged | Unchanged (see 001-baseline) | — |
| `Event/TimeTrackEvents.php` | changed | CLIENT_API event name | FR-API-010 |
| `Event/TimerStartEvent.php` | unchanged | Unchanged (see 001-baseline) | — |
| `Event/TimerStopEvent.php` | unchanged | Unchanged (see 001-baseline) | — |
| `Exception/ActiveTimerConflictException.php` | unchanged | Unchanged (see 001-baseline) | — |
| `Exception/DeviceCodeAttemptsExceededException.php` | new | User-code lookup throttle | FR-API-007 |
| `Exception/DeviceFlowCapacityExceededException.php` | new | device/code caps (per prefix, total) | FR-API-007, FR-API-009 |
| `Form/DeviceDecisionType.php` | new | Approve / deny form (CSRF per action + request) | FR-API-007 |
| `Form/DeviceUserCodeType.php` | new | User code form | FR-API-007 |
| `Form/TimerActionType.php` | unchanged | Unchanged (see 001-baseline) | — |
| `Integration/TaskProviderInterface.php` | unchanged | Unchanged (see 001-baseline) | — |
| `Integration/TeamContextProviderInterface.php` | unchanged | Unchanged (see 001-baseline) | — |
| `Repository/ActiveTimerRepositoryInterface.php` | unchanged | Unchanged (see 001-baseline) | — |
| `Repository/ClientTokenRepositoryInterface.php` | unchanged | Unchanged (see 001-baseline) | — |
| `Repository/ConditionalActiveTimerRepositoryInterface.php` | new | Conditional timer removal (atomic stop) — 1.9.1 | FR-API-016 |
| `Repository/CredentialClientTokenRepositoryInterface.php` | new | Bulk revoke by credential | FR-API-002 |
| `Repository/DeviceAuthorizationRepositoryInterface.php` | new | Device request store | FR-API-007 |
| `Repository/DoctrineOrmActiveTimerRepository.php` | changed | removeIfPresent: conditional DELETE in a transaction — 1.9.1 | FR-API-016 |
| `Repository/DoctrineOrmClientTokenRepository.php` | changed | removeByCredentialId | FR-API-002 |
| `Repository/DoctrineOrmDeviceAuthorizationRepository.php` | new | Doctrine device store (atomic consume) | FR-API-007 |
| `Repository/DoctrineOrmTimeEntryRepository.php` | unchanged | Unchanged (see 001-baseline) | — |
| `Repository/PaginatedTimeEntryRepositoryInterface.php` | unchanged | Unchanged (see 001-baseline) | — |
| `Repository/TimeEntryRepositoryInterface.php` | unchanged | Unchanged (see 001-baseline) | — |
| `Resources/config/services.yaml` | changed | Service scan exclusions, public revoker | FR-API-011 |
| `Resources/translations/NowoTimeTrackBundle.de.yaml` | changed | Device page + downloads keys (7 locales) | FR-API-012, FR-API-007 |
| `Resources/translations/NowoTimeTrackBundle.en.yaml` | changed | Device page + downloads keys (7 locales) | FR-API-012, FR-API-007 |
| `Resources/translations/NowoTimeTrackBundle.es.yaml` | changed | Device page + downloads keys (7 locales) | FR-API-012, FR-API-007 |
| `Resources/translations/NowoTimeTrackBundle.fr.yaml` | changed | Device page + downloads keys (7 locales) | FR-API-012, FR-API-007 |
| `Resources/translations/NowoTimeTrackBundle.it.yaml` | changed | Device page + downloads keys (7 locales) | FR-API-012, FR-API-007 |
| `Resources/translations/NowoTimeTrackBundle.nl.yaml` | changed | Device page + downloads keys (7 locales) | FR-API-012, FR-API-007 |
| `Resources/translations/NowoTimeTrackBundle.pt.yaml` | changed | Device page + downloads keys (7 locales) | FR-API-012, FR-API-007 |
| `Resources/views/_legacy_layout.html.twig` | unchanged | Unchanged (see 001-baseline) | — |
| `Resources/views/client_distribution/_downloads.html.twig` | new | Downloads partial (UiKit, plain anchors) | FR-API-012 |
| `Resources/views/layout.html.twig` | unchanged | Unchanged (see 001-baseline) | — |
| `Resources/views/time_track/device_code.html.twig` | new | Device page step 1 (UiKit form scaffold) | FR-API-007 |
| `Resources/views/time_track/device_confirm.html.twig` | new | Device page steps 2–3 (UiKit show scaffold) | FR-API-007 |
| `Resources/views/time_track/index.html.twig` | unchanged | Unchanged (see 001-baseline) | — |
| `Resources/views/time_track/reports.html.twig` | unchanged | Unchanged (see 001-baseline) | — |
| `Routing/TimeTrackRouteLoader.php` | changed | New API routes, device routes opt-in | FR-API-005, FR-API-007 |
| `Security/AllowAllTimeTrackAccessChecker.php` | unchanged | Unchanged (see 001-baseline) | — |
| `Security/ConfigurableTimeTrackAccessChecker.php` | unchanged | Unchanged (see 001-baseline) | — |
| `Security/TaskAccessCheckerInterface.php` | new | Per-request task check (host) | FR-API-005 |
| `Security/TaskProviderTaskAccessChecker.php` | new | Default task check, deny by default | FR-API-005 |
| `Security/TimeTrackAccessCheckerInterface.php` | unchanged | Unchanged (see 001-baseline) | — |
| `Service/ClientAuthService.php` | changed | authenticate / issueToken / resolveToken | FR-API-001 |
| `Service/DeviceAuthorizationService.php` | new | Device flow logic | FR-API-007 |
| `Service/TeamAccessGuard.php` | unchanged | Unchanged (see 001-baseline) | — |
| `Service/TimerService.php` | changed | switchTo, resolveIdle, listOwnEntries, keepAlive, stop note, atomic stop (1.9.1) | FR-API-005, FR-API-006, FR-API-016 |
| `Support/IpPrefix.php` | new | IP /24 /64 truncation, rate-limit keys | FR-API-009, FR-API-010 |
| `Support/QueryPeriodResolver.php` | unchanged | Unchanged (see 001-baseline) | — |
| `Support/TimeEntryCsv.php` | new | CSV export (formula-safe) | FR-API-006 |
| `Support/UserIdResolver.php` | unchanged | Unchanged (see 001-baseline) | — |
| `TimeTrackBundle.php` | unchanged | Unchanged (see 001-baseline) | — |
| `Twig/TimeTrackTwigExtension.php` | changed | Distribution Twig functions | FR-API-012 |
| `Ui/TimeTrackPages.php` | changed | Device page view models | FR-API-007 |
| `ValueObject/Uuid.php` | unchanged | Unchanged (see 001-baseline) | — |

**Files**: 107 (42 new, 25 changed, 40 unchanged).
