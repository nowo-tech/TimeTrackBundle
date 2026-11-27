# Feature Specification: Extra Microsoft scopes on the calendar connection (TimeTrack 2.3.0)

**Feature Branch**: `feat/2.3.0-extra-scopes`  
**Created**: 2026-11-27  
**Status**: Implemented (2.3.0, unreleased)  
**Input**: Beacon request `docs/ops/BUNDLES-REQUESTS/2026-11-27-teams-calls.md` item 1 (spec 260 T001, *Chat video
calls through Microsoft Teams*): the Microsoft OAuth helper asks for `OnlineMeetings.ReadWrite` on top of the calendar
scope when the host enables it, with incremental consent, and the stored token exposes the granted scopes.

**Related docs**: [`docs/MEETINGS.md`](../../docs/MEETINGS.md#extra-microsoft-scopes-23),
[`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md#calendar-suggestions-114),
[`docs/UPGRADING.md`](../../docs/UPGRADING.md#to-230)  
**Code inventory (traceability)**: [`code-inventory.md`](code-inventory.md)

## Gap (2.2.1)

`MicrosoftCalendarOAuthClient::scopes()` was fixed to `offline_access` + `calendar.microsoft_scope` and used for the
consent URL, the code exchange and the refresh; `CalendarTokens` kept no granted scopes. No `extra_scopes` option
existed.

## User scenarios

1. **First call of a connected person** (P1). Beacon allow-lists `OnlineMeetings.ReadWrite`. A person connected in
   2.2 (calendar only) starts a call: Beacon sees `isGranted(stored, 'OnlineMeetings.ReadWrite') === false` and sends
   the person to `authorizationUrl(..., ['OnlineMeetings.ReadWrite'])`; Entra ID asks only for the new permission.
2. **Refresh keeps the calendar working** (P1). The refresh asks only for the scopes in the stored grant. If the
   person removed the meeting permission, Entra ID's `consent_required` makes the helper retry with the calendar scopes
   alone; the calendar keeps syncing and the new granted scopes show the permission is gone.
3. **A token for the Graph call** (P1). `accessTokenForScope()` returns a token for the granted scope; nothing is sent
   when the stored grant lacks it (`scope_not_granted`).
4. **Default unchanged** (P1). Without `microsoft_extra_scopes` every request is the 2.2 one.

## Requirements

- **FR-001**: `calendar.microsoft_extra_scopes` — allow-list (default `[]`), Graph scope names
  (`MicrosoftCalendarOAuthClient::EXTRA_SCOPE_PATTERN`), at most 10, distinct case-insensitively, not the calendar
  scope; refused at compile time (configuration) and in the constructor.
- **FR-002**: `authorizationUrl()` / `exchangeCode()` take an optional list of extra scopes, a subset of the
  allow-list (else `InvalidArgumentException` before any call, message without the scope); sent once, allow-list
  spelling, after the calendar scopes. PKCE, state and `select_account` unchanged.
- **FR-003**: `CalendarTokens::grantedScopes()` from the answer's `scope` (else the requested scope, RFC 6749 §5.1;
  null when unknown); malformed tokens dropped, at most 64. Not secret: shown by `__debugInfo()`.
- **FR-004**: `refresh($token, ?array $grantedScopes = null)` asks only for the calendar scopes plus the allow-listed
  extra scopes in `$grantedScopes`; on `consent_required` (AADSTS65001) one retry with the calendar scopes alone.
- **FR-005**: `accessTokenForScope($token, $scope, $grantedScopes)`: allow-listed and granted, else
  `InvalidArgumentException` / `scope_not_granted` without a call; Entra ID's missing consent is `consent_required`.
- **FR-006**: `CalendarCredential` takes optional granted scopes (credential port, additive).
- **FR-007**: No BC break: new arguments optional and trailing, new error codes only from the new paths; tokens never
  logged; stateless readonly services (FrankenPHP worker mode, REQ-RUNTIME-003).

## Success criteria

- 100% line coverage, PHPStan, PHP-CS-Fixer and Rector clean.
- `MicrosoftExtraScopesTest` covers FR-001..FR-005; `CalendarExtensionTest` FR-001; `CalendarReaderValueObjectsTest`
  FR-003 / FR-006; the 2.2 tests of `CalendarOAuthClientTest` pass unchanged (FR-007).
