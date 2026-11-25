# Code inventory — 100% traceability

**Spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/time-track-bundle`  
**Last audited**: 2026-11-27 (2.2.0, released)

## New PHP classes (`src/**/*.php`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Client/DeviceScopeFamily.php` | Scope family VO | FR-FAM-001 |
| `Client/DeviceScopeRequest.php` | Parsed device request scopes | FR-FAM-001, FR-FAM-003 |
| `Client/DeviceCredentialRequest.php` | Minter request VO | FR-FAM-001, FR-FAM-005 |
| `Client/DeviceScopeFamilyProviderInterface.php` | Family provider port (tagged) | FR-FAM-002 |
| `Client/ConfigDeviceScopeFamilyProvider.php` | Families from configuration | FR-FAM-002 |
| `Client/DeviceScopeFamilyRegistry.php` | Registry and request parser | FR-FAM-002, FR-FAM-003 |
| `Client/ClientFamilyCredentialMinterInterface.php` | Family-aware minter port | FR-FAM-005 |
| `Client/ClientFamilyTokenAuthenticator.php` | Authenticator for other bundles' APIs | FR-FAM-006 |
| `Client/ClientFamilyAuthentication.php` | Authenticator answer | FR-FAM-006 |
| `Client/ClientFamilyPrincipal.php` | Family principal VO | FR-FAM-006 |
| `Client/ClientTokenCheck.php` | Shared token check outcome (internal) | FR-FAM-004, FR-FAM-006 |
| `Client/ClientContextPolicyInterface.php` | Tenant context port | FR-FAM-007 |
| `Client/ClientContextOption.php` | Context option VO | FR-FAM-007 |
| `Client/ClientContextResolver.php` | Context check (internal) | FR-FAM-007 |
| `Client/CurrentClientContext.php` | Current request context reader | FR-FAM-007 |
| `Query/ConnectedClientDevicesQueryInterface.php` | Connected devices port | FR-FAM-009 |
| `Query/ConnectedDeviceView.php` | Device row view | FR-FAM-009 |
| `Exception/DeviceAuthorizationRefusedException.php` | Approval refused under the lock (audit ZBV-M1, ZBV-L1) | FR-FAM-003, FR-FAM-005 |
| `DependencyInjection/Compiler/ScopeFamilyMinterPass.php` | Build fails without a family-aware minter (audit ZBV-I1) | FR-FAM-005 |

## Files changed for 2.2.0 (also listed in the baseline / client API inventories)

| Source file | Change | Requirement IDs |
| --- | --- | --- |
| `Entity/ClientToken.php`, `Entity/DeviceAuthorization.php` | Family, device key, client name | FR-FAM-004 |
| `Client/ClientApiGuard.php` | Shared `check()`, family refusal, context, `deviceKeyOf()` | FR-FAM-004, FR-FAM-006, FR-FAM-007 |
| `Client/ClientApiPrincipal.php` | Context key | FR-FAM-007 |
| `Client/ClientApiAuditor.php`, `Event/ClientApiEvent.php` | Family in the audit | FR-FAM-008 |
| `Client/ClientResponseFactory.php` | Context header in CORS | FR-FAM-007 |
| `Client/ClientTokenRevoker.php`, `Dto/ClientSessionRecord.php`, `Repository/DoctrineOrmClientTokenRepository.php` | Family in the revocation audit | FR-FAM-008 |
| `Client/OpaqueClientCredentialIssuer.php`, `Port/ClientCredentialMinterGate.php`, `Port/Internal/ClientCredentialIssuerInterface.php` | Family-aware issue | FR-FAM-005 |
| `Service/ClientAuthService.php` | `issueFamilyToken()`, family `logout()` | FR-FAM-004 |
| `Service/DeviceAuthorizationService.php` | `startFamily()`, family approval and exchange | FR-FAM-003, FR-FAM-005 |
| `Controller/TimeTrackClientDeviceController.php` | `family`, Bearer link, `scopeFamily` | FR-FAM-003 |
| `Controller/TimeTrackClientApiController.php` | Time-only logout, `me.contexts` | FR-FAM-004, FR-FAM-007 |
| `Controller/TimeTrackDeviceVerificationController.php` | Hides unavailable families, link-mismatch outcome | FR-FAM-003, FR-FAM-010 |
| `Client/ClientIdempotencyStore.php` | Context-bound idempotency hash (audit ZBV-L3) | FR-FAM-007 |
| `TimeTrackBundle.php` | Registers `ScopeFamilyMinterPass` | FR-FAM-005 |
| `Controller/TimeTrackCalendarApiController.php`, `TimeTrackClientAllocationApiController.php`, `TimeTrackMonitoringApiController.php`, `TimeTrackWorkingTimeApiController.php` | Family in action audits | FR-FAM-008 |
| `Dto/DevicePollResult.php` | `scopeFamily` key | FR-FAM-003 |
| `Query/ClientSessionView.php`, `Query/DeviceSignInView.php`, `Query/DoctrineTimeTrackQuery.php` | Family views, `devicesOf()` | FR-FAM-009 |
| `PersonalData/DoctrineTimeTrackPersonalData.php` | Family and client name in the export | FR-FAM-009 |
| `Ui/TimeTrackPages.php` | Family on the approval page | FR-FAM-010 |
| `DependencyInjection/Configuration.php`, `DependencyInjection/TimeTrackExtension.php` | Options and wiring | FR-FAM-002, FR-FAM-007 |
