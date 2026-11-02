# Feature Specification: Client API 1.9 (contract v0.2)

**Feature Branch**: `feat/client-api-1.9`  
**Created**: 2026-11-02  
**Status**: Implemented (1.9.0, unreleased)  
**Input**: TimeTrack client API contract v0.2 (approved by Beacon 2026-11-02, §6 binding) for the browser extension
(`nowo-tech/time-track-extension`) and the desktop app (`nowo-tech/time-track-desktop`) offered by Beacon 1.117.0.

**Related docs**: [`docs/CLIENT-API.md`](../../docs/CLIENT-API.md), [`docs/USAGE.md`](../../docs/USAGE.md#host-integration-client-api-19),
[`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md#client-api-19), [`docs/SECURITY.md`](../../docs/SECURITY.md#client-api),
[`docs/UPGRADING.md`](../../docs/UPGRADING.md#to-190)  
**Code inventory (traceability)**: [`code-inventory.md`](code-inventory.md)

---

## Gaps closed (contract §0)

| Gap | Fix |
|---|---|
| G1 revoking a host credential left its session tokens alive | `ClientToken.credentialId`; per-request `ClientCredentialStateInterface::isActive(ClientToken)`; `ClientTokenRevoker::revokeByCredential()` |
| G2 no per-request task check on `timer/start` | `TaskAccessCheckerInterface::canTrack()` on start / switch, deny by default, one `403 task_forbidden` for missing and foreign tasks |
| G3 free metadata and server-side idle flag | `metadata` rejected unless `client_api.allow_metadata`; closed `source` enum + `note`; `heartbeat` ignores `isIdle` (deprecated); `timer/discard-idle` |
| G4 no scopes, device flow, rate limit per token, audit | Scopes, device flow, per-token / per-IP limits on a host pool, `ClientApiEvent` |

## User Scenarios & Testing

### User Story 1 — Extension signs in with an app password (P1)

**Acceptance**: `POST login` with a host credential returns `{token, expiresAt, scopes}`; the token carries the
credential id; deleting the credential (host calls `revokeByCredential`) or the host answering `isActive() = false`
makes the next request `401 invalid_token`. Tests: `ClientApiV19Test::testRevokeByCredentialEndsEverySessionOfTheCredential`,
`ClientApiControllerUnitTest::testInactiveCredentialAnswersInvalidToken`.

### User Story 2 — Desktop app connects with the device flow (P1)

**Acceptance**: `device/code` → user code `XXXX-XXXX` (no vowels) + verification URI; polling answers
`authorization_pending` / `slow_down`; the signed-in user enters the code, reviews client name / type / scopes, approves
(CSRF bound to action + request) → the host issues a credential → one `device/token` exchange returns the token, the
next answers `expired_token`; denial answers `access_denied`. Tests: `DeviceFlowTest`, `DeviceAuthorizationServiceTest`. Security audit CL (fail-closed scopes, IPv6 /64 limits, device request caps, per-username login throttle, anti-framing, request time + network on the page): `SecurityAuditClTest` (unit + functional).

### User Story 3 — Timer use cases of the clients (P1)

**Acceptance**: start / stop with note and `source`; switch checks the task before stopping; discard-idle keep /
discard / split; own entries bounded; own export CSV / JSON. Tests: `ClientApiV19Test`, `ClientApiTest`.

### User Story 4 — Host renders downloads (P2)

**Acceptance**: the partial renders the configured http(s) links as plain anchors inside a UiKit section, drops unsafe
URLs, no script / style. Test: `DeviceFlowTest::testDownloadsPartialRendersSafeLinksOnly`.

## Functional requirements

| ID | Requirement |
|---|---|
| FR-API-001 | Session tokens are issued from a host credential (`ClientAuthResult::success($user, ?$credentialId, $scopes)`, optional args), stored hashed with `credentialId`, `scopes`, `issuedAt`; never listed to users. |
| FR-API-002 | Every authenticated request checks `ClientCredentialStateInterface::isActive(ClientToken)` (default: true). `ClientTokenRevoker::revokeByCredential()` deletes every token of a credential. |
| FR-API-003 | Scopes `timer:read`, `timer:write`, `tasks:read`, `entries:read`, checked per endpoint (`403 insufficient_scope`); 1.8 tokens have all. |
| FR-API-004 | `GET me` returns `userId`, `identifier`, `displayName`, `scopes`, `workingHours` (`ClientUserProfileProviderInterface`). |
| FR-API-005 | `timer/start` and `timer/switch` call `TaskAccessCheckerInterface::canTrack()` per request (deny by default); missing and foreign → `403 task_forbidden`. `timer/switch` stops and starts, task checked first. `timer/discard-idle` applies keep / discard / split. |
| FR-API-006 | `heartbeat` deprecated, `isIdle` ignored; free `metadata` rejected unless `client_api.allow_metadata`; `entries` own and ≤ 100; `export` own CSV / JSON ≤ 10 000 rows, formula-safe CSV. |
| FR-API-007 | Device flow: hashed single-use codes, 8-char user code without vowels, TTL, `slow_down`, lookup throttle per user, `DeviceAuthorizationService` (lookup / approve / deny / CSRF id), optional default page (disable-able route), `ClientCredentialIssuerInterface` on approval, purge command. |
| FR-API-008 | Stateless (no session read / start, no cookies, also on 401 / 429); CORS exact origins only, no credentials mode. |
| FR-API-009 | Per-token and per-IP (and device per-IP) fixed-window limits on `client_api.rate_limit.cache_pool`; `429 rate_limited` + `Retry-After`. |
| FR-API-010 | `ClientApiEvent` audit (user id, credential id, client type, IP /24 or /64, reason; never the token) for issue / revoke / credential revoke / device approve-deny / denials (throttled per credential). |
| FR-API-011 | Settings via `ClientApiSettingsProviderInterface` (default from config). |
| FR-API-012 | Distribution via `ClientDistributionProviderInterface` (default from config) + UiKit partial with plain anchors. |
| FR-API-013 | BC for 1.x hosts: `ClientAuthenticatorInterface`, `ClientTokenRepositoryInterface`, login flow, route names / paths unchanged; device flow opt-in. |
| FR-API-014 | Errors `{error: code, message}`. |
| FR-API-015 | (1.9.1) `GET me` adds `minSupportedVersion` for the token's client type from `ClientMinSupportedVersionProviderInterface` (default: semver-validated `client_distribution.min_supported_version.*`); key absent when null. `ClientDistributionProviderInterface` unchanged. |
| FR-API-016 | (1.9.1, CL-05) `TimerService::stop()` (also `timer/switch`, idle split) records an entry only when its conditional delete of the timer row affected one row (`ConditionalActiveTimerRepositoryInterface`, one transaction); concurrent stops record one entry. Stores without it keep 1.x behaviour. |

## Deviations from the contract

- Rate limits apply per token to **every** authenticated endpoint (contract: `timer:*` 60/min) — simpler, one bucket.
- The 1.x Tauri webview origins stay allowed until 2.0 (`client_api.allow_tauri_webview_origins`, deprecated) so the
  1.x desktop agent keeps working; all other CORS is exact.
- `entries?userId=` (1.x team view) is kept, deprecated, behind `TeamAccessGuard`.
- Unknown device codes answer `expired_token` (no `invalid_grant`): the contract lists four codes only.
- `timer/switch` is "check, then stop, then start" (no DB transaction across repositories); a refused task never stops
  the running timer, a concurrent start answers `409`.

## Success criteria

- 100% line coverage (CI gate), PHPStan, CS, Rector, Igor, translations parity (7 locales), Twig lint, no raw HTML forms.
