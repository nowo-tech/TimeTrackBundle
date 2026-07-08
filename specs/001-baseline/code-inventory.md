# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/time-track-bundle`  
**Last audited**: 2026-07-07

## PHP classes (`src/**/*.php`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `TimeTrackBundle.php` | Bundle entry | FR-BUNDLE-001 |
| `DependencyInjection/Configuration.php` | Config tree | FR-CFG-001 |
| `DependencyInjection/TimeTrackExtension.php` | DI extension | FR-CFG-002 |
| `DependencyInjection/Compiler/TwigPathsPass.php` | Twig namespace | FR-TWIG-001 |
| `Routing/TimeTrackRouteLoader.php` | Route loader | FR-ROUTE-001 |
| `Doctrine/TimeTrackMetadataListener.php` | Table prefix | FR-DOCTRINE-001 |
| `Entity/ActiveTimer.php` | Active timer entity | FR-ENTITY-001 |
| `Entity/TimeEntry.php` | Time entry entity | FR-ENTITY-002 |
| `Entity/ClientToken.php` | Client token entity | FR-ENTITY-003 |
| `Enum/ClientType.php` | Client type enum | FR-ENUM-001 |
| `Enum/TimeEntrySource.php` | Entry source enum | FR-ENUM-001 |
| `ValueObject/Uuid.php` | UUID helper | FR-VO-001 |
| `Dto/TaskReference.php` | Task DTO | FR-DTO-001 |
| `Dto/TaskListQuery.php` | Task list query | FR-DTO-001 |
| `Integration/TaskProviderInterface.php` | Task provider contract | FR-INT-001 |
| `Integration/TeamContextProviderInterface.php` | Team context contract | FR-INT-002 |
| `Bridge/StubTaskProvider.php` | Demo task provider | FR-BRIDGE-001 |
| `Bridge/NullTeamContextProvider.php` | Null team provider | FR-BRIDGE-001 |
| `Repository/ActiveTimerRepositoryInterface.php` | Active timer repo IF | FR-REPO-001 |
| `Repository/TimeEntryRepositoryInterface.php` | Time entry repo IF | FR-REPO-001 |
| `Repository/ClientTokenRepositoryInterface.php` | Client token repo IF | FR-REPO-001 |
| `Repository/DoctrineOrmActiveTimerRepository.php` | Active timer ORM | FR-REPO-002 |
| `Repository/DoctrineOrmTimeEntryRepository.php` | Time entry ORM | FR-REPO-002 |
| `Repository/DoctrineOrmClientTokenRepository.php` | Client token ORM | FR-REPO-002 |
| `Service/TimerService.php` | Timer domain service | FR-SVC-001 |
| `Service/ClientAuthService.php` | Client auth service | FR-SVC-002 |
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

## Symfony config

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/config/services.yaml` | Service wiring | FR-DI-001 |

## Twig views

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/views/layout.html.twig` | Base layout | FR-TPL-001 |
| `Resources/views/time_track/index.html.twig` | Timer index | FR-TPL-001 |
| `Resources/views/time_track/reports.html.twig` | Reports page | FR-TPL-001 |

## Translations

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/translations/NowoTimeTrackBundle.en.yaml` | English | FR-I18N-001 |
| `Resources/translations/NowoTimeTrackBundle.es.yaml` | Spanish | FR-I18N-001 |

## Coverage summary

| Category | Files | Mapped |
| --- | ---: | ---: |
| PHP classes | 42 | 42 |
| YAML config | 1 | 1 |
| Twig views | 3 | 3 |
| Translations | 2 | 2 |
| **Total production sources** | **48** | **48** |
