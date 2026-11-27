# Feature Specification: Client capabilities from the host's permissions (TimeTrack 2.4.0)

**Feature Branch**: `feat/2.4.0-client-capabilities`  
**Created**: 2026-11-27  
**Status**: Implemented (2.4.0, unreleased)  
**Input**: Beacon request `docs/ops/BUNDLES-REQUESTS/2026-11-27-desktop-permission-parity.md` (spec 262 block B,
T004–T006, owner decision D-20261127-04, Beacon v1.181): "Beacon Desktop shows only the modules the person can use on
the web".

**Related docs**: [`docs/CLIENT-API.md`](../../docs/CLIENT-API.md#changes-in-24-capabilities-beacon-spec-262),
[`docs/USAGE.md`](../../docs/USAGE.md#modules-the-person-may-use-24-beacon-spec-262),
[`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md#client-capabilities-24),
[`docs/SECURITY.md`](../../docs/SECURITY.md#client-capabilities-24), [`docs/UPGRADING.md`](../../docs/UPGRADING.md#to-240),
[`docs/schemas/capabilities.v1.json`](../../docs/schemas/capabilities.v1.json)  
**Code inventory (traceability)**: [`code-inventory.md`](code-inventory.md)

## Gap (2.3.0)

The device flow granted every registered family to anyone who approved it (the four default time scopes always), the
password login always granted `time`, and a client had no way to learn which modules its person may use; a permission
removed after pairing could only be enforced by the host revoking the credential (`401`, the device signs out).

## User scenarios

1. **Messages only** (P1). A person who may use Messages but not time tracking pairs the desktop: the time request is
   not shown on the approval page and is denied; the chat request is approved; `GET me/capabilities` answers
   `{time: false, chat: true, tasks: false}`. Tests: `ClientCapabilitiesClientApiTest`.
2. **Permission removed after pairing** (P1). The next request of every token of that family answers `403
   module_forbidden` (`module` = the family); the token stays valid, `me/capabilities` (any family token) answers the
   module `false`; when the permission comes back the same token works. Tests: `ClientCapabilitiesClientApiTest`.
3. **Per context** (P2). The provider gets the request's context; a module may be off in one organisation and on in
   another. Tests: `ClientCapabilitiesClientApiTest`, `ClientCapabilitiesResolverTest`.
4. **No provider** (P1). Byte-identical grants and answers to 2.3 apart from the new route. Tests:
   `ClientCapabilitiesDefaultTest`, the existing suite.

## Edge cases

- Permission lost between the approval page and the submit, or between the approval and the exchange: refused under the
  lock / at the exchange, nothing minted or the minted credential handed to the orphan revoker (2.2.1 path).
- Provider exception: fail closed (false), logged without personal data.
- A token of a family no longer registered on `me/capabilities`: `403 insufficient_scope`.
- Worker mode: the resolver, guard and provider keep no per-request state (FrankenPHP, kernel reset false).

## Requirements

- **FR-CAP-001**: Host port `ClientCapabilitiesProviderInterface::canUse(TimeTrackUserRef, string $scopeFamily,
  ?string $context): bool`; `client_api.capabilities.provider` (service id, default null = off).
- **FR-CAP-002**: `ClientCapabilitiesResolver` (internal): `isEnforced()`, `isRegistered()`, `canUse()` (true without a
  provider, fail closed), `capabilitiesOf()` (`modules` with `time`, `chat`, `tasks` always present; `grantableScopes`).
- **FR-CAP-003**: `GET me/capabilities` (+ v1, route key `me_capabilities`): token of any registered family, every guard
  check except the module check, `Cache-Control: no-store, private`, JSON Schema `capabilities.v1.json`.
- **FR-CAP-004**: Device flow grant check: default page (not shown, denied at once, reason `scope_family_not_granted`),
  `approve()` under the lock (`DeviceAuthorizationRefusedException::FAMILY_NOT_GRANTED`), the exchange,
  `isGrantableTo()` for host pages, `deny()` audit reason.
- **FR-CAP-005**: `POST login` grants `time` only when usable (`403 module_forbidden`, audited `request_denied`).
- **FR-CAP-006**: Per-request module check in `ClientApiGuard` (time routes and `ClientFamilyTokenAuthenticator`): `403
  module_forbidden` + `module`, audited, token kept; `ClientFamilyAuthentication::status()` / `body()`.
- **FR-CAP-007**: Documented 401 / 403 semantics for clients (module revoked vs signed out).

## Success criteria

- 100% line coverage, PHPStan, PHP-CS-Fixer, Rector, translations (7 locales) and route fixtures green.
- No BC break: every new constructor argument is optional and trailing; no schema change.
