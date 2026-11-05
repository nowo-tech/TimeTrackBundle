# Usage

## Table of contents

- [Web UI](#web-ui)
- [Overriding templates (REQ-TWIG-001)](#overriding-templates-req-twig-001)
- [Translations (REQ-I18N-001)](#translations-req-i18n-001)
- [API (clients)](#api-clients)
- [Host integration (client API 1.9)](#host-integration-client-api-19)
- [Imported entries (1.11)](#imported-entries-111)
- [Events](#events)

## Web UI

- **Web timer:** `/tools/time-track` — start/stop timer on trackable tasks
- **Reports:** `/tools/time-track/reports` — last 7 days of entries by default (`?from=&to=`), paginated
  (`?page=`, `reports.page_size` entries per page — see [CONFIGURATION.md](CONFIGURATION.md#reports-req-perf-001))
- **Pages (REQ-UI-003):** both pages extend the UiKit list scaffold and render inside `nowo_ui_kit.layouts.admin`
  (or `web_ui.layout_template`); classes come from the UiKit theme (`nowo_ui_kit.css_framework`). View models, data
  blocks and per-page overrides: [TEMPLATES.md](TEMPLATES.md); layout options and the deprecated 1.x `templates.*`
  keys: [CONFIGURATION.md](CONFIGURATION.md#web-ui-and-templates-req-ui-001--req-ui-003).
- **Override Twig (escape hatch):** `templates/bundles/NowoTimeTrackBundle/` — prefer the UiKit layout / theme and
  `web_ui.templates.*` (one data block) for upgrades.
- **Forms (REQ-TWIG-005):** the start/stop buttons are Symfony Forms (`Nowo\TimeTrackBundle\Form\TimerActionType`, flat field names `_token` / `action` / `task_id`, CSRF id `nowo_time_track_manage`). The controller always passes `stop_form` and `start_forms` (keyed by task id) to `time_track/index.html.twig`. Render them with `form_start`, the child loop and `form_end`; hosts that override the page and demos must not reintroduce raw `<form>` / `<input>` markup:

```twig
{{ form_start(stop_form) }}
    {% for child in stop_form %}
        {% if not child.rendered %}
            {{ form_row(child) }}
        {% endif %}
    {% endfor %}
    <button class="btn btn-danger" type="submit">{{ 'action.stop'|trans({}, 'NowoTimeTrackBundle') }}</button>
{{ form_end(stop_form) }}
```

## Overriding templates (REQ-TWIG-001)

Any bundle template can be replaced by a file at `templates/bundles/NowoTimeTrackBundle/<subpath>` (same relative
path as under `src/Resources/views/`). `TwigPathsPass` registers the application directory first (`prependPath`, when
it exists) and the bundle views after it (`addPath`), so **an application file at the override path always wins**.
The paths are registered whenever the bundle is enabled. Pages are rendered by logical name
(`@NowoTimeTrackBundle/...`, or the `web_ui.templates.index` / `web_ui.templates.reports` values).

**Freeze rule.** An application file at the override path always wins and will not pick up vendor changes for that
path until you remove or merge it: after `composer update` the application keeps rendering its own copy.
Upgrade-safe customisation, in this order:

| Depth | How | On a TimeTrackBundle upgrade |
| --- | --- | --- |
| Preferred — no Twig fork | `nowo_ui_kit.layouts.admin` or `web_ui.layout_template` (your layout or a one-file bridge), `nowo_ui_kit.css_framework` / `nowo_ui_kit.theme`, translations (below), the FormKit profile `time_track`, CSS on `nowo-ui-*` / `--nowo-ui-*` | Vendor templates and fixes apply automatically |
| Surgical | `web_ui.templates.index` / `web_ui.templates.reports` pointing at a thin host template that `{% extends '@!NowoTimeTrackBundle/time_track/reports.html.twig' %}` and overrides one data block (`nowo_ui_list_row`, `nowo_ui_list_after`, …) | Only that block is frozen |
| Full-file override | Copy a page into `templates/bundles/NowoTimeTrackBundle/<subpath>` | That `<subpath>` is frozen until removed or merged |

**Procedure.**

1. Find the `<subpath>` in the table below (path relative to `src/Resources/views/`).
2. Create `templates/bundles/NowoTimeTrackBundle/<subpath>` in the application (same file name).
3. Clear the cache in dev if needed: `php bin/console cache:clear`.
4. Treat the file as a fork: it does not change when the bundle ships a new version of that `<subpath>`.

Example: to replace the reports page, create `templates/bundles/NowoTimeTrackBundle/time_track/reports.html.twig`.

**Recovering upstream after a freeze.** Delete (or rename aside) the application file, clear the Twig cache, then
re-apply only surgical customisations if still needed. Diff your copy against
`vendor/nowo-tech/time-track-bundle/src/Resources/views/<subpath>` before re-copying anything.

**Overridable subpaths.**

| `<subpath>` | Purpose |
| --- | --- |
| `time_track/index.html.twig` | Timer page (UiKit list scaffold, `page` view model; 1.x variables `active_timer`, `tasks`, `stop_form`, `start_forms`) |
| `time_track/reports.html.twig` | Reports page (UiKit list scaffold, `page` view model, data block `nowo_ui_list_row`; 1.x variables `entries`, `entry_page`, `from`, `to`) |
| `layout.html.twig` | Deprecated (1.8, removed in 2.0): 1.x default layout, kept as a shim for 1.x templates that extend `nowo_time_track_layout` |
| `_legacy_layout.html.twig` | Deprecated (1.8, removed in 2.0): bridge for a 1.x host layout without the UiKit canonical blocks |

Renaming or removing a `<subpath>` or a published block is a breaking change (see [UPGRADING](UPGRADING.md)).

## Translations (REQ-I18N-001)

All bundle strings use the translation domain **`NowoTimeTrackBundle`** (catalogues in `src/Resources/translations/`,
7 locales: de, en, es, fr, it, nl, pt). The application overrides any key without forking templates:

1. Create `translations/NowoTimeTrackBundle.<locale>.yaml` (or `.xlf`) in the application — same domain name.
2. Add only the keys you want to change; missing keys fall back to the bundle catalogue.
3. Clear the cache in dev if needed: `php bin/console cache:clear`.

Example — `translations/NowoTimeTrackBundle.en.yaml`:

```yaml
page:
  timer: Timesheet
tasks:
  empty: No open tasks assigned to you.
flash:
  timer_started: Clock is running.
```

Application translations are loaded after the bundle catalogues, so they take precedence. To add a locale the bundle
does not ship, create the full file for that locale (copy the English catalogue as a starting point).

## API (clients)

Full reference with request / response examples: [CLIENT-API](CLIENT-API.md).

| Method | Path | Scope | Description |
|--------|------|-------|-------------|
| POST | `/api/time-track/login` | — | Session token `{token, expiresAt, scopes}` |
| POST | `/api/time-track/logout` | any | Revoke the presented token |
| GET | `/api/time-track/me` | any | `{userId, identifier, displayName, scopes, workingHours}` |
| GET | `/api/time-track/tasks` | `tasks:read` | Trackable tasks; `?search=&limit=&offset=` (`limit` 1–50) |
| GET | `/api/time-track/timer` | `timer:read` | Active timer (204 if none) |
| POST | `/api/time-track/timer/start` | `timer:write` | `{taskId, note?, source?}`; per-request task check |
| POST | `/api/time-track/timer/stop` | `timer:write` | `{note?}` |
| POST | `/api/time-track/timer/switch` | `timer:write` | `{taskId, note?}`: stop + start |
| POST | `/api/time-track/timer/discard-idle` | `timer:write` | `{from, to, action: keep\|discard\|split}` |
| POST | `/api/time-track/heartbeat` | `timer:write` | Deprecated (removed in 2.0); `isIdle` ignored |
| GET | `/api/time-track/entries` | `entries:read` | Own entries `?from=&to=&limit=1..100&offset=` |
| GET | `/api/time-track/export` | `entries:read` | Own data `?from=&to=&format=csv\|json` |
| POST | `/api/time-track/device/code` | — | Device flow start (with `device_flow.enabled`) |
| POST | `/api/time-track/device/token` | — | Device flow poll |

`from` / `to` (client `entries` API and the manage `reports` page) accept ATOM / RFC 3339 (`2026-05-01T10:30:00+02:00`) or `Y-m-d` (midnight). Any other value falls back to the default (`from` = 7 days ago, `to` = now). The span is capped at 366 days: an earlier `from` is moved to `to - 366 days`.

## Host integration (client API 1.9)

All interfaces are optional; set the service id under `client_api.*` (see [CONFIGURATION](CONFIGURATION.md#client-api-19)).

**Login with a host credential** — return the credential id and scopes from your authenticator (1.x authenticators
that return `ClientAuthResult::success($user)` keep working: no credential, all scopes):

```php
final class AppPasswordAuthenticator implements ClientAuthenticatorInterface
{
    public function authenticate(string $username, string $password): ?ClientAuthResult
    {
        $appPassword = $this->appPasswords->findValid($username, $password); // your lookup
        return $appPassword === null ? null : ClientAuthResult::success($appPassword->getUser(), (string) $appPassword->getId(), ['timer:read', 'timer:write', 'tasks:read', 'entries:read']);
    }
}
```

**`ClientCredentialStateInterface::isActive(ClientToken $token): bool`** — per request. Check the credential
(`$token->getCredentialId()`) still exists and is not expired, the user is enabled, the organisation is active and no
"sign out everywhere" happened after `$token->getIssuedAt()`. Cache ≤ 60 s if needed. A `false` answers `401
invalid_token`.

**`ClientTokenRevoker::revokeByCredential(string $credentialId, ?UserInterface $user = null): int`** — call it when the
user deletes the credential (immediate end of every session issued from it; audited as `credential_revoked`).

**`TaskAccessCheckerInterface::canTrack(UserInterface $user, string $taskId): bool`** — per `timer/start` and
`timer/switch`. Deny by default; return true only when the user may track the task now (Beacon:
`project.tasks.track_time` on the task's project). Missing and foreign tasks both answer `403 task_forbidden`.

**`ClientCredentialIssuerInterface::issueForDevice(UserInterface $user, ClientType $clientType, string $clientName, array $scopes): string`** —
called when the user approves a device: create a credential (e.g. an app password named `$clientName`, typed by `$clientType`) so the device
shows in the same list, and return its id.

**`DeviceAuthorizationService`** (own verification page; set `device_flow.default_page: false` and
`verification_uri`):

```php
$request = $devices->findPending($typedCode, $user);            // null: unknown / expired; throws DeviceCodeAttemptsExceededException
// render $request->getClientName(), ->getClientType(), ->getScopes(), ->getExpiresAt();
// approve / deny forms with CSRF id $devices->csrfTokenId('approve'|'deny', $request) and the request id
$request = $devices->findPendingById($postedId);                 // after the CSRF check
$devices->approve($request, $user, $httpRequest->getClientIp()); // or ->deny(...)
```

Also show `->getCreatedAt()` and `->getIpPrefix()` (requesting /24 or /64) so the user can spot a request they did not
start, and treat `getClientName()` / `getClientType()` as the device's own claims. Send these headers on every answer of
your page (the default page does): `X-Frame-Options: DENY`, `Content-Security-Policy: frame-ancestors 'none'`,
`Cache-Control: no-store` — a framed page pre-filled from `?code=` is a two-click clickjacking target.

**`ClientApiSettingsProviderInterface::getSettings(): ClientApiSettings`** — origins and rate limits from your
instance settings (read per request). **`ClientDistributionProviderInterface::getDistribution(): ClientDistribution`**
— download links. **`ClientUserProfileProviderInterface`** — `getDisplayName()` / `getWorkingHours()` of `GET me`; since 1.11 the same
service may also implement **`ClientUserLocaleProviderInterface`** (`getLocale()`, the `locale` of `GET me`).
**`ClientMinSupportedVersionProviderInterface::getMinSupportedVersion(ClientType): ?string`** (1.9.1) — `GET me`'s
`minSupportedVersion` for the token's client type (`null` = key absent). Default: `client_distribution.min_supported_version.*`;
a host with a release manifest backs it there (Beacon reads its `stable.json`) and sets
`client_distribution.min_supported_version.provider` to its service id. Return semver only.

**Custom active timer store** — implement `ConditionalActiveTimerRepositoryInterface` (1.9.1): `removeIfPresent()`
deletes the timer only while it still exists and runs the callback (which records the entry) in the same transaction,
so two concurrent `timer/stop` / `timer/switch` calls record one entry. A store with only
`ActiveTimerRepositoryInterface` keeps the 1.x behaviour, which can record a concurrently stopped timer twice.

**Downloads partial** — embed in a page on the UiKit page contract (plain anchors, no script / style):

```twig
{{ include('@NowoTimeTrackBundle/client_distribution/_downloads.html.twig') }}
```

**Audit** — listen to `nowo_time_track.client_api` (`ClientApiEvent`: `action`, `userId`, `credentialId`,
`clientType`, `ipPrefix` /24 or /64, `reason`, `occurredAt`; never the token). Actions: `token_issued`,
`token_revoked`, `credential_revoked`, `device_approved`, `device_denied`, `request_denied` (throttled per credential).

**Cron** — `nowo:time-track:client-tokens:purge` also deletes expired device requests.

## Imported entries (1.11)

Time recorded in another tool (e.g. ClickUp, through TaskBoardBundle's sync) is written by the host:

```php
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryData;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryWriter;

$result = $writer->upsert($user, new ImportedTimeEntryData(
    externalSource: 'clickup:' . $directoryId,   // [a-z0-9_]{1,24}:[A-Za-z0-9._-]{1,39}
    externalId: $clickUpEntryId,
    taskId: $task->getId(), taskTitle: $task->getTitle(), boardId: $board->getId(),
    startedAt: $start, endedAt: $end, durationSeconds: $seconds,
    billable: $billable, description: $description,  // kept in full
));
// $result->outcome: created | updated | unchanged (unchanged = nothing written, no event)
```

- Idempotent by `(externalSource, externalId)`; `upsertMany()` takes up to 500 rows with one flush, each identity
  at most once per call (`rows` / `duplicate` otherwise). When a concurrent run inserts an identity first, the
  unique violation is read again once and the row becomes an update (still refused for another owner).
- Validation (`InvalidImportedTimeEntryException`, field and rule only, never the value): source / id / task / board
  patterns, valid UTF-8 title and description, `0 < duration ≤ end − start + 60 s`, one entry at most
  `imports.max_entry_seconds` (31 days by default). The title is cleaned to one line and cut to 255; the description
  is cleaned (controls and bidi overrides stripped, line breaks kept) and **never truncated**.
- **The owner never changes**: re-associating someone's time is `removeForUser()` / `remove()` then `upsert()` for
  the new user (the host audits it).
- Imported entries are **read-only** (`TimeEntry::isReadOnly()`, `canEditEntry()` always false) and are not live
  (`TimeEntrySource::isLive()`): keep them out of overlap checks, registers and clock-in rules
  (`findLiveByUserAndPeriod()`, `TimeEntryListQueryEvent::setIncludeImported(false)`).
- **Deleting** imported time is an explicit manager action: one entry through `ImportedTimeEntryDeleter` (needs a
  `delete_imported` grant on `TimeEntryAccessCheckEvent`), or in bulk through `remove*()` after the host's own check.
  **Never** call `removeForTasks()` / `removeForBoard()` from a task or board deletion: deleting tasks keeps tracked
  time (owner rule).
- Lists, reports and exports include imported entries. The title of an imported entry whose task its owner cannot
  track is shown as *Task not visible*.

## Events

Since 1.11: `TimeTrackEvents::TIME_ENTRY_IMPORTED` (`TimeEntryImportedEvent`) after an imported entry is created or
changed, and `TimeTrackEvents::TIME_ENTRIES_REMOVED` (`TimeEntriesRemovedEvent`: scope, count, seconds per task, owner
ids) after imported entries are deleted. Neither is a timer event.

- `nowo_time_track.timer.start` — `TimerStartEvent`
- `nowo_time_track.timer.stop` — `TimerStopEvent` (TaskBoard can aggregate `time_spent`)
- `nowo_time_track.time_entry.list_query` — filter report user ids
- `nowo_time_track.time_entry.access_check` — fine-grained ACL
- `nowo_time_track.client_api` — `ClientApiEvent` audit trail of the client API (1.9)
