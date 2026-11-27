# Code inventory — 008 client capabilities (2.4.0)

| Area | Files | Requirements | Tests |
| --- | --- | --- | --- |
| Host port | `src/Client/ClientCapabilitiesProviderInterface.php` | FR-CAP-001 | `tests/Functional/ClientCapabilitiesClientApiTest.php`, `tests/Stub/StubClientCapabilitiesProvider.php` |
| Resolver | `src/Client/ClientCapabilitiesResolver.php` | FR-CAP-002 | `tests/Unit/Client/ClientCapabilitiesResolverTest.php` |
| Endpoint | `src/Controller/TimeTrackClientApiController.php` (`capabilities()`), `src/Routing/TimeTrackRouteLoader.php` (`me_capabilities`), `src/DependencyInjection/Configuration.php` (`clients.routes.me_capabilities`) | FR-CAP-003 | `ClientCapabilitiesClientApiTest.php`, `ClientCapabilitiesDefaultTest.php`, `tests/Integration/Routing/RouteCollectionFixtureTest.php` |
| Device flow | `src/Service/DeviceAuthorizationService.php` (`isGrantableTo()`, `denyNotGranted()`, `approve()`, `deny()` reason, exchange), `src/Exception/DeviceAuthorizationRefusedException.php` (`FAMILY_NOT_GRANTED`), `src/Controller/TimeTrackDeviceVerificationController.php`, `src/Resources/translations/*.yaml` (`device.not_granted`) | FR-CAP-004 | `ClientCapabilitiesClientApiTest.php` |
| Password login | `src/Controller/TimeTrackClientApiController.php` (`login()`) | FR-CAP-005 | `ClientCapabilitiesClientApiTest.php` |
| Per-request check | `src/Client/ClientApiGuard.php` (`authenticateAnyFamily()`, `moduleForbidden()`, module check), `src/Client/ClientTokenCheck.php`, `src/Client/ClientFamilyAuthentication.php` (`body()`) | FR-CAP-006 | `ClientCapabilitiesClientApiTest.php`, `ClientCapabilitiesResolverTest.php` |
| Wiring | `src/DependencyInjection/Configuration.php` (`client_api.capabilities.provider`), `src/DependencyInjection/TimeTrackExtension.php` | FR-CAP-001 | `ClientCapabilitiesClientApiTest.php` |
| Docs | `docs/CLIENT-API.md`, `docs/USAGE.md`, `docs/CONFIGURATION.md`, `docs/SECURITY.md`, `docs/UPGRADING.md`, `docs/CHANGELOG.md`, `docs/schemas/capabilities.v1.json` | FR-CAP-007 | — |
