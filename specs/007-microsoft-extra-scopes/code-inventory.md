# Code inventory — 007 extra Microsoft scopes (2.3.0)

| Area | Files | Requirements | Tests |
| --- | --- | --- | --- |
| OAuth helper | `src/Calendar/MicrosoftCalendarOAuthClient.php` (`extraScopes()`, `scopesWith()`, `authorizationUrl()`, `exchangeCode()`, `refresh()`, `accessTokenForScope()`, `grantedExtraScopes()`, `isGranted()`) | FR-001, FR-002, FR-004, FR-005 | `tests/Unit/Calendar/MicrosoftExtraScopesTest.php`, `CalendarOAuthClientTest.php` |
| Token answers | `src/Calendar/CalendarTokenEndpoint.php` (`grantedScopes()`, consent-aware `tokens()`), `src/Calendar/CalendarTokens.php` (`grantedScopes()`) | FR-003, FR-004, FR-005 | `MicrosoftExtraScopesTest.php`, `tests/Unit/Calendar/CalendarReaderValueObjectsTest.php` |
| Credential port | `src/Calendar/CalendarCredential.php` (`$grantedScopes`) | FR-006 | `CalendarReaderValueObjectsTest.php` |
| Errors | `src/Calendar/CalendarSyncException.php` (`CONSENT_REQUIRED`, `SCOPE_NOT_GRANTED`) | FR-004, FR-005 | `MicrosoftExtraScopesTest.php` |
| Wiring | `src/DependencyInjection/Configuration.php` (`calendar.microsoft_extra_scopes`), `TimeTrackExtension.php` (`$extraScopes`) | FR-001 | `tests/Unit/DependencyInjection/CalendarExtensionTest.php` |
