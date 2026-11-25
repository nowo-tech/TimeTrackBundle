# Feature Specification: Device-flow scope families (Beacon Desktop BD-2)

**Feature Branch**: `feat/2.2-scope-families`  
**Created**: 2026-11-25  
**Status**: Implemented (2.2.0, unreleased)  
**Input**: Beacon Desktop design (`time-track-desktop` `docs/design/BEACON_DESKTOP.md`, BD-0) §4 and §7 (BD-2); owner
answers Q-BD-1..19 as recommended (2026-11-25); Beacon's fixed requirements R3 (tokens), R4 (tenant), R7 (worker).

**Related docs**: [`docs/USAGE.md`](../../docs/USAGE.md#device-flow-scope-families-22-beacon-desktop),
[`docs/CLIENT-API.md`](../../docs/CLIENT-API.md#changes-in-22-scope-families-contract-v05),
[`docs/UPGRADING.md`](../../docs/UPGRADING.md#to-220), [`docs/SECURITY.md`](../../docs/SECURITY.md#scope-families-and-tenant-context-22),
[`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md#device-flow-scope-families-and-tenant-context-22)  
**Code inventory (traceability)**: [`code-inventory.md`](code-inventory.md)

## User Scenarios & Testing

### User Story 1 — Connect chat on a signed-in desktop (P1)

The desktop holds a time token and runs the device flow again for `family: chat`, proving its device with the time
token. **Acceptance**: one approval page that names Chat; the token answers `scopeFamily: chat`; both tokens share a
device key; the host minter receives the family, scopes and device key. Tests: `ScopeFamilyClientApiTest`.

### User Story 2 — Tokens never cross families (P1)

**Acceptance**: a `chat` token gets `403 insufficient_scope` on every time route (`GET me` included); a `time` token is
refused by `ClientFamilyTokenAuthenticator` for `chat`; the time `logout` leaves a chat token alone and the reverse.
Tests: `ScopeFamilyClientApiTest`, `FamilyCredentialAndAuthenticatorTest`.

### User Story 3 — Revoke one family (P1)

**Acceptance**: `devicesOf()` shows one device with badges `time`, `chat`; revoking the chat session leaves the time
token working and the device with its time badge. Tests: `ScopeFamilyClientApiTest`.

### User Story 4 — Explicit tenant context (P1)

**Acceptance**: the configurable context header is checked by the host policy on every request; a revoked membership is
refused at the next request; nothing is written to the user's web context. Tests: `ScopeFamilyClientApiTest`,
`ClientContextResolverTest`.

### Edge Cases

- No family registered, no policy: byte-identical behaviour to 2.1 except the additive `scopeFamily` keys.
- Malformed families fail the container build (`ScopeFamiliesConfigurationTest`); a broken provider throws.
- Worker mode without reset, revocation mid-run, flat memory (`ScopeFamilyWorkerModeTest`).
- Rollback scripts delete non-time tokens first (`ScopeFamilySchemaTest`).

## Requirements

### Functional Requirements

- **FR-FAM-001**: Value objects `DeviceScopeFamily`, `DeviceScopeRequest`, `DeviceCredentialRequest`,
  `ClientContextOption`, `ClientFamilyPrincipal`, `ClientFamilyAuthentication`, validated on construction.
- **FR-FAM-002**: Port `DeviceScopeFamilyProviderInterface` (tagged), config provider, `DeviceScopeFamilyRegistry`
  (time first, one owner per key and scope, deny by default).
- **FR-FAM-003**: `device/code` `family` + one family per request + device-key link through a valid Bearer token.
- **FR-FAM-004**: Tokens and device requests store family, device key (and client name); time API refuses other
  families; family-aware `logout`.
- **FR-FAM-005**: `ClientFamilyCredentialMinterInterface` and the family-aware internal issuer.
- **FR-FAM-006**: `ClientFamilyTokenAuthenticator` for other bundles' APIs (same checks, family, scope, context, revoke).
- **FR-FAM-007**: Tenant context: `ClientContextPolicyInterface`, `ClientContextResolver`, `CurrentClientContext`,
  configurable header, `me.contexts` / `me.context`, CORS.
- **FR-FAM-008**: Audit per family (`ClientApiEvent::$scopeFamily`).
- **FR-FAM-009**: Connected devices read port and views with the family.
- **FR-FAM-010**: Approval page names the family (7 locales).
- **FR-FAM-011**: Schema scripts `docs/sql/2.2.0`, demo migration, worker-mode and concurrency docs.
