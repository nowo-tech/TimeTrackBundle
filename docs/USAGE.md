# Usage

## Table of contents

- [Web UI](#web-ui)
- [Overriding templates (REQ-TWIG-001)](#overriding-templates-req-twig-001)
- [Translations (REQ-I18N-001)](#translations-req-i18n-001)
- [API (clients)](#api-clients)
- [Host integration (client API 1.9)](#host-integration-client-api-19)
- [Imported entries (1.11)](#imported-entries-111)
- [Entries with no user (1.15)](#entries-with-no-user-115)
- [Read ports and personal data (1.35)](#read-ports-and-personal-data-135)
- [Entries without a task (1.36, Beacon spec 250)](#entries-without-a-task-136-beacon-spec-250)
- [Device-flow scope families (2.2, Beacon Desktop)](#device-flow-scope-families-22-beacon-desktop)
  - [Register a family](#register-a-family)
  - [Two approvals chained by the app (Q-BD-6)](#two-approvals-chained-by-the-app-q-bd-6)
  - [Authenticate another bundle's API with a family token](#authenticate-another-bundles-api-with-a-family-token)
  - [Tenant context header (Q-BD-7)](#tenant-context-header-q-bd-7)
  - [Modules the person may use (2.4, Beacon spec 262)](#modules-the-person-may-use-24-beacon-spec-262)
  - [Connected apps (Q-BD-19)](#connected-apps-q-bd-19)
- [Time subjects and activity types (2.1, Beacon spec 256)](#time-subjects-and-activity-types-21-beacon-spec-256)
  - [Standard Symfony host](#standard-symfony-host)
  - [DDD / hexagonal host](#ddd--hexagonal-host)
  - [Rules worth knowing](#rules-worth-knowing)
- [Entry access by id (1.37)](#entry-access-by-id-137)
- [Writes by id (1.40)](#writes-by-id-140)
- [Task time follows a board move (Unreleased)](#task-time-follows-a-board-move-unreleased)
- [Working time and timesheets (1.13)](#working-time-and-timesheets-113)
- [Calendar suggestions (1.14)](#calendar-suggestions-114)
- [Calendar OAuth apps at runtime (1.20)](#calendar-oauth-apps-at-runtime-120)
- [Billable entries (1.21)](#billable-entries-121)
- [Leave on the clients (1.22, spec 204)](#leave-on-the-clients-122-spec-204)
- [Timer for token principals and service accounts (1.25)](#timer-for-token-principals-and-service-accounts-125)
- [Activity monitoring ports (1.26)](#activity-monitoring-ports-126)
- [Standard Symfony host](#standard-symfony-host)
- [Using it from a DDD / hexagonal application](#using-it-from-a-ddd--hexagonal-application)
- [Worker mode (FrankenPHP, kernel reset false)](#worker-mode-frankenphp-kernel-reset-false)
- [Events](#events)

## Web UI

- **Web timer:** `/tools/time-track` — start/stop timer on trackable tasks
- **Reports:** `/tools/time-track/reports` — last 7 days of entries by default (`?from=&to=`), paginated
  (`?page=`, `reports.page_size` entries per page — see [CONFIGURATION.md](CONFIGURATION.md#reports-req-perf-001))
- **Pages (REQ-UI-003):** both pages extend the UiKit list scaffold and render inside `nowo_ui_kit.layouts.admin`
  (or `web_ui.layout_template`); classes come from the UiKit theme (`nowo_ui_kit.css_framework`). View models, data
  blocks and per-page overrides: [TEMPLATES.md](TEMPLATES.md); layout options and the 1.x `templates.*` keys removed in
  2.0: [CONFIGURATION.md](CONFIGURATION.md#web-ui-and-templates-req-ui-001--req-ui-003).
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
| GET | `/api/time-track/me` | any | `{userId, identifier, displayName, scopes, workingHours, locale, daysOff, features, limits}` (`workingHours` in the `slots` shape since 1.12) |
| GET | `/api/time-track/tasks` | `tasks:read` | Trackable tasks; `?search=&limit=&offset=` (`limit` 1–50) |
| GET | `/api/time-track/timer` | `timer:read` | Active timer (204 if none) |
| POST | `/api/time-track/timer/start` | `timer:write` | `{taskId, note?, source?}`; per-request task check |
| POST | `/api/time-track/timer/stop` | `timer:write` | `{note?}` |
| POST | `/api/time-track/timer/switch` | `timer:write` | `{taskId, note?}`: stop + start |
| POST | `/api/time-track/timer/discard-idle` | `timer:write` | `{from, to, action: keep\|discard\|split}` |
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

**`ClientTokenRevoker::revokeById(ClientSessionId|string $sessionId, UserInterface $actor): ClientSessionRevocation`** (1.34.0) —
"sign out this device": ends one session (`ClientToken::getId()`, a UUID) at once; its Bearer token answers `401` on the
next request. Use it instead of deleting `time_track_client_tokens` rows yourself.

```php
use Nowo\TimeTrackBundle\Client\ClientTokenRevoker;
use Nowo\TimeTrackBundle\Enum\ClientSessionRevocation;
use Nowo\TimeTrackBundle\ValueObject\ClientSessionId;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/account/sessions/{id}/revoke', methods: ['POST'])]
#[IsGranted('IS_AUTHENTICATED')] // revokeById() needs a user: never pass a null getUser() (TypeError, 500)
public function revoke(string $id, Request $request, ClientTokenRevoker $revoker): Response
{
    if (!$this->isCsrfTokenValid('revoke-session-' . $id, (string) $request->request->get('_token'))) {
        throw $this->createAccessDeniedException();
    }
    $user = $this->getUser();
    if (!$user instanceof UserInterface) {
        throw $this->createNotFoundException();
    }
    $result = $revoker->revokeById($id, $user); // or ClientSessionId::fromString($id)

    return $result === ClientSessionRevocation::Revoked
        ? $this->redirectToRoute('account_sessions')
        : throw $this->createNotFoundException(); // unknown, already revoked or not yours: one answer
}
```

- **Who may revoke:** the session owner, and users with one of `security.admin_roles` (role names, no hierarchy). Then
  `nowo_time_track.client_session.access_check` (`ClientSessionAccessCheckEvent`, action `revoke`) is dispatched for an
  existing session with that decision: `grant()` (e.g. a support role of the owner's organisation) or `deny()` (e.g.
  an admin of another tenant). The event carries `actorRef()` (`TimeTrackUserRef`), `getSessionId()`, `getOwnerId()`,
  `getCredentialId()` and `getClientType()`; never the token.
- **Result:** `Revoked` when this call ended the session; `NotFound` otherwise — no such id, a malformed id, already
  revoked (also by a concurrent call) or not allowed. Not allowed is never told apart, so a caller learns nothing about
  someone else's session. Calling it again is safe.
- **Audit:** a revocation dispatches `nowo_time_track.client_api` with action `session_revoked` (user = the owner,
  `credentialId`, `clientType`, reason `owner` or `other`). A listener that throws is logged; the revocation stands.
  Logs carry the action and the client type only.
- **Storage:** one `DELETE` statement on the configured TimeTrack manager, through the bundle's transaction runner
  (statements only; a savepoint inside your open transaction). It flushes, clears, resets and loads nothing in any
  entity manager, so it is safe in a FrankenPHP worker with kernel reset off and next to your own pending changes. A lock conflict (deadlock, lock wait
  timeout) throws `Nowo\TransactionContract\TransientStorageFailure` (with a Retry-After hint) and nothing is
  committed: `ConflictCode::forFailure()` (transaction-doctrine) gives `CONFLICT_001` — answer `409` with `Retry-After`
  and retry.
- **Inside your own transaction:** your commit decides whether the session is gone, but `revokeById()` has already
  returned `Revoked` and dispatched `session_revoked` before you commit. If you roll back, the session works again
  while the audit record stays: call it after your commit, or record the rollback in your audit store.
- **Custom stores:** `revokeById()` needs a store implementing `ClientSessionRepositoryInterface` (the Doctrine store
  does); otherwise it throws `LogicException`.

**`TaskAccessPolicyInterface::mayTrack(TimeTrackUserRef $user, string $taskId): bool`** — per `timer/start` and
`timer/switch`. Deny by default; return true only when the user may track the task now (Beacon:
`project.tasks.track_time` on the task's project). Missing and foreign tasks both answer `403 task_forbidden`.

**`ClientCredentialMinterInterface::mintForDevice(TimeTrackUserRef $user, ClientType $clientType, string $clientName, array $scopes): string`** —
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
— download links. **`ClientUserProfileSourceInterface`** — `displayNameOf()` / `workingHoursOf()` of `GET me`; since 1.11 the same
service may also implement **`ClientUserLocaleSourceInterface`** (`localeOf()`, the `locale` of `GET me`) and, since
1.12, **`ClientUserDaysOffSourceInterface`** (`daysOffOf($user, $from, $to)`, the `daysOff` of `GET me`: holidays and
vacations from today to today + 60 days). `workingHoursOf()` may return the `slots` or the 1.9 `days` shape; `GET me`
always sends `slots` ([CLIENT-API](CLIENT-API.md#working-hours-normalised-since-112)).
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
`token_revoked`, `credential_revoked`, `session_revoked` (1.34.0), `device_approved`, `device_denied`, `request_denied` (throttled per credential).
Since 2.2.1 `device_denied` also covers an exchange refused after the approval (reason `account_inactive` or
`scope_family_unavailable`, `credentialId` = the orphaned credential; see `OrphanedCredentialRevokerInterface`).

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
  at most once per call (`rows` / `duplicate` otherwise).
- **Concurrent runs.** When another run inserts one of the identities between the read and the flush, the flush fails
  with a unique violation and nothing of the batch is written. With the default Doctrine store and a
  `ManagerRegistry` (the Symfony default), the store resets its closed entity manager, the writer reads the whole
  batch again **once**, the raced row becomes an update (still refused for another owner) and the new
  rows are created with their owners re-attached (`attachUser()`; the entries then reference the managed user, not
  the object you passed). It **rethrows** `UniqueConstraintViolationException` instead, with nothing written, when
  no registry is wired (the entity manager stays closed) or when the call runs **inside a transaction of yours** (its
  snapshot may hide the other row; PostgreSQL aborts it): roll back and retry the slice. Do not wrap the writer in
  your own transaction. A second violation on the retry is rethrown too. After a reset, user objects you hold are
  detached: re-read them before reusing them.
- Validation (`InvalidImportedTimeEntryException`, field and rule only, never the value): source / id / task / board
  patterns, valid UTF-8 title and description, `0 < duration ≤ end − start + 60 s`, one entry at most
  `imports.max_entry_seconds` (31 days by default), start year ≥ 1970 and end at most one day after now (since 1.12;
  `too_old` / `future`). The title is cleaned to one line and cut to 255; the description
  is cleaned (controls and bidi overrides stripped, line breaks kept) and **never truncated**.
- **An upsert never changes the owner**: re-associating someone's time is a move (since 1.16, [below](#moving-imported-time-between-owners-116)), which deletes nothing. An entry of a person with no account may also be claimed explicitly.
- **A person with no account (1.15):** `$writer->upsertForPerson(new ImportedTimeEntryPerson($ref, $name, $scopeKey), $data)`.
  See [Entries with no user](#entries-with-no-user-115).
- Imported entries are **read-only** (`TimeEntry::isReadOnly()`, `canEditEntry()` always false) and are not live
  (`TimeEntrySource::isLive()`): keep them out of overlap checks, registers and clock-in rules
  (`findLiveByUserAndPeriod()`, `TimeEntryListQueryEvent::setIncludeImported(false)`).
- **`removeForUser()` deletes time: since 1.16 use a move to re-associate it.** Never call it for a deleted or
  erased account: it deletes tracked time. Since 1.15 use [Entries with no user](#entries-with-no-user-115).
- **Deleting** imported time is an explicit manager action: one entry through `ImportedTimeEntryDeleter` (needs a
  `delete_imported` grant on `TimeEntryAccessCheckEvent`), or in bulk through `remove*()` after the host's own check.
  **Never** call `removeForTasks()` / `removeForBoard()` from a task or board deletion: deleting tasks keeps tracked
  time (owner rule). Removals are DQL bulk deletes: since 1.12 loaded entries of removed rows are detached and task
  lists are bound 1 000 ids per query; do not reuse `TimeEntry` objects of removed rows.
- Lists, reports and exports include imported entries. The title of an imported entry whose task its owner cannot
  track is shown as *Task not visible* on the bundle's own surfaces (client API, export JSON / CSV, reports page).
- **Masking is per surface.** The stored `taskTitleSnapshot` and `toArray()` without arguments keep the real title.
  A host that overrides `reports.html.twig` (full file, or the surgical `nowo_ui_list_row` override) must keep the
  `hidden_task_entry_ids` check; host lists and exports must call `TimerService::hiddenTaskEntryIds($entries)` and
  print `reports.task_not_visible` / use `toArray(false, $translatedTitle)` for the returned ids. Since 1.12 a masked
  entry's `description` is hidden as well (`null` in `toArray(false, …)`, empty in the CSV): it is copied from the
  source and may repeat task content. Do not print it in host lists for those ids either.
- **Whose access masks a title.** `hiddenTaskEntryIds()` asks the task provider whether the entry's **owner** may track
  the task (`canTrackTask(owner, taskId)`), not the viewer: a manager sees the titles the member can see, as for live
  entries. A task the owner can see but no longer track (closed, archived) is masked too (the safe direction). Large
  lists make one provider call per (owner, task); a provider that implements `BatchTaskDirectoryInterface`
  (`filterTrackableFor(user, taskIds)`, since 1.12) is asked once per owner.
- If you replace or decorate `TimeEntryRepositoryInterface`, the imported-entry store (`ImportedTimeEntryStoreInterface`) resolves to your
  service (since 1.12): implement it there (or alias it yourself), otherwise getting it throws `LogicException`.
- The client API's own `GET entries` (without `userId`) and `GET export` **always include** the token user's imported
  entries (flagged `readOnly`, `source: import`) and ignore `TimeEntryListQueryEvent::setIncludeImported(false)`:
  they are the user's copy of their own data (GDPR art. 15 / 20). The team `userId` path honours it.

## Entries with no user (1.15)

Owner rule: tracked time is not lost, including when a user is deleted. The rule keeps the hours; it does not require keeping
the person. An entry has no user when it is a former person's imported time, or when its user was deleted (`user_id` is
`ON DELETE SET NULL`).

```php
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryClaim;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryPerson;
use Nowo\TimeTrackBundle\Retention\FormerPersonTimeEntries;

// A former colleague with no account: the entry has no owner and shows under the name.
$writer->upsertForPerson(new ImportedTimeEntryPerson(ref: $personId, name: 'Ana Ruiz', scopeKey: $tenant), $data);
// A mapped person: the user owns new entries, and takes the entries of that person, in that tenant.
$writer->upsert(new ImportedTimeEntryClaim($user, $personId, $tenant), $data);

// Someone leaves: their running timer is saved and their entries stay under their name; the account can go.
$people->release($user, 'Ana Ruiz');
// An erasure request: the time stays, the person goes. Also covers what release() or a delete left.
$people->anonymiseUser($user);
$people->anonymisePerson($personId);                                    // a former person
$people->anonymisePerson(FormerPersonTimeEntries::personRefOf($user)); // when you no longer hold the user
$people->anonymiseUnidentified();                                       // what a raw DELETE left: all of it
// The retention term for names and free text.
$people->anonymiseOwnerlessEndedBefore($clock->now()->modify('-4 years'));
// GDPR art. 15 / 20. Pass cells through TimeEntryCsv::cell() if you write a CSV.
$rows = $people->export($personId);
```

| An upsert naming | on an entry of | does |
| --- | --- | --- |
| a user or a claim | the same user | update |
| a user or a claim | another user | `ImportedEntryOwnerMismatchException` |
| a plain user | a former person or a deleted user | mismatch: taking one must be explicit |
| a claim, with the entry's reference and exactly its scope (with a scope provider, also among `scopesFor()` of the user) | a former person | claim: the user owns it, the snapshot goes |
| a claim, with the entry's reference and exactly its scope | a deleted user | the same id: back to them; another: mismatch |
| a claim with another reference or scope, or with a scope on an entry that has none | an ownerless entry | mismatch |
| a person | a user | mismatch: use `moveToPerson()` |
| a person, same reference and scope | that person | update, name included |
| a person, another reference or scope | a former person | mismatch |
| a person | a deleted user | `Unchanged`, nothing written |
| a user, a claim or a person | an entry with no identity (a raw delete) | `Unchanged`, nothing written |
| anyone | an anonymised entry | `Unchanged`, nothing written |

A mismatch refuses the whole batch, as it always has; `ImportedEntryOwnerMismatchException::$externalId` says which row stopped it. References
compare case-sensitively. `Unchanged` on a deleted user's entry means the local hours stay as they were, even if the remote changed: totals can
drift from the remote. To take a remote change, a manager removes the entry and the next sync imports it for the person.

- `ImportedTimeEntryPerson::$ref` is opaque and stable (`[A-Za-z0-9._:#-]{1,64}`, `user:` is reserved in any case): never an e-mail.
  The name is cleaned to one line with every invisible character stripped, cut to 255; blank, or anything holding an `@`, means none.
- **Who sees them:** nobody by default. A `TIME_ENTRY_LIST_QUERY` listener decides, with the viewer's tenant scopes:
  `$event->includeOwnerless(['org-a'])`, or `null` for every scope (the only way to list an entry with no scope, so a host
  listing by scope does not see those hours). They never reach the client API or the owner's export. On a MySQL
  case-insensitive collation, keep tenant keys distinct regardless of case.
- **Read-only:** `canEditEntry()` is false for them (no owner, or imported).
- **Manager removal stays allowed** (owner ruling: "never deleted" means never as a side effect). `ImportedTimeEntryDeleter`
  (needs a `delete_imported` grant) and `remove()`, `removeForTasks()`, `removeForBoard()`, `removeForSource()` work on ownerless
  imported entries. An entry of a locked or submitted period of the deleted user, or with no identity while timesheets are on,
  is kept and not counted. `TimeEntriesRemovedEvent` records each one, a refusal included: count, `keptCount`, `secondsByTask`,
  `userIds`, `personRefs` and `actorId` (pass `$actorId` to the bulk calls). **The bundle keeps no audit table: store that event**,
  or the removal leaves only a log line of counts. `removeForUser()` reaches only entries that still have that user, and is
  for re-association only.
- **A timer still running when the user is deleted** is stopped and saved, so the time in progress is not lost: the end is the
  deletion time, `isClosedByUserDeletion()` is true (`metadata.closed_by = user_deletion`, kept by anonymising), and it gets the
  name and reference like the rest. `$entityManager->remove($user)` does this. It is a normal stop: `TIMER_STOP` fires while the user
  still exists (task totals follow; a listener that throws stops the delete, and a retry goes through), a locked period does not
  refuse it or block the deletion (`TIMESHEET_LATE_ENTRY` tells the approver, and the entry stays locked), and no overlap is checked
  (ownerless entries are not in any overlap check). It flushes at `remove()`, before the delete, your pending work included. Wrap
  the delete in a transaction to make it one unit; otherwise, if the delete fails, the user keeps a stopped, marked entry. Do not
  `remove()` a user from inside a flush.
- **A delete that runs no bundle code gets none of this:** a bulk DQL `DELETE` of users, raw SQL, a database cascade from a parent table. Code that
  deletes `ActiveTimer` rows loses the timer in the same way. `time_track_active_timers` is `ON DELETE CASCADE`, so the running timer goes with the
  user, however long it ran, with no row, event or log left, and the entries stay with no name and no reference, where no erasure or export can find
  one person's. **`$people->release($user, $name)` is required before such a delete.** A `SET NULL` on the timers table could keep the row, but a
  foreign key runs no code, so nothing could close the timer at the deletion: only a later sweep ending it at the last heartbeat, with no name or
  exact end.
- **A timer started between `remove()` and the flush is deleted with the user.** Keep the two close together.
- **A delete that fails changes nothing on the user's rows.** The name snapshot and `anonymise_on_user_delete` are written after the commit. If the
  process dies between the commit and that write, the rows stay with no name or reference: `anonymiseUnidentified()` reaches them.
- **Period lock:** a deleted user's entry stays locked by their timesheets (found by `owner_ref`); a former person has none; an entry with no
  identity has an unknown owner and is not writable while timesheets are on. Anonymising outranks the lock, and it changes no time. An
  approved timesheet keeps its stored totals, while a rebuild from the live entries differs.
- Task totals (`loggedSecondsByTask()`) include them. Worked time, balance, timesheets and overlap checks read one user's live
  entries and never see them. **Anonymising a living account takes its hours out of those views.** A deleted user's timesheet has
  stored totals, but a rebuild reads zero, so an approver gets `stale`.
- Anonymising clears the name, the reference, `description`, `task_title_snapshot` and `metadata` (except the `closed_by` mark), and hashes
  `external_id`, so a re-sync still finds the row and writes nothing. `task_id`, `board_id_snapshot`, `scope_key` and the times stay, because totals and
  tenant isolation need them, and so do `billable`, `source`, `external_source` (which can hold a directory id) and `created_at`. Task totals stay. Timesheets
  keep `owner_ref` and `totals.byTask`, which can re-link task time to an id. The hash is unsalted: whoever holds the remote id can recompute it, and a small
  id space can be enumerated (a 6-digit id took 0.36 s in the audit). An explicit manager removal of an anonymised row forgets it, and a later re-sync creates it again with the name.

### Moving imported time between owners (1.16)

```php
$result = $writer->moveToPerson($source, $user, new ImportedTimeEntryPerson($personId, $name, $tenant), actorId: $managerId); // unmapped
$result = $writer->moveToUser($source, new ImportedTimeEntryClaim($user, $personId, $tenant), actorId: $managerId);           // mapped
$result = $writer->moveBetweenUsers($source, $previous, $user, $tenant, actorId: $managerId);                                 // remapped
$result = $writer->moveToPerson($source, $user, $person, ['clickup-1', 'clickup-2']);                                         // only these rows
// $result->moved, keptLocked, refusedScope, secondsMoved
```

- **Never deletes.** Only the owner columns change: seconds, times, task, description, external id and scope stay. One transaction, nested into yours when there is one. Bulk statements, no flush; loaded rows of a move are detached, so read them again, and a change you had pending on one is not written. The rows being moved are locked by primary key until the commit, so a concurrent move or sync of those same rows waits, and nothing else does.
- Only `source = import` rows of exactly that source that the `from` owner holds now, and never an anonymised one.
- **Period lock:** a row in a locked or submitted period, of its current owner or of the new user, stays and counts in `keptLocked`. There is no exception. With timesheets off it is always 0.
- **Scope, the claim's rule:** the row's scope must be exactly the target's, a null one included (the scope provider's for that task when it gives one, else the one you pass), and **with a scope provider it must be among `scopesFor()` of the new user**. Otherwise the row stays and counts in `refusedScope`. With no provider you vouch for the scope you pass, and a row with no scope moves when you pass none. A deleted user's reference (`user:<id>`) only goes back to that same id.
- **Source and ids are compared exactly**, also on a case-insensitive collation. `$externalIds` must be strings of the id pattern: anything else throws `InvalidImportedTimeEntryException` (`externalIds`, `pattern`).
- **A move to a person writes the name only on the rows it moves,** so one reference can hold two names.
- **A row that stayed mismatches the next sync's row for the new owner:** use `upsertMany($rows, skipMismatches: true)`, and read `$result->mismatched`: skipped rows leave only that count and a warning in the log.
- `TimeEntriesMovedEvent` (`TIME_ENTRIES_MOVED`) carries the source, both owners, the counts and the actor, also when every row stayed, after the move's own commit (so before yours if you wrap it). **The bundle has no audit table: store it.** Task totals do not change, so no `TIME_ENTRY_IMPORTED` fires.
- The bundle cannot know a soft-erased user: do not move time to a person from an account you have erased; anonymise it.

### What the host must do

- **Choose what a delete means:** by default the name, description and note stay with no expiry. Set
  `retention.anonymise_on_user_delete: true`, or call `anonymiseUser()` in your erasure flow. This is pseudonymisation unless you have assessed your
  team size and titles (GDPR recital 26), and it is for your counsel to confirm.
- **Set a retention term** with `anonymiseOwnerlessEndedBefore()` on a schedule. The bundle does not run it.
- **Do not anonymise entries you use as a legal working-time register** within the legal term. The bundle marks its totals `legalRecord: false`.
- **Show names only to those who need them.** If you keep data only for possible liabilities, block it (LOPDGDD art. 32) and do not list it.
- **Store `TimeEntriesRemovedEvent`.**
- **Call `release()` before a bulk DQL or raw SQL delete**, and `stopForUserDeletion()` before code that deletes `ActiveTimer` rows.
- **Keep `external_id` opaque:** it is hashed on anonymise, but only then, and long random ids resist enumeration where short or sequential ones do not.

### An erasure chain that must not flush

`stopForUserDeletion()`, `release()` and `anonymiseUser()` flush when a timer is running, open a nested transaction and fire `TIMER_STOP`, whose listeners may
write. An erasure chain whose erasers must not flush or open transactions (UserKit's `PersonalDataEraser`) should stop the timer **before** the chain:

```php
if ($eraser->check($user, $actor) === []) { // no vetoes: a vetoed erasure stops nobody's timer
    $timers->stop($user);                    // public since 1.12; saves the entry under the user, with its note
}
$eraser->erase($user, $actor);               // throws on a veto; your eraser blanks that note like any other
```

Limits: it is not atomic, so if the erase fails the timer stays stopped (the time is safe). A timer started between the stop and the eraser is still deleted by an
eraser that deletes `ActiveTimer` rows. `stop()` adds no `closed_by` mark, which suits a user who is not being deleted. A `TIMER_STOP` listener that throws stops
the erasure before it starts.

## Read ports and personal data (1.35)

DDD-host checklist B7: a host lists, counts, exports and erases TimeTrack data through ports that take ids and return
views, so it writes no DQL over the bundle's entities. Every port below is a public service, works with both user
mappings (`relation` and `id`) and with the private entity manager, and keeps nothing between calls (worker mode). None
of them applies an access rule: your permission port decides what a viewer may read, then passes the owner ids and
scope keys.

### Entries: `Query\TimeEntryQueryInterface`

```php
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Query\TimeEntryCriteria;
use Nowo\TimeTrackBundle\Query\TimeEntrySort;

// A member's last 30 days in two tenants, on one task, newest first.
$criteria = new TimeEntryCriteria(ownerIds: [$userId], scopeKeys: ['org-a', 'org-b'], taskIds: [$taskId], startedFrom: $from, startedTo: $to);
$page     = $entries->list($criteria, TimeEntrySort::StartedAtDescending, limit: 100, offset: 0);
$total    = $entries->count($criteria);
$seconds  = $entries->totalSeconds($criteria);

// An overlap check before a correction: entries that overlap 09:30–12:30, leaving out the entry being corrected.
$clash = $entries->count(new TimeEntryCriteria(ownerIds: [$userId], overlapFrom: $start, overlapTo: $end, excludeEntryIds: [$entryId]));

// Batch jobs and exports: keyset pages by id.
$after = '';
while ([] !== $rows = $entries->listAfter(TimeEntryCriteria::ofOwner($userId), $after, 500)) {
    foreach ($rows as $record) {
        $after = $record->entry->id;
    }
}

$record = $entries->find($entryId); // one entry by its exact id, or null
```

- **Keyed.** A criteria needs owner ids, scope keys, task ids or board ids (`InvalidArgumentException` otherwise). Other
  filters: `startedFrom` / `startedTo` (both inclusive), `overlapFrom` + `overlapTo` (ends after the first, starts before
  the second: touching entries do not overlap), `sources` (`TimeEntrySource` cases), `serviceAccount`, `excludeEntryIds`.
  At most 1 000 values per list; an empty list matches nothing.
- **Exact.** Ids, scope keys, task and board ids compare byte for byte: rows are checked again in PHP before a page is
  cut, so on a case-insensitive MySQL collation `TASK-1` neither lists nor shifts the pages of `task-1`. A value with a
  leading or trailing blank or an invisible character is refused.
- **Owned entries only.** An anonymised entry, a former person's entry and a deleted user's entry have no owner: read
  them through `FormerPersonTimeEntries` ([Entries with no user](#entries-with-no-user-115)).
- **Views.** `TimeEntryRecord` holds the `TimeEntryView` the events carry (ids, task, board, scope, times, source,
  channel, service-account mark) plus the note, the description, the billable flag, the stored metadata and the creation
  time. Nothing is hydrated, so nothing enters an entity manager. The task title is the stored snapshot: mask titles of
  imported entries as the bundle's surfaces do (`TimerService::hiddenTaskEntryIds()`).
- **Paging.** `list()` takes 1–1 000 rows and an offset; deep offsets read every matching row before them, so prefer
  `listAfter()` for exports and jobs. `count()` and `totalSeconds()` read the key columns in batches.

### Running timers: `Query\RunningTimerQueryInterface`

```php
$running = $timers->runningOf($userId);                         // RunningTimerView|null: TimerSnapshot + ownerId + lastHeartbeatAt
$page    = $timers->startedBefore($cutOff, $afterTimerId, 200); // e.g. a forgotten-timer job, keyset by timer id
```

Stopping a timer stays with the timer services (`HostTimerApi`, `TimerService`). Entry corrections and manual entries by id: [Writes by id (1.40)](#writes-by-id-140).

### Client sessions and device sign-ins: `Query\ClientAccessQueryInterface`

```php
$sessions = $clientAccess->sessionsOf($userId);              // ClientSessionView list, newest first, unexpired
$all      = $clientAccess->sessionsOf($userId, true);        // expired ones the purge has not removed too
$devices  = $clientAccess->deviceSignInsOf($userId);         // DeviceSignInView list
$revoker->revokeById($sessionId, $actor);                    // sign out one device (1.34)
$revoker->revokeAllForUser($userId);                         // sign out everywhere (1.35): returns how many
```

Views never hold a token hash or a device code. `revokeAllForUser()` is authorised by the host, like
`revokeByCredential()`, and records `session_revoked` with the reason `all` on the client API audit event.

### Personal data: `PersonalData\TimeTrackPersonalDataInterface`

```php
use Nowo\TimeTrackBundle\PersonalData\TimeEntryErasure;

$sections = $personalData->export($userId);   // GDPR arts. 15 / 20, JSON-ready
$result   = $personalData->erase($userId);    // GDPR art. 17, the bundle's rule: anonymise
$result   = $personalData->erase($userId, TimeEntryErasure::FreeText); // keep the owner, clear the free text
```

- **Export** sections: `entries` (every owned entry), `formerPersonEntries` (entries left under the user's former
  reference `user:<id>`), `runningTimer`, `clientSessions`, `deviceSignIns`, `linkedDeviceSignIns` (2.2.1), `monitoringAcknowledgements`,
  `monitoringUploads`, `timesheets`, `balanceLedgerLines` (balance movements: kind, seconds, reason),
  `timesheetEvents` (the audit trail of the user's own timesheets, comments included), `calendarConnections`,
  `calendarRules`, `calendarSuggestions`. Dates are RFC 3339 strings; no token hash, device code or calendar credential.
  Pass cells through `TimeEntryCsv::cell()` for a CSV. Most sections select scalars; `formerPersonEntries` and the
  calendar sections load entities into the bundle's entity manager (dropped at the request boundary with the private
  manager; with the shared one they stay managed until you clear it). Nothing is written.
- **Erasure** deletes the client sessions, device sign-ins, monitoring upload intents and monitoring acknowledgements, and
  treats the entries as you choose:
  - `TimeEntryErasure::Anonymise` (default, as `FormerPersonTimeEntries::anonymiseUser()`): the time stays, the person
    goes. The entries lose their owner and free text and leave the user's worked time, balance and timesheets.
  - `TimeEntryErasure::FreeText`: the description and `metadata.note` of each owned entry are cleared, and since
    1.40.1 every other string value of the metadata except `closed_by`, `origin` and `provider` (a key such as
    `comment` may hold personal text); the owner and your number and boolean keys stay. For a host that keeps an
    anonymised account row.
  In both modes the entries under `user:<id>` are anonymised, **no entry is deleted and no time changes** (owner rule),
  and a locked or submitted period does not block it (the timesheet hash covers ids, tasks, times and sources only).
- **Kept:** timesheets and their audit trail (the working-time register, GDPR art. 17.3.b) and calendar connections:
  call `CalendarConnectionService::revokeAllFor()`, which revokes the host grant before deleting them
  (`TimeTrackErasureResult::$calendarConnectionsKept` counts them, and a warning is logged when it is not 0). Evidence files of activity monitoring live in your
  store: delete them there.
- **A running timer refuses the erasure** (`PersonalDataErasureRefusedException`, code `timer_running`, nothing written):
  stop it first (`HostTimerApi::stop()` or `TimerService::stopForUserDeletion()`), so the time in progress is saved and
  cannot bring the person back afterwards. A timer started after the erasure is a new one.
- **One unit:** one transaction of the bundle's runner (a savepoint inside yours), bulk statements only: no entity
  manager is flushed, cleared or reset, so it fits an erasure chain that must not flush. Idempotent; a lock conflict
  surfaces as `TransientStorageFailure` with nothing committed (retry).
- An id with a leading or trailing blank or an invisible character is refused (`invalid_user_id`). Rows are matched by
  owner and checked again byte for byte before they are deleted or anonymised (by id): on a case-insensitive collation
  of your string user ids, `erase('ABC')` never touches `abc`'s rows. An id the owner column cannot hold (`'nobody'`
  with integer ids) owns nothing.

## Entries without a task (1.36, Beacon spec 250)

Opt-in (`entries.allow_without_task: true`, default false). Use case: a salesperson who uses no task boards times a
customer from the host's CRM area; the host links the entry to the customer on its own side by the ids it gets back,
so TimeTrack needs no CRM knowledge.

```php
use Nowo\TimeTrackBundle\Timer\HostTimerApi;

// Starts now (stopping the actor's running timer first, as start() does); scope: one of the actor's, or the current one.
$start = $hostTimerApi->startWithoutTask($actorRef, description: 'Call with ACME', channel: 'api', scopeKey: 'org-42');
$crmLinks->link($start->timer->timerId, $customerId);
$entry = $hostTimerApi->stop($actorRef);         // TimerEntrySnapshot: withoutTask true, description, scopeKey
$crmLinks->link($entry->entryId, $customerId);

// A past range without a timer.
$manualEntries->create($user, null, $start, $end, TimeEntrySource::Manual, description: 'Visit ACME', scopeKey: 'org-42');
```

- Storage: `task_id` is NULL, the title snapshot is empty, the free text is the entry's `description`. `getTaskId()`
  returns `''`; use `hasTask()` / `taskIdOrNull()`.
- Validation: the same as with a task (backdate clamping, overlaps, 24 h, age, period lock of the entry's scope). A
  requested scope must be one of the user's, compared byte for byte; anything else is refused like an unknown task
  (`TaskNotTrackableException`, `ManualEntryException::NOT_TRACKABLE`), with no hint whether the scope exists. Without a
  scope provider the entry has no scope and a requested one is refused.
- One running timer per user, with or without a task (the same lock and unique index; REQ-RUNTIME-003).
- Who sees and edits it: its owner; anyone else (a manager, an admin role, a granting access-check listener) only within
  their own tenant scopes (`TeamAccessGuard::isWithinTenant()`, checked after the listeners). An entry without a scope is
  then the owner's only. It is never reached through task or board permissions: no query by task or board matches a
  NULL task.
- Reports, exports, timesheets: grouped as "No task" (task id `''` in `byTask` and in the timesheet hash); the client
  API and export rows carry `taskId: ""` and `withoutTask: true`. The host billable default is not asked (per task).
- Events: `TimerStartedWithoutTaskEvent` (ids only) instead of `TIMER_START`; `TIMER_STOP`, `TIME_ENTRY_CREATED` and
  `TIME_ENTRY_ACCESS_CHECK` as for every entry, with `getTaskId()` null.
- Erasure and anonymisation: the 1.35 rules, unchanged (the description is cleared with the person's other free text;
  the time stays).
- Idle decisions on such a timer: `split` continues it without a task (same description and scope); `reassign` gives
  the span to a task and `continue` resumes without one.

## Device-flow scope families (2.2, Beacon Desktop)

Since 2.2.0 (Beacon Desktop BD-2, design `time-track-desktop` `docs/design/BEACON_DESKTOP.md` §4). The device flow can
issue tokens for **another bundle's client API** (e.g. ChatKit's `/api/chat/v1`) next to the time API:

- a **scope family** is a named set of scopes; TimeTrack owns `time` (every `ClientScope`), the host registers others
  (e.g. `chat` with `chat:read`, `chat:write`);
- **one token per family**: a device request names the scopes of one family only (`400 invalid_scope` otherwise); a
  `chat` token never opens the time API and a `time` token never opens the chat API (both `403 insufficient_scope`);
- **deny by default**: an unregistered family or scope is refused; a family other than `time` needs an explicit scope
  list;
- each token is **revocable on its own** (its own credential and session id), and the tokens of one device share a
  **device key**, so an account page can show one row per device with one badge per family (Q-BD-19);
- installed `time` clients (contract v0.4) change nothing: no `family` means `time`.

### Register a family

```yaml
nowo_time_track:
    client_api:
        device_flow:
            enabled: true
            scope_families:
                chat: { label: Chat, scopes: { 'chat:read': 'Read your conversations', 'chat:write': 'Send messages' } }
```

or a service (autoconfigured):

```php
use Nowo\TimeTrackBundle\Client\DeviceScopeFamily;
use Nowo\TimeTrackBundle\Client\DeviceScopeFamilyProviderInterface;

final class ChatScopeFamily implements DeviceScopeFamilyProviderInterface
{
    public function scopeFamilies(): iterable
    {
        yield new DeviceScopeFamily('chat', 'Chat', ['chat:read' => 'Read your conversations', 'chat:write' => 'Send messages']);
    }
}
```

The configured `client_api.credential_issuer` must implement `ClientFamilyCredentialMinterInterface` (the build fails
when a family other than `time` is registered without one; the default opaque issuer mints `time` credentials only): `mintForFamily()`
receives a `DeviceCredentialRequest` (user, client type and name, family, scopes, device key) for every approved request,
`time` included, so the credential list can group a device's families.

Since 2.2.1 (audit ZBV-v-I2) the minter may also implement `OrphanedCredentialRevokerInterface`: when the token
exchange is refused after the approval (account disabled, family unregistered), the bundle calls
`revokeOrphanedCredential($credentialId, $scopeFamily)` after the refusal is flushed, best effort (a failure is logged and
the device still gets `access_denied`). It must be idempotent and must not throw for an unknown id. Either way the
refusal is audited as `device_denied` with the credential id (twice when two polls race, audit ZCC-I3).

### Two approvals chained by the app (Q-BD-6)

1. The app signs in for time as today: `POST /api/time-track/v1/device/code {clientType, clientName}`.
2. Right after, it asks for chat **proving it is the same device** with the token it just got:
   `POST /api/time-track/v1/device/code {clientType, clientName, family: "chat", scopes: ["chat:read"]}` with
   `Authorization: Bearer <time token>`. The new request gets that token's device key (`401 invalid_token` when the token
   is not valid) and **only that token's owner may approve it**: another user's approval is refused
   (`DeviceAuthorizationRefusedException::LINK_MISMATCH`, the request is denied, nothing is minted). The approval page
   names the family ("… asks for access to: Chat"), one family per approval.
3. `POST device/token` answers `{token, expiresAt, scopes, scopeFamily: "chat"}`.

The default verification page and a host page built on `DeviceAuthorizationService` read the family with
`DeviceAuthorization::getScopeFamily()` and its label with `DeviceScopeFamilyRegistry::labelOf()`. A host page also
skips a request for which `DeviceAuthorizationService::isFamilyAvailable()` is false (its family or a scope is no longer
registered) and catches `DeviceAuthorizationRefusedException` from `approve()` (`reason`: `device_link_mismatch` or
`scope_family_unavailable`; the request is already denied, nothing was minted).

### Authenticate another bundle's API with a family token

`ClientFamilyTokenAuthenticator` (public service) runs the time API's checks in the same order (per-IP limit, token,
account status, credential state, per-token limit), then the **family**, the scope and the tenant context; every refusal
is audited with the family. No HTTP types: pass the header values, map the answer.

```php
use Nowo\TimeTrackBundle\Client\ClientFamilyTokenAuthenticator;

final class ChatClientPrincipalResolver implements ChatClientPrincipalResolverInterface // ChatKit's port
{
    public function __construct(private ClientFamilyTokenAuthenticator $tokens, private UserRepository $users)
    {
    }

    public function resolve(string $bearer, ChatRequestContext $ctx): ?ChatClientPrincipal
    {
        $answer = $this->tokens->authenticate('chat', 'Bearer ' . $bearer, $ctx->requiredScope, $ctx->clientIp, $ctx->header($this->tokens->contextHeaderName()));
        if (!$answer->isAuthenticated()) {
            return null; // or map $answer->error / status() / headers() to the API's error answer
        }
        $p = $answer->principal;

        return new ChatClientPrincipal($this->users->chatUser($p->user), $p->scopes, $p->tokenId, $p->contextKey);
    }
}
```

The family API's `logout` calls `revoke('chat', $authorizationHeader, $clientIp)` (a token of another family is left
alone; the time API's `logout` likewise revokes `time` tokens only).

### Tenant context header (Q-BD-7)

With `client_api.context.policy` set, a client names its tenant on every request in the context header (Beacon:
`X-Beacon-Context: personal` or `org:<uuid>`). The `ClientContextPolicyInterface` is asked **on every request**
(`contextFor()`): an unknown, revoked or non-existent context answers `403 context_forbidden`. The policy must be
read-only: it never changes the user's stored web context. On the time API the allowed key is a request attribute
that host services read through `CurrentClientContext::key()`; `GET me` lists `contexts` (`contextsOf()`) and the
`context` of the request. For another family the key is `ClientFamilyPrincipal::$contextKey`.

### Modules the person may use (2.4, Beacon spec 262)

Implement `ClientCapabilitiesProviderInterface` from your web permission checks and set
`client_api.capabilities.provider`:

```php
use Nowo\TimeTrackBundle\Client\ClientCapabilitiesProviderInterface;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

final readonly class WebPermissionCapabilities implements ClientCapabilitiesProviderInterface
{
    public function __construct(private Permissions $permissions) {}

    public function canUse(TimeTrackUserRef $user, string $scopeFamily, ?string $context): bool
    {
        return match ($scopeFamily) {
            'time'  => $this->permissions->canTrackTime($user->id, $context),
            'chat'  => $this->permissions->canUseMessages($user->id, $context),
            'tasks' => $this->permissions->canUseTasks($user->id, $context),
            default => false, // deny by default
        };
    }
}
```

`$context` is the context the request named (null in the device flow and at `POST login`: answer "in at least one
context"). Clients read `GET me/capabilities`; a lost module answers `403 module_forbidden` on every request of its
tokens. Host approval pages call `DeviceAuthorizationService::isGrantableTo()` before showing a request. See
[CLIENT-API.md](CLIENT-API.md#changes-in-24-capabilities-beacon-spec-262).

### Connected apps (Q-BD-19)

`ConnectedClientDevicesQueryInterface::devicesOf($userId)` groups the user's unexpired sessions by device key:
one `ConnectedDeviceView` per device with `scopeFamilies` (badges, `time` first), `clientName`, `lastUsedAt` and its
`ClientSessionView`s (each with `scopeFamily`). Revoke one badge with `ClientTokenRevoker::revokeById($session->id, $actor)`
or the credential with `revokeByCredential()`. `ClientSessionView` and `DeviceSignInView` carry `scopeFamily` too.

## Time subjects and activity types (2.1, Beacon spec 256)

Time can be logged on a **subject** — a customer (a phone call), the organisation itself under an internal category —
alone or next to a task ("this task, for customer X"), with an optional **activity type** (`support_call`,
`internal_meeting`). The subject lives in your application; TimeTrack stores only `subject_type` + `subject_id` (opaque,
compared byte for byte) and asks your provider who may use and see it. Design and decisions:
[design/TIME_SUBJECT_ACTIVITY.md](design/TIME_SUBJECT_ACTIVITY.md).

| Allocation | `task_id` | subject | Allowed when | Checked on write |
|---|---|---|---|---|
| Task alone | set | — | always | task access (as before) |
| Task + subject | set | set | the type's provider `allowsTask()` | task access **and** `canLog(subject)` |
| Subject alone | — | set | any type | `canLog(subject)` |
| Nothing | — | — | `entries.allow_without_task` and not `entries.require_allocation` | the 1.36 scope check |

### Standard Symfony host

Implement the provider; autoconfiguration registers it (one per type, the build refuses two):

```php
use Nowo\TimeTrackBundle\Subject\{TimeSubjectContext, TimeSubjectLabel, TimeSubjectLabels, TimeSubjectProviderInterface, TimeSubjectRef, TimeSubjectSearch};

final readonly class CustomerSubjectProvider implements TimeSubjectProviderInterface
{
    public function __construct(private CustomerRepository $customers, private CustomerAccess $access) {}

    public static function subjectType(): string { return 'customer'; }
    public static function allowsTask(): bool { return true; }      // customer time may sit on a task entry

    public function canLog(TimeSubjectContext $context, TimeSubjectRef $subject): bool
    {
        return $this->access->canLog($context->actor->id, $context->scopeKey, $subject->id); // deny by default
    }

    public function describe(TimeSubjectContext $context, array $subjects): TimeSubjectLabels
    {
        $labels = [];
        foreach ($this->customers->readableBy($context->actor->id, $context->scopeKey, array_map(fn (TimeSubjectRef $s) => $s->id, $subjects)) as $customer) {
            $labels[] = new TimeSubjectLabel(new TimeSubjectRef('customer', $customer->id), $customer->name, 'https://app.example/customers/' . $customer->id, $customer->city);
        }

        return TimeSubjectLabels::of($labels);                       // left out = not readable (one answer)
    }

    public function search(TimeSubjectContext $context, TimeSubjectSearch $search): array
    {
        return array_map(fn ($c) => new TimeSubjectLabel(new TimeSubjectRef('customer', $c->id), $c->name), $this->customers->search($context->actor->id, $context->scopeKey, $search->query, $search->limit));
    }
}
```

Activity types: implement `ActivityTypeProviderInterface::activityTypesFor(string $scopeKey): ActivityTypeList` (retired
types with `active: false`) and alias the interface to your service. Then write with allocations:

```php
use Nowo\TimeTrackBundle\Subject\{TimeAllocation, TimeAllocationPatch, TimeSubjectRef};

$hostTimerApi->startAllocated($actorRef, TimeAllocation::subject(new TimeSubjectRef('customer', '42'), 'support_call'), scopeKey: 'org-1');
$hostTimerApi->startAllocated($actorRef, TimeAllocation::task('task-7', new TimeSubjectRef('customer', '42')));
$hostTimerApi->allocate($actorRef, TimeAllocationPatch::keep()->withSubject(new TimeSubjectRef('customer', '42'))); // running timer
$hostTimerApi->stop($actorRef, allocation: TimeAllocationPatch::keep()->withActivityType('support_call'));
$operations->createManual($ownerId, new ManualTimeEntryDraft(null, $start, $end, allocation: TimeAllocation::subject($customer)));
$operations->correct($ownerId, $entryId, $version, TimeEntryCorrection::allocation(TimeAllocationPatch::keep()->withSubject($other)));
```

Read with the rule-free query port and label for the viewer (one `describe()` per type per page):

```php
$records = $query->list(new TimeEntryCriteria(scopeKeys: ['org-1'], subjects: [new TimeSubjectRef('customer', '42')]));
$labels  = $labelResolver->forPage(new TimeSubjectContext($viewerRef, 'org-1'), array_map(fn ($r) => $r->entry, $records));
// $labels->get($record->entry->subject) === null → show {type, hidden: true} without the id
$totals  = $totalsQuery->totals(new TimeEntryCriteria(scopeKeys: ['org-1'], startedFrom: $from, startedTo: $to), TimeEntryGrouping::Subject);
```

### DDD / hexagonal host

The same two interfaces take bundle value objects only (`TimeSubjectContext`, `TimeSubjectRef`, `TimeSubjectSearch`,
`ActivityTypeList`; the actor is a `TimeTrackUserRef`), so the adapter sits in your infrastructure layer and your domain
sees no TimeTrack type. Without autoconfiguration tag the provider `nowo_time_track.time_subject_provider`. Keep nothing
between calls (FrankenPHP worker mode, kernel reset false): cache in your own tag-aware pool if you need to.

### Rules worth knowing

- Deny by default: an unknown type, an unknown id and no access all answer `subject_forbidden`; a provider exception is
  logged (class only) and read as denied, never a 500.
- `canLog` is asked for the **owner** of the time (also when you write for another person), in the entry's scope (the
  task's scope on a task entry); a correction asks it only when the subject changes. Changing the subject of a running
  task timer checks the task access again first.
- `require_allocation` is a UX gate: listen to `TimeEntryUnallocatedEvent` for the time system stops store unallocated;
  `require_activity_type` needs v0.4 clients (UPGRADING "To 2.1.0").
- `entries.require_allocation: true` refuses **interactive** stops (client API, `HostTimerApi::stop()`, the web UI) and
  manual entries with neither task nor subject (`TimeAllocationRequiredException`, 409); stops the bundle triggers
  (start-replacing, switch, idle, user deletion) store the entry unallocated and dispatch `TimeEntryUnallocatedEvent`.
- Billable default of a new entry: explicit flag, then the activity type's `defaultBillable`, then
  `TimeEntryBillableDefaultProviderInterface`, then none.
- Every bundle read surface masks a subject the viewer may not read; erasure keeps the allocation (it describes the
  work, not the person); the timesheet `entries_hash` does not cover it.
- Built-in "the tenant itself" provider: `entries.subjects.scope_subject.enabled: true` (type `organisation`, never next
  to a task, over your timesheet scope port).
- After a back-fill, run `nowo:time-track:entries:check-allocation` (see UPGRADING "To 2.1.0").

## Entry access by id (1.37)

Since 1.37.0 (DDD-host checklist B1/B8) a host asks "may this user see / change entry X?" by ids, through the public
`Nowo\TimeTrackBundle\Access\TimeEntryAccessInterface`. A host with a private entity manager (or users that are no
Doctrine entities) no longer loads `TimeEntry` in its own manager or re-implements ownership in DQL.

```php
use Nowo\TimeTrackBundle\Access\TimeEntryAccessInterface;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

final class CorrectOwnEntry
{
    public function __construct(private TimeEntryAccessInterface $entryAccess) {}

    public function __invoke(string $userId, string $entryId): void
    {
        $entry = $this->entryAccess->viewableEntry($userId, $entryId); // TimeEntryView or null
        if ($entry === null || !$this->entryAccess->canEditEntry($userId, $entryId)) {
            throw new NotFoundHttpException(); // one answer for unknown, foreign and out-of-reach entries
        }
        // ... change it through the bundle's services: under the owner's lock they re-check owner, state and period lock
    }
}

// Admin roles are read from the reference; a bare id carries none.
$entryAccess->canViewEntry(TimeTrackUserRef::ofId(User::class, $id, '', $roles), $entryId);
```

| Method | Granted to |
|---|---|
| `canViewEntry($user, $entryId)` | the owner; an admin role (`security.admin_roles`); a manager of the owner (`security.manager_can_view_entries`); an ownerless or anonymised entry: admin roles only |
| `canEditEntry($user, $entryId)` | the owner; a manager of the owner (`security.manager_can_edit_entries`); an admin role. Never an imported, ownerless or anonymised entry, nor one in a locked or submitted period (checked after the listeners) |
| `viewableEntry($user, $entryId)` | `canViewEntry()`, returning the `TimeEntryView` snapshot (null otherwise) |

- `$user` is your user id (`string|int`, what `getId()` returns) or a `TimeTrackUserRef`. A reference that carries your
  security user object (`TimeTrackUserRef::of()`) is checked as that object; a bare id or `TimeTrackUserRef::ofId()` is
  checked by its id with the reference's role names, and your team / scope ports (`TeamDirectoryInterface`,
  `TimesheetScopePolicyInterface`) receive that reference (no user object). A reference whose object has another id
  than the reference (`getId()`, byte for byte) is refused.
- Listeners of `TimeEntryAccessCheckEvent` may receive an internal `UserInterface` that carries only the id and the role names (no
  login, no other data, not your class). Resolve it by `getId()` only, never by its identifier or login, and answer
  `[]` / `false` for a user object of a class you do not know.
- Build a role-carrying reference (`TimeTrackUserRef::ofId(…, $roles)`) from your current data at check time; never
  reuse roles kept in a queued message or a cache.
- Then both access-check events run, in this order: `nowo_time_track.time_entry.access_check`
  (`TimeEntryAccessCheckEvent`, entity based) and the ids-only `nowo_time_track.time_entry.id_access_check`
  (`TimeEntryIdAccessCheckEvent`: `getUserId()`, `getEntryId()`, `getOwnerId()`, `getTaskId()`, `getScopeKey()`,
  `getAction()`, `grant()` / `deny()`), with action `view` or `edit`. They run for the bundle's own checks too
  (`edit_billable`, `delete_imported`). A listener may narrow any decision; for an entry without a task it never
  grants outside the user's tenant scopes (the 1.36 rule is applied before and after the listeners). Handle unknown
  actions with a default branch: `view` also reaches `access_check` listeners when `TimeEntryBillableService::changeById()`
  refuses (it asks whether the actor may see the entry), and a listener that grants only `edit` should also decide
  `view`.
- A failure of the store, a host port or a listener is logged (its class only) and answered as the same refusal.
- No oracle: an unknown id, a look-alike (`ABC…` for `abc…`, a padded id), an unusable user id (empty, padded, invisible
  characters, longer than 128), another user's entry and an entry out of reach all answer `false` / `null`.
- Exact ids (audit LQ rule): the entry id is a bound `id = ?` parameter and is compared again with `===` in PHP; user ids
  compare byte for byte (`'07'` is not `7`).
- Worker mode (kernel reset false): nothing is kept between calls; the entry and your ports are read at every call. An
  answer holds as of the check only: write through the bundle's services, which under the owner's lock re-check the
  entry's owner, its state and the period lock (REQ-RUNTIME-003).

## Writes by id (1.40)

Since 1.40.0 a host corrects and creates entries and ends client sessions **by ids**, through public ports that take
user ids or a `TimeTrackUserRef` (never a Symfony user, never an entity) and return read models. With them a host no
longer writes the bundle's tables with its own entity manager (`persist(new TimeEntry(…))`, a DBAL `UPDATE` of
`time_track_entries`, `remove($clientToken)`), which 2.0 (private entity manager only) no longer stores. Every write
goes through the bundle's own manager and stores, with both user mappings (`relation` and `id`). The ports are not
deprecated: 2.0 keeps them.

| Port (public service id) | Method | Replaces in the host |
|---|---|---|
| `Client\ClientSessionRevokerInterface` | `revokeSession(ClientSessionId\|string $sessionId, TimeTrackUserRef\|string\|int $actor): ClientSessionRevocation` | removing a `ClientToken` with your manager |
| | `revokeAllForUser(string $userId): int` | a DQL `DELETE` of the user's tokens |
| `Entry\TimeEntryOperationsInterface` | `correct(TimeTrackUserRef\|string\|int $owner, string $entryId, string $expectedVersion, TimeEntryCorrection $correction): TimeEntryCorrectionResult` | a DBAL `UPDATE` of an entry |
| | `createManual(TimeTrackUserRef\|string\|int $owner, ManualTimeEntryDraft $draft): TimeEntryRecord` | `persist(new TimeEntry(…))` |
| `Query\TimeEntryQueryInterface` (1.35) | `find(string $entryId): ?TimeEntryRecord`, with `TimeEntryRecord::version()` (1.40) | reading `TimeEntry` with your manager |

The host authorises every call (who may act for that owner); the bundle scopes the write to the owner and applies its
own rules. Ids compare byte for byte: `'07'` is not `7`, `ABC` is not `abc`, and an id with a leading or trailing blank
or an invisible character names nobody.

### Read an entry by id

```php
use Nowo\TimeTrackBundle\Query\TimeEntryQueryInterface;

$record = $entries->find($entryId);         // TimeEntryRecord|null: no lock, no access rule, no entity
if ($record === null || $record->entry->userId !== $userId) {
    throw new NotFoundHttpException();       // also for another user's entry
}
$record->entry->startedAt;                   // TimeEntryView: ids, task, board, scope, times, source
$record->note;                               // metadata.note
$record->metadata;                           // the stored metadata (e.g. your `auto_stopped` mark)
$version = $record->version();               // pass it back to correct()
```

Use it wherever you read an entry for a notice, a form or a revision (e.g. a timer auto-stop notice that keeps only the
entry id). `TimeEntryAccessInterface::viewableEntry()` (1.37) does the same with the bundle's access rules.

### Correct an entry (GDPR art. 16)

```php
use Nowo\TimeTrackBundle\Entry\TimeEntryCorrection;
use Nowo\TimeTrackBundle\Entry\TimeEntryOperationsInterface;
use Nowo\TimeTrackBundle\Exception\PeriodWriteRefusedException;
use Nowo\TimeTrackBundle\Exception\TimeEntryCorrectionException;

final class OwnEntryCorrector
{
    public function __construct(
        private TimeEntryOperationsInterface $timeEntries,
        private Connection $connection,              // the connection of `database.entity_manager`
        private TimeEntryRevisionRepository $revisions, // your own classes from here on
        private TaskTotals $taskTotals,
    ) {}

    public function correct(string $userId, string $entryId, string $version, DateTimeImmutable $start, DateTimeImmutable $end, ?string $note, string $reason): void
    {
        $correction = TimeEntryCorrection::times($start, $end)->withNote($note);   // duration = the new span

        // One transaction: the bundle's write is a savepoint of it, so the revision and the entry commit together.
        $this->connection->transactional(function () use ($userId, $entryId, $version, $correction, $reason): void {
            $result = $this->timeEntries->correct($userId, $entryId, $version, $correction);
            if (!$result->changed) {
                throw new EntryCorrectionRefused('unchanged');
            }
            // $result->before / ->after are TimeEntryRecord read models; ->changedFields lists the columns.
            $this->revisions->add(TimeEntryRevision::fromRecords($result->before, $result->after, $reason));
            $this->taskTotals->adjust($result->after->entry->taskIdOrNull(), $result->after->entry->durationSeconds - $result->before->entry->durationSeconds);
        });
    }
}
```

- **Scope.** Only the entry's owner: an unknown id, another owner's entry, an ownerless or anonymised entry and a
  look-alike id all answer `TimeEntryCorrectionException('not_found')` (no oracle). An imported entry is `read_only`.
- **Optimistic concurrency.** `$expectedVersion` is `TimeEntryRecord::version()` of the record you read (show it in
  the form as a hidden field). Under the owner's lock the row is read again with a row lock (`SELECT … FOR UPDATE`):
  when anything changed meanwhile (times, duration, note or other metadata, billable flag, task, board, scope, owner)
  the answer is `conflict` (409) and nothing is written; read the entry again. Never a lost update.
- **Values.** A null start or end keeps the stored one; without a duration the new span becomes the duration when the
  times change (a duration-only correction keeps the times). Refused: `invalid_range` (end not after start),
  `entry_too_long` (more than 24 h; since 1.40.1 only when the times change, so a forgotten timer's long entry still gets
  a note or duration fix), `entry_in_future` (a changed end beyond the 2-minute skew), `entry_too_old` (since 1.40.1: a
  changed start older than `TimeEntryCorrection::$maxAgeDays`, default `manual_entries.max_age_days`; 0 to 3650),
  `invalid_duration` (longer than the span), `overlap` (another live entry of the owner or the running timer; not for a
  service account). Your own stricter rules (a correction window, a reason) stay in your code, before the call.
- **Metadata** is a patch: `['auto_stopped' => true]` sets a key, `['key' => null]` removes it, other keys stay. Keys
  are 1–64 characters `[a-z][a-z0-9_]*`, values finite scalars (valid UTF-8 strings up to 2 000 bytes), at most 32 keys
  per patch; `note` is cleaned and cut like every note (`withNote(null)` or a blank note removes it); `closed_by` is the
  bundle's own timer mark and is refused (`InvalidArgumentException` when the correction is built). Since 1.40.1 the
  stored metadata may not grow past 32 keys or 16 KB of JSON (`metadata_too_large`, 422); an entry stored larger before
  may still shrink. Only `note` is shown by the bundle; on `erase(…, TimeEntryErasure::FreeText)` every string value
  except `closed_by`, `origin` and `provider` is cleared (numbers and booleans stay), so keep personal text in string
  keys and flags in booleans.
- **Period lock** (owner decision FR-008): the period of the old start **and** of the new start must be neither locked
  (approved included) nor submitted, else `PeriodLockedException` / `PeriodSubmittedException` (409). No role or listener
  overrides it.
- **Nothing changes** (same values): nothing is written and nothing dispatched; `$result->changed` is false.
- **Event** `nowo_time_track.time_entry.corrected` (`TimeEntryCorrectedEvent`: entry id, owner id, `before`, `after`,
  `changedFields`, `occurredAt`) after the bundle's unit, once per real change; a listener that throws is logged and does
  not undo the correction.

| Refusal | Class | Status |
|---|---|---|
| `not_found` | `TimeEntryCorrectionException` | 404 |
| `read_only` | `TimeEntryCorrectionException` | 403 |
| `conflict`, `overlap` | `TimeEntryCorrectionException` | 409 |
| `invalid_range`, `entry_too_long`, `entry_in_future`, `entry_too_old`, `invalid_duration`, `metadata_too_large` | `TimeEntryCorrectionException` | 422 |
| `period_locked`, `period_submitted` | `PeriodWriteRefusedException` | 409 |
| lock wait, deadlock | `Nowo\TransactionContract\TransientStorageFailure` | 409 `CONFLICT_001`: retry |

`httpStatus()` on both exception classes gives the status.

### Create a manual entry for a user id

```php
use Nowo\TimeTrackBundle\Entry\ManualTimeEntryDraft;

// Store-reviewer demo data: three past entries for the reviewer account.
foreach ($samples as [$taskId, $start, $minutes]) {
    $record = $this->timeEntries->createManual($reviewerId, new ManualTimeEntryDraft(
        $taskId, $start, $start->modify("+{$minutes} minutes"),
        metadata: ['origin' => 'store_review'],
        maxAgeDays: 60,            // older than manual_entries.max_age_days is allowed here
    ));
    $record->entry->id;            // TimeEntryRecord of the new entry
}
```

- The rules of `POST entries` (`ManualEntryService::create()`, source `manual`): the task must be one the owner may track
  (`client_api.task_access_checker` and the task provider), or none with `entries.allow_without_task`; end after start,
  at most 24 h, not in the future, not older than `maxAgeDays` (default `manual_entries.max_age_days`; 0 to 3650, checked
  with the metadata rules of corrections when the draft is built, since 1.40.1), no overlap
  (except a service account), period neither locked nor submitted. `manual_entries.enabled` is not required (it gates
  the client API route only). `nowo_time_track.time_entry.created` is dispatched.
- The owner: with `user_mapping: relation` the `user_class` row with exactly that id (an id its identifier column cannot
  hold, e.g. `'abc'` or `'07'` for integer ids, is never sent to SQL); with `user_mapping: id` the bundle's host-user row,
  created with the first entry. A `TimeTrackUserRef` that carries your security user object is used as that user. An
  unknown owner is `ManualEntryException('unknown_user')` (404).

### Revoke a client session by id

```php
use Nowo\TimeTrackBundle\Client\ClientSessionRevokerInterface;

$revoker->revokeSession($sessionId, $userId);    // "sign out this device": Revoked, or NotFound (unknown / not yours)
$revoker->revokeSession($sessionId, TimeTrackUserRef::ofId(User::class, $adminId, '', ['ROLE_ADMIN'])); // admin rule
$revoker->revokeAllForUser($userId);              // "sign out everywhere": how many were ended
```

- Owner by id (byte for byte); `security.admin_roles` from the roles the reference carries (or its user object). **The
  roles of a reference without a user object are trusted as given**: build them from the current security user at call
  time, never from a queued message, a cache or request input. Since 1.40.1 a reference whose class is neither
  `user_class` nor an `accepted_user_classes` entry answers `NotFound` (another principal with the same id is not that
  user). Then
  the `nowo_time_track.client_session.access_check` listeners (they receive the reference). Every other case answers
  `ClientSessionRevocation::NotFound`, idempotent. A real revocation records `session_revoked` (reason `owner` or
  `other`) on the client API audit event, like `revokeById()`.
- One `DELETE` statement on the bundle's manager: nothing is loaded into your manager, nothing flushed or cleared.

### Transactions, erasure and worker mode

- Each write is one unit of the bundle's runner under the owner's row lock: its own transaction when none is open, a
  **savepoint of yours** when you opened one on the same connection (the private manager shares it). Your commit or
  rollback decides; a rollback of yours undoes the bundle's write.
- **GDPR paths join your transaction.** A correction (art. 16) or an erasure (art. 17, `TimeTrackPersonalDataInterface::erase()`,
  1.35) called inside your transaction is part of it, so your own audit or revision rows commit with it or not at all.
  Called outside a transaction, each runs in the bundle's own unit and is committed when the call returns.
- Since 1.40.1 the overlap and period-lock checks read under a shared lock, so an earlier REPEATABLE READ snapshot of
  your transaction (MySQL, MariaDB) cannot hide what others committed since; on PostgreSQL keep READ COMMITTED (the
  default) for such a transaction ([CONCURRENCY, Residual risks](CONCURRENCY.md#residual-risks)).
- Events (`time_entry.corrected`, `time_entry.created`, `session_revoked`) are dispatched when the bundle's unit ends:
  inside your transaction that is **before your commit**. A listener that must only see committed data defers its work
  (Messenger, a post-commit hook of yours).
- A lock conflict surfaces as `TransientStorageFailure` with nothing of the unit committed (`CONFLICT_001`, retry); inside
  your transaction, roll it back and retry the whole unit.
- Stateless: nothing is kept between calls (FrankenPHP worker mode, kernel reset false).

## Task time follows a board move (Unreleased)

Owner decision (Beacon spec 217 FR-008): **the time follows the task.** When TaskBoard moves a task and its subtasks to another board,
also in another tenant, the entries' `board_id_snapshot` and `scope_key` move with it, so the source's reports stop counting those hours
and the target's count them, billing included. `TaskTimeMover` is a public service with no route; the host authorises the move.

Wire it to TaskBoard's `TASK_BOARD_CHANGED` (TaskBoard 2.3, dispatched after the move's commit). The mover trusts the target it is given
and the last call wins, so **take the target from the task's current board when you handle the message, not from the event payload,
and pass the board the move left as `fromBoardId`.** A message handled late, or retried after a later move, then cannot pull the time
back to a board the task already left:

```php
use Nowo\TaskBoardBundle\Event\TaskBoardChangedEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TimeTrackBundle\TaskMove\TaskTimeKeptReason;
use Nowo\TimeTrackBundle\TaskMove\TaskTimeMover;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: TaskBoardEvents::TASK_BOARD_CHANGED)]
final readonly class MoveTaskTime
{
    // TaskBoards: your own service over TaskBoard's tasks and boards; TenantScopes: what your scope provider answers.
    public function __construct(private TaskTimeMover $mover, private TaskBoards $tasks, private TenantScopes $scopes) {}

    public function __invoke(TaskBoardChangedEvent $event): void
    {
        // The task's board now, read at handling time; a later move may have happened since the event.
        $boardId = $this->tasks->currentBoardIdOf($event->getTask()->getId());
        if ($boardId === null) {
            return; // the task is gone: its time stays where it is
        }
        foreach (array_chunk($event->taskIds, 200) as $taskIds) {
            $result = $this->mover->moveTasks(
                $taskIds,
                $boardId,
                $this->scopes->scopeKeyOfBoard($boardId),
                actorId: $this->scopes->actorIdOf($event),   // optional, for TASK_TIME_MOVED
                fromBoardId: $event->sourceBoardId,           // entries on any other board stay (on_another_board)
            );
            // $result->moved, alreadyAtTarget, secondsMoved, timersMoved
            // $result->kept: entry id => TaskTimeKeptReason; keptIds(TaskTimeKeptReason::SourcePeriodLocked), secondsKept
        }
    }
}
```

- **A wrong target is a data leak, not only a wrong number.** The entries' hours, task titles, descriptions and notes then show in the
  other tenant's reports, exports and bills. The service checks the target against neither the actor nor the scope provider: you vouch for
  the board and the scope. Pass the scope your `timesheets.scope_provider` returns for tasks of that board; `null` is for hosts without
  tenant scopes.
- **`fromBoardId`** (optional, recommended): only entries and running timers on that board move; an entry already on the target board
  counts in `alreadyAtTarget`; any other entry stays and is reported as `on_another_board`. Without it, every entry of the tasks moves,
  whatever its board.
- **Only two columns change**, `board_id_snapshot` and `scope_key` (for a running timer, only `board_id_snapshot`: the entry it becomes takes the scope of its
  board when it stops). Duration, times, user, person, task, title, source, read-only state, notes and external ids stay. Nothing is created or deleted, so count
  and total per user are the same before and after.
- **Every entry of the tasks:** live, manual, calendar and imported, of users and of people with no account.
- **Batches:** 500 entries per transaction; the ids are read with no lock, then locked by primary key and checked again; one bulk `UPDATE` by id; loaded entries and
  timers are detached, so read them again after the call. Task ids go 200 to a read; pass as many as you like.
- **Retry:** if your listener fails (or the worker dies), the entries are still there with the old snapshot. Call again with the same arguments: rows already at the
  target count in `alreadyAtTarget` and are not written. Running it from a message handler with retries is fine once the target is resolved at handling time.
- **What stays, and why** (`TaskTimeMoveResult::$kept`, by entry id). With timesheets on, on a move **into another scope**:
  - `source_period_locked`: the entry's period is locked, approved or submitted in its current scope (also for a deleted user's timesheets, by `owner_ref`).
    Its timesheet's `entries_hash` and totals were taken over the entries of that scope: moving it would change what an approved or locked period bills.
  - `target_period_locked`: the owner's period at that date is locked, approved or submitted in the target scope; the hours would land in it.

  On a move **within one scope** (the entry's scope is already the target's), such an entry follows in `board_id_snapshot` only and counts in `moved`: its scope,
  hours, timesheet totals and `entries_hash` do not change, because neither covers the board (owner decision 2026-11-08). In every case:
  - `anonymised`: an anonymised entry is never written again.
  - `owner_unknown`: with timesheets on, an entry with no user and no person reference; the guard cannot check its period.
  - `on_another_board`: you passed `fromBoardId` and the entry is on a third board.

  Kept hours keep counting where they are. To move locked ones, reopen the period (or withdraw or reject a submitted one) through the audited timesheet flow and
  call again with the same arguments. With timesheets off only anonymised entries (and, with `fromBoardId`, entries on a third board) stay.
- **Two moves of one task handled at the same time** (two workers, each with its own message) can leave the time on the wrong board, because each resolves the current board before the other commits. Re-dispatch every move after a delay of a few seconds (it is idempotent, and the late call sees the final board), or handle the moves of one task one at a time (for example a Messenger lock or a single-consumer queue per task).
- **A timer stopping during the move** may have read the timer before the move updated it, and then writes its entry with the old board and scope. The time is
  safe, but it counts in the source until another call. `TASK_BOARD_CHANGED` fires once, so schedule that call yourself: dispatch the same message again with a
  delay of a few seconds (it is idempotent), or, on `TIME_ENTRY_CREATED` / `TIMER_STOP`, compare the new entry's `board_id_snapshot` with the task's current board
  and call the mover for that task.
- **Ledger:** balance ledger lines are not touched. Working-time balance derived from task time (`TaskTimeWorkedTimeSource`) moves with the entries for open
  periods; a locked period's balance is frozen in its timesheet, whose entries stay.
- **`TaskTimeMovedEvent` (`TASK_TIME_MOVED`) fires after each committed batch**, with that batch's task ids, target board and scope, result and actor, whenever the
  batch met any entry or timer, including entries that were all already at the target. So a retry reports what an earlier call committed, and a batch that
  failed does not hide the ones before it. **The bundle has no audit table: store the event**, and expect the same entries more than once: `alreadyAtTarget`, `kept` and `secondsKept` repeat in every call's event, so a host that sums them across calls counts them twice.
  A listener that throws does not stop the move: the rest is done and committed, then the call throws `TaskTimeMoveEventException`, whose `getResult()` says
  what was done and whose previous exception is the listener's. Call again to have the event dispatched again.

## Working time and timesheets (1.13)

Full guide: [WORKING-TIME.md](WORKING-TIME.md).

```php
// A period, its timesheet and the owner's actions
$week      = $timesheets->period($user, 'org-a', 'previous');            // 'current', 'previous', '2026-W45'
$timesheet = $timesheets->view($user, $userId, 'org-a', $week);
$timesheets->submit($user, 'org-a', $week);
$timesheets->approve($approver, $timesheet->getId());                    // TimesheetException: stale, conflict, not_found…

// The balance of a range
$report = $balance->report($user, 'org-a', '2026-11-01', '2026-11-30');  // BalanceReport, null when off
$report->balanceSeconds; $report->legalRecord;                           // show "Task time…" when false

// Host code that writes entries itself must respect the lock
$periodLockGuard->assertWritable($user, $scopeKey, $entryStart, WriteKind::Edit);
```

Components for host pages (no template overrides):

```twig
{{ nowo_time_track_component('TimeTrack:TimesheetWeekGrid', {timesheet: timesheet, viewer: app.user, options: {action_urls: urls}}) }}
{{ nowo_time_track_component('TimeTrack:BalanceCard', {report: report, options: {range: 'month'}}) }}
{{ nowo_time_track_component('TimeTrack:LedgerTable', {lines: lines, viewer: app.user, owner_id: ownerId, scope_key: scope}) }}
```

The posted forms go to your routes; hand the request to `Timesheet\TimesheetFormHandler::handleDecision()` /
`handleLedger()`. Since 1.39.0 the dialogs (ledger adjustment and reversal, reject, reopen) load on demand: pass
`options.dialog_urls` (one host route per dialog, GET and POST, handed to `Ui\TimesheetDialogResponder::ledger()` /
`decision()`) and the components render links plus one empty remote dialog instead of a form per row:

```twig
{{ nowo_time_track_component('TimeTrack:LedgerTable', {lines: lines, viewer: app.user, owner_id: ownerId, scope_key: scope, options: {
    dialog_urls: {add_adjustment: path('app_ledger_adjust'), reversal: path('app_ledger_reverse')},
}}) }}
```

The reversal URL gets `line=<id>` (or replace a `{line}` placeholder), the week grid's `timesheet=<id>`. The responder
serves the fragment for `X-Nowo-Dialog: 1` and the full page otherwise (works without JavaScript), answers 422 in
place, 409 on a conflict, 204 + `X-Nowo-Redirect` once saved, and refuses access exactly as the post. The `nowo_time_track_duration` filter formats seconds as `H:MM` (`|nowo_time_track_duration(true)` adds
the sign).

## Calendar suggestions (1.14)

Opt-in per user and scope; nothing is read until the user connects a calendar in your app. The host owns OAuth and the
tokens; the bundle owns the read-only adapters, the matching and the suggestions.

```php
// After your OAuth flow (server mode): record the consent with your token reference.
$connection = $calendarConnections->connect($user, 'org-a', CalendarProvider::Google, CalendarConnectionMode::Server, $tokenRef, '2026-11');
// The desktop reads the calendar itself (local mode): only the consent is recorded.
$calendarConnections->connect($user, 'org-a', CalendarProvider::Microsoft, CalendarConnectionMode::Local, null, '2026-11');

// A rule for keyless events (literal, case-insensitive; matches grouped per ISO week).
$calendarRules->create($user, 'org-a', 'stand-up', $taskId);

// Disconnect: your CalendarCredentialSourceInterface::revokeFor() runs first, then everything derived is deleted.
$calendarConnections->revoke($user, $connection->getId());
```

`nowo:time-track:calendar:sync` (schedule it, e.g. every 15 minutes) reads each due server-mode connection inside its
owner's working hours: events of the primary calendar of the last `calendar.lookback_days` that have ended, whose title
carries a key your `TaskKeyLookupInterface` resolves (or that a user rule matches) and whose task the user can track.
Recurring events of one series (or one grouping rule) in one ISO week share a `group_key`.

The owner's side (also behind the client API, [CLIENT-API.md](CLIENT-API.md#changes-in-114-calendar-suggestions-manual-entries)):

```php
$list     = $calendarSuggestions->pending($user, 'org-a', $from, $to);          // {groups, singles}
$outcomes = $calendarSuggestions->accept($user, $ids, null, null, 'note');      // one `calendar` entry per occurrence
$calendarSuggestions->dismiss($user, null, $groupKey);

// Entries without a timer (POST entries; refuses overlaps, locked periods, > 24 h, future, too old)
$entry = $manualEntries->create($user, $taskId, $start, $end, TimeEntrySource::Manual, 'Fixed the build');
```

`TimeTrackEvents::TIME_ENTRY_CREATED` fires for each of these entries (TaskBoard aggregates it).


Since 1.11: `TimeTrackEvents::TIME_ENTRY_IMPORTED` (`TimeEntryImportedEvent`) after an imported entry is created or
changed, and `TimeTrackEvents::TIME_ENTRIES_REMOVED` (`TimeEntriesRemovedEvent`: scope, count, seconds per task, owner
ids) after imported entries are deleted. Neither is a timer event. `TimeEntryImportedEvent::getEntry()` is the managed
`TimeEntry` itself (duration, task, owner for task totals), so it also reaches the title snapshot and the description:
the event adds no copy of them, and listeners must not log or forward them.

- `nowo_time_track.timer.start` — `TimerStartEvent`
- `nowo_time_track.timer.stop` — `TimerStopEvent` (TaskBoard can aggregate `time_spent`)
- `nowo_time_track.time_entry.list_query` — filter report user ids
- `nowo_time_track.time_entry.access_check` — fine-grained ACL
- `nowo_time_track.time_entry.id_access_check` — the same decision with ids only (`TimeEntryIdAccessCheckEvent`, 1.37)
- `nowo_time_track.client_api` — `ClientApiEvent` audit trail of the client API (1.9)

## Calendar OAuth apps at runtime (1.20)

By default the OAuth apps of the optional helpers come from `calendar.oauth.google` / `.microsoft` (usually
`%env()%`), fixed when the container compiles. Since 1.20 you can keep them in your database instead — encrypted,
edited by an administrator, write-only — with a service implementing `Calendar\CalendarOAuthAppProviderInterface`:

```yaml
nowo_time_track:
  calendar:
    oauth:
      app_provider: App\TimeTrack\Calendar\InstanceSettingsCalendarOAuthApps
      microsoft: { tenant: organizations }   # default tenant of an app stored without one
```

Example with InstanceSettingsBundle (secrets in an `#[Encrypted]` column through DoctrineEncryptBundle; the admin form
never shows a stored secret, a blank field keeps it):

```php
use Nowo\InstanceSettingsBundle\Attribute\AsSettingsSection;
use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Symfony\Component\Validator\Constraints\Regex;

#[AsSettingsSection(priority: 20)]
final class CalendarOAuthSettingsSection extends AbstractSettingsSection
{
    public const NAME = 'calendar_oauth';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getLabel(): string
    {
        return 'Calendar OAuth apps';
    }

    public function getFields(): iterable
    {
        yield SettingField::string('google_client_id', null, 'Google client id');
        yield SettingField::secret('google_client_secret', 'Google client secret');
        yield SettingField::string('microsoft_client_id', null, 'Microsoft client id');
        yield SettingField::secret('microsoft_client_secret', 'Microsoft client secret');
        yield SettingField::string('microsoft_tenant', null, 'Microsoft tenant', 'common, organizations, consumers or a tenant id', false, [
            new Regex('/^(?!\.{1,2}$)[A-Za-z0-9.-]{1,64}$/D'), // MicrosoftCalendarOAuthClient::TENANT_PATTERN
        ]);
    }
}
```

```php
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\TimeTrackBundle\Calendar\CalendarOAuthApp;
use Nowo\TimeTrackBundle\Calendar\CalendarOAuthAppProviderInterface;

final readonly class InstanceSettingsCalendarOAuthApps implements CalendarOAuthAppProviderInterface
{
    public function __construct(private InstanceSettingsInterface $settings)
    {
    }

    public function app(string $provider): ?CalendarOAuthApp
    {
        if ($provider !== 'google' && $provider !== 'microsoft') {
            return null;
        }
        $prefix = CalendarOAuthSettingsSection::NAME . '.' . $provider;
        $id     = $this->settings->getString($prefix . '_client_id');
        $secret = $this->settings->getSecret($prefix . '_client_secret');
        if ($id === null || $id === '' || $secret === null || $secret === '') {
            return null; // hidden on the Integrations page; its calls fail with not_configured
        }
        $tenant = $provider === 'microsoft' ? $this->settings->getString($prefix . '_tenant') : null;

        return new CalendarOAuthApp($id, $secret, $tenant === '' ? null : $tenant);
    }
}
```

- **Read on every use, never kept** (REQ-RUNTIME-002): the helpers ask the provider once per call — consent URL, code
  exchange, each refresh, `isConfigured()` — and keep nothing, so a rotated secret applies to the next call in
  FrankenPHP worker mode with kernel reset false and in long-running Messenger workers. Do not memoise the app in your
  provider either; InstanceSettings' own memo lasts one request or one message.
- **Discovery:** `Calendar\CalendarOAuthClients::configuredProviders()` / `isConfigured(CalendarProvider)` (or each
  helper's `isConfigured()`) for the Integrations page, the connect buttons and the refresh job. A provider whose app
  was removed disappears on the next call.
- **Removed app, existing connections:** `authorizationUrl()`, `exchangeCode()` and `refresh()` throw
  `CalendarSyncException('not_configured')` without a request. Treat `not_configured` as "integration switched off":
  show it on the connection, skip the connection in the refresh job (no retry loop; in a Messenger handler throw
  `UnrecoverableMessageHandlingException`) and **keep the stored tokens** — the bundle deletes nothing, and the
  connection works again once an administrator restores the app. Only `token_expired` means the user must reconnect.
- **Disconnecting or erasing with the app removed:** `revoke()` needs no app. Google revokes by the token alone, so the
  grant still ends at Google (audit ML-L2); Microsoft Graph never revokes (tell the user to remove the app at
  https://myapps.microsoft.com). Delete your stored tokens whatever it answers.
- **The secret:** `CalendarOAuthApp::revealClientSecret()` is the only way to read it. `__toString()`, debug output
  (`var_dump()`, `print_r()`, VarDumper and the profiler's dump panel), `json_encode()`, `serialize()`,
  `var_export()`, an `(array)` cast, Serializer and Monolog see `[redacted]`; unserializing throws. This prevents
  accidental output, not deliberate access: reflection on the internal wrapper, or a cast of that wrapper itself, still
  reads it (audit ML-I1).
- **The profiler's HTTP Client panel is not redacted** (audit ML-L1): in debug, `TraceableHttpClient` records every
  token request body in plain text — `client_secret`, `code`, `code_verifier`, `refresh_token`. Never enable the
  profiler where real secrets are used, or point `calendar.http_client` at a client the profiler does not trace (for
  example a plain `HttpClient::create()` service); it carries the readers' Bearer calls too.
- **A failing store:** an exception thrown by your provider is treated as "not configured"; its message is dropped and
  the `time_track` logger gets a warning with the provider name and the exception class only (audit ML-I3), so a
  broken key does not look like "switched off" in your logs.
- Without `app_provider` nothing changes: the static values apply exactly as in 1.19.

## Billable entries (1.21)

`TimeEntry::$billable` (nullable boolean, since 1.11) was set only by imports. Since 1.21 (Beacon D-20261111-12) it is
editable, and new live entries can take a default from your application. No schema change: the column exists.

### A default for new entries

Implement `Integration\TimeEntryBillableDefaultProviderInterface` and alias the interface to your service. It is asked
once for every new **live** entry stored without an explicit value: a timer stop or switch, an idle reassign, `POST
entries` without `billable`, an accepted calendar suggestion. Imported entries keep their source's value and never ask.
Scalars only (REQ-ARCH-001): the entry's tenant scope (`''` when it has none) and the task id. Return null to keep the
behaviour before 1.21 (the flag stays unset). The default, `NullTimeEntryBillableDefaultProvider`, answers null.

```php
namespace App\TimeTrack;

use Nowo\TimeTrackBundle\Integration\TimeEntryBillableDefaultProviderInterface;

final readonly class ProjectBillableDefault implements TimeEntryBillableDefaultProviderInterface
{
    public function __construct(private ProjectSettings $projects) {}

    public function defaultFor(string $scopeKey, ?string $taskRef): ?bool
    {
        return $taskRef === null ? null : $this->projects->newTimeIsBillableForTask($taskRef); // null = no default
    }
}
```

```yaml
# config/services.yaml
services:
  Nowo\TimeTrackBundle\Integration\TimeEntryBillableDefaultProviderInterface: '@App\TimeTrack\ProjectBillableDefault'
```

It runs inside the write transaction: keep it fast, read-only and free of request state (worker mode).

### Changing the flag

Call `Service\TimeEntryBillableService` (public) instead of writing the column:

```php
$entry = $this->billable->changeById($this->getUser(), $entryId, true);   // or ->change($user, $entry, false)
```

| Refusal | When |
|---|---|
| `BillableChangeRefusedException` `not_found` | no entry with that id (or the store cannot look one up) |
| `BillableChangeRefusedException` `read_only` | an imported entry |
| `BillableChangeRefusedException` `anonymised` | an anonymised or ownerless entry: it never changes |
| `BillableChangeRefusedException` `forbidden` | the grant says no: `TeamAccessGuard::isBillableEditGranted()` — by default the edit grant (the owner, a manager with `security.manager_can_edit_entries`, an admin role in `security.admin_roles`, then your `nowo_time_track.time_entry.access_check` listener for action `edit`), then the same listener for action `edit_billable` (`TeamAccessGuard::ACTION_EDIT_BILLABLE`, `deny()` / `grant()`), so you can decide billable apart from editing |
| `PeriodLockedException` / `PeriodSubmittedException` (409) | the entry's period is locked (approved) or submitted, checked with `WriteKind::Edit` after the grant; no role or listener overrides it, only an audited reopen |

With the Doctrine store the lock check and the write run in one transaction holding the owner's write lock, and the
entry is re-read there with a row lock (`LockingFindableTimeEntryRepositoryInterface::findForUpdate()`) and checked again,
so an entry anonymised, replaced or moved to another owner after you loaded it is refused. A replacement store with only
`FindableTimeEntryRepositoryInterface` is re-read with `findById()` in the same transaction, without a row lock; one
without `UserSerialisedTimeEntryRepositoryInterface` / `FindableTimeEntryRepositoryInterface` runs the steps one after
the other.
Setting the value an entry already has writes nothing.

- **`security.admin_roles` are not tenant-aware:** a user with one of those roles passes the edit grant for every
  entry, in every tenant scope. In a multi-tenant host, keep those roles for platform staff or deny the `edit` /
  `edit_billable` actions of other tenants in your access-check listener.
- **Errors for other people's entries:** `read_only` and `anonymised` are answered before the grant. A host controller
  that takes an entry id from someone who is not its owner should answer both (and `forbidden`) as 404, as the client
  API does, so the caller learns nothing about entries it cannot see.
- **An explicit value on `POST entries`** (or `ManualEntryService::create(..., billable: …)`) asks the `edit_billable`
  check for the new entry too; a refusal is `ManualEntryException` `billable_forbidden` (403) and nothing is stored.
  Without a value the host default applies and nothing is asked.

**Audit.** Each real change dispatches `TimeEntryBillableChangedEvent` (`TimeTrackEvents::TIME_ENTRY_BILLABLE_CHANGED`)
after the commit: `entryId`, `ownerId`, `actorId`, `oldBillable`, `newBillable`, `occurredAt` and `via` (`api` or
`host`). Ids and scalars only. The bundle has no audit table, so store it in yours (AuditKit, your own log):

```php
#[AsEventListener(event: TimeTrackEvents::TIME_ENTRY_BILLABLE_CHANGED)]
public function onBillableChanged(TimeEntryBillableChangedEvent $event): void
{
    $this->audit->record('time_entry.billable', $event->entryId, $event->actorId, ['from' => $event->oldBillable, 'to' => $event->newBillable], $event->occurredAt);
}
```

A listener that throws is logged and does not undo the change. A refusal by the period lock also writes the
`edit_blocked` timesheet row when `timesheets.audit_blocked_edits` is on.

### Client API

`billable` is read on `GET entries` / `GET export` (since 1.11), accepted on `POST entries` (`true`, `false`, or absent /
null for the host default) and changed with `PATCH entries/{id}` `{"billable": true|false|null}` (scope
`entries:write`, own entries only, same rules as above). See [CLIENT-API.md](CLIENT-API.md#patch-entriesid-121).

## Leave on the clients (1.22, spec 204)

The clients show *On leave until …* and send no reminders on leave days (Beacon spec 204 FR-029). Leave itself —
requests, approvals, balances, the team calendar — stays in your application and on the web. Two optional pieces:

**1. Today's leave** (`GET workday.leave`, `GET me` `features.leave` / `links.leave`). Implement
`Client\ClientUserLeaveProviderInterface` and set `client_api.leave_provider`. It is framework-free (REQ-ARCH-001 rule
3): it receives the user's **id string** (the same as `GET me.userId`) and the start of the workday's date in the
workday time zone, and returns a `Client\ClientUserLeave` or null. Return a leave only for an approved full-day leave
that covers that day (half days and hours reduce the expected time in your register instead).

```php
namespace App\Leave\Infrastructure;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Client\ClientUserLeave;
use Nowo\TimeTrackBundle\Client\ClientUserLeaveProviderInterface;

final readonly class TimeTrackLeaveAdapter implements ClientUserLeaveProviderInterface
{
    public function __construct(private LeaveQueries $leaves) {} // your application port

    public function getCurrentLeave(string $userId, DateTimeImmutable $day): ?ClientUserLeave
    {
        $leave = $this->leaves->approvedFullDayLeaveOn($userId, $day); // your read model
        if ($leave === null) {
            return null;
        }
        $until = $leave->lastDay->format('Y-m-d');

        // Medical leave (and any type the devices must not learn): no type at all, the clients show "Absent".
        return $leave->type->isMedical() || !$leave->type->isPublic()
            ? ClientUserLeave::absent($until)
            : ClientUserLeave::typed($leave->type->label($leave->locale), $until);
    }
}
```

```yaml
nowo_time_track:
  client_api:
    workday_provider: App\WorkingTime\Infrastructure\TimeTrackWorkdayAdapter   # GET workday must answer
    leave_provider: App\Leave\Infrastructure\TimeTrackLeaveAdapter
    links_leave_route: app_leave_index                                         # optional: your Leave page (https)
```

- `ClientUserLeave` has no field for a reason, a comment, an attachment or a health detail. A `typed()` label reaches
  the user's devices and is stored there: a public type name only (*Vacation*, *Paid leave*), never a reason.
- The bundle sends the label cleaned (control, format and invisible characters removed, at most 80 grapheme clusters
  and 320 bytes), the translated *Absent* for `absent()` and for an empty label, and `reminder: null` while the user is
  on leave.
- **Your register must suppress reminders itself.** The workday provider's `reminder` (and your own notifications) must
  already be off on leave days (spec 204 FR-025). The bundle's `reminder: null` is only a second layer: it **fails
  open** — when the leave provider throws, `leave` is null and the register's reminder is sent as it is.
- It runs on every `GET workday` (clients poll it): keep it fast, read-only and free of request state (worker mode; the
  bundle asks it again on every request and keeps nothing). If it throws, `leave` is null for that request and a
  warning without user data is logged; the workday API keeps answering.
- Without a `workday_provider` (or with the register off for the user) `GET workday` answers 404, `features.leave` is
  false and `links.leave` is null: the clients then rely on `daysOff`.

**2. Leave days in `daysOff`** (`GET me`, the next 60 days; only with `client_api.leave_provider` set, otherwise a
`leave` entry is dropped as in 1.21). Your days-off provider (`ClientUserDaysOffSourceInterface`, unchanged) may
return `['date' => 'YYYY-MM-DD', 'kind' => 'leave']` for every
approved leave day. The bundle always sends its label as null, so a leave day never carries a type or a reason; keep
labels for public holiday names.

## Timer for token principals and service accounts (1.25)

Since 1.25.0 (Beacon spec 234, AI Flow on Beacon tasks) a principal without an app password, such as an API token or an
MCP session, can start, stop and read its timer through `Timer\HostTimerApi`, a public service your own controllers
call. The app-password client API is unchanged.

```php
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefFactory;
use Nowo\TimeTrackBundle\Timer\HostTimerApi;

final readonly class TimerTool
{
    public function __construct(private HostTimerApi $timers, private TimeTrackUserRefFactory $refs) {}

    public function start(User $account, string $taskId): array
    {
        // Your code authenticated the token and decided that this account may use the timer.
        $start = $this->timers->start($this->refs->fromUser($account), $taskId, note: null, channel: 'mcp');

        return [
            'timer'   => ['taskId' => $start->timer->taskId, 'startedAt' => $start->timer->startedAt],
            'stopped' => $start->stopped?->entryId, // the account's timer this start stopped, if one ran
        ];
    }
}
```

| Method | Returns |
|--------|---------|
| `start(TimeTrackUserRef $actor, string $taskId, ?string $note = null, string $channel = 'api')` | `HostTimerStart`: `timer` (`TimerSnapshot`), `stopped` (`?TimerEntrySnapshot`, the replaced timer's entry), `stoppedEntries` |
| `stop(TimeTrackUserRef $actor, ?string $note = null)` | `?TimerEntrySnapshot`: entry id, task, start, end, duration, source, channel, `serviceAccount`; null when no timer ran |
| `current(TimeTrackUserRef $actor)` | `?TimerSnapshot`: timer id, task, start, client type, channel, note |

The rules:

- **Authorisation stays in your application.** Pass the account as a `TimeTrackUserRef` that carries its security user
  object (`TimeTrackUserRefFactory::fromUser()`); a reference by id alone is refused with a `LogicException`. The bundle
  then asks your `client_api.task_access_checker` (or its 1.23 twin `TaskAccessPolicyInterface`) **and** your task
  provider — the same two checks as `POST timer/start` — and then the period lock. A refusal of either is one
  `TaskNotTrackableException`, so the caller cannot tell "not allowed" from "does not exist"; the period lock throws
  `PeriodLockedException` / `PeriodSubmittedException`.
- **One running timer per account.** `start()` on an account whose timer runs stops that timer first and returns its
  entry in `stopped` (starting a new timer replaces the running one). That entry is marked `closed_by: replaced` in its metadata
  (`TimeEntry::closedBy()`, `TimerEntrySnapshot::$closedBy`; reserved, a client or a manual entry cannot set it). The
  access check, the task and the period lock all run before anything is stopped, so a start refused for one of those
  reasons leaves the running timer untouched. Only the actor's own timer is ever read, started or stopped, so an
  agent's call never stops a person's timer.
- **Concurrent starts: the last call wins.** Each attempt checks and inserts under the account's row lock, so two calls of
  `start()` for one account run one after the other and the second stops the first. A start that does not take the lock
  (the client API) may still win the unique index in between: the start is retried, up to 3 attempts
  (`TimerService::REPLACE_ATTEMPTS`), and every stopped timer is recorded exactly once. After the last attempt it throws
  `ActiveTimerConflictException` (answer 409). That is the one refusal that is not untouched-on-refusal: entries stopped
  on the earlier attempts are already written and committed, and their ids are on the exception
  (`$stoppedEntryIds`), so your 409 can name them. The replaced timer is logged at `info` (`action: timer_replace`) with
  the entry id and the account id only.
- **The channel** (`api`, `mcp`, …: 1 to 16 characters of `a-z`, `0-9` and `_`, otherwise `InvalidArgumentException`) is
  stored on the entry. It is a label for reports and audit, never an authorisation input. The entry's source stays `web`:
  a dedicated source is planned for 2.0 (a new enum case would break an exhaustive `match` in your code). A client of the
  client API cannot set the channel through its timer metadata.

### Service accounts (agents)

Implement `Integration\ServiceAccountPolicyInterface` and point `service_account_policy` at it (or alias the
interface). The default, `NoServiceAccountPolicy`, treats everyone as a person.

```php
use Nowo\TimeTrackBundle\Integration\ServiceAccountPolicyInterface;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

final readonly class AgentAccounts implements ServiceAccountPolicyInterface
{
    public function __construct(private UserRepository $users) {}

    public function isServiceAccount(TimeTrackUserRef $account): bool
    {
        return $account->id !== null && $this->users->isServiceAccount($account->id);
    }
}
```

```yaml
nowo_time_track:
    service_account_policy: App\TimeTrack\AgentAccounts
```

For a service account:

- its entries may overlap, its own and other accounts': `ManualEntryService::create()` does not refuse an `overlap`
  (neither with its entries nor with its running timer), and a backdated timer start is not clamped to the end of its
  latest entry;
- every entry it writes is marked: `TimeEntry::isServiceAccount()`, `TimeEntryView::$serviceAccount`,
  `TimerEntrySnapshot::$serviceAccount`, `serviceAccount` in the client API and personal-data export rows,
  `service_account` in the CSV export.

Nothing else changes: one running timer per account, the period lock (also for locked periods while a timer runs), the
task checks, the time that follows a moved task (spec 217 FR-008) and the timer of a deleted account (closed and saved;
the HA-M6 exception for deletes that run no bundle code still applies). People keep every overlap rule. The policy is
asked on every write and its answer is never kept: keep it fast and free of request state (worker mode).

**The policy must not throw, and must tolerate an account being deleted.** It is asked inside every timer stop, the stop
that saves the running time of a user you are deleting included, where your account row may already be gone: answer
false rather than failing for an unknown account. The bundle does not depend on that — an exception is caught, logged
with its class only and read as "a person", so the entry is still written — but then an agent's entry is recorded as a
person's. That rescue covers the timer paths only: `ManualEntryService::create()` lets the exception out, because one
synchronous write of yours may fail loudly. A flaky policy therefore makes the two surfaces disagree about one account —
its timer entries recorded as a person's, its manual entries refused — and no catch can repair an entity manager your
policy closed itself, so keep it to reads.

`ManualEntryService::create()` takes an optional `channel` too, for a finished entry logged through your API.

### Telling agents' time from people's time

Read the typed columns instead of the metadata: `TimeEntry::channel()` and `TimeEntry::isServiceAccount()` (mapped
fields `channel` and `serviceAccount`, so your DQL can filter on them). The Doctrine store also implements
`Repository\OriginFilteredTimeEntryRepositoryInterface`:

```php
use Nowo\TimeTrackBundle\Repository\TimeEntryOriginFilter;

$agentTime  = $store->findPageByUsersPeriodAndOrigin($userIds, $from, $to, TimeEntryOriginFilter::serviceAccounts(), 0, 50);
$mcpCount   = $store->countByUsersPeriodAndOrigin($userIds, $from, $to, new TimeEntryOriginFilter(channel: 'mcp'));
$appsOnly   = $store->countByUsersPeriodAndOrigin($userIds, $from, $to, new TimeEntryOriginFilter(serviceAccount: false, withoutChannel: true));
```

The conditions are SQL (the page is cut after them) on the index `idx_time_track_entries_origin`
(`service_account, channel, started_at`), with the user and period conditions of the other list queries. Rows are checked
again in PHP (owners byte for byte, channel with `===`); a channel that is not a valid label is refused when the filter is
built (REQ-SEC-006). Schema: see [UPGRADING](UPGRADING.md#to-1250).

## Activity monitoring ports (1.26)

Spec 199, contract §TT-MON. **Opt-in**: an organisation turns monitoring on only with a recorded DPIA, and each worker
sees the notice before anything is collected; workers are never scored. The bundle owns the client API (routes, scope
`monitoring:write`, every refusal check, caps, MIME sniffing, rate limits, audit events) and never writes files; your
application owns the policy, the checklist, the notice, the storage, the viewers, retention and the workers' rights.
Configuration: [CONFIGURATION](CONFIGURATION.md#activity-monitoring-126); client contract:
[CLIENT-API](CLIENT-API.md#changes-in-126-activity-monitoring-spec-199).

Four ports, all framework-free (`TimeTrackUserRef` and bundle value objects only; no Doctrine, no HttpFoundation, no
`UserInterface`), none with a deprecated twin:

| Port | You implement | Default | VO |
|---|---|---|---|
| `Monitoring\MonitoringPolicySourceInterface::policyOf(TimeTrackUserRef): ?MonitoringPolicy` | **yes** (`client_api.monitoring.policy_source`; null = off for the worker) | — | `MonitoringPolicy` |
| `Monitoring\MonitoringEvidenceStoreInterface` (`storeScreenshot`, `markDiscarded`, `discard`, `storeActivity`, `hasScreenshot`) | **yes** (`client_api.monitoring.evidence_store`) | — | `ScreenshotUpload`, `ActivityItem` |
| `Monitoring\MonitoringAcknowledgementStoreInterface` (`acknowledge`, `lastAcknowledged`) | DDD hosts | `Repository\DoctrineOrmMonitoringAcknowledgementStore` (bundle table, bundle entity manager) | `MonitoringAcknowledgement` |
| `Monitoring\MonitoringUploadSignerInterface` (`sign`, `verify`) | optional | `Monitoring\HmacMonitoringUploadSigner` | `MonitoringUploadClaim` |

**Since 1.41.0** (owner decisions D4, D9, D11 of the 2026-11-24 monitoring study):

- **No window titles (D4).** `MonitoringPolicy` has no title switch (2.0 removed the `windowTitles` argument and
  property, frozen to false in 1.41); no route accepts a title or a project name, and `GET me` has no `windowTitles` key.
- **Team views (D9).** Give the policy its written `purpose` (`billing_evidence`, `project_costing`, `self_analytics`).
  Third parties see **project aggregates** (`teamView: project_aggregates`, the default). Per-person detail needs the
  organisation's explicit opt-in: `purpose: billing_evidence`, `teamView: per_person` and `perPersonDetailRecordedAt`
  (the `Y-m-d` date it was recorded; keep who and why in your policy audit) and, on later versions,
  `perPersonSinceVersion` (the first `per_person` version, which the worker must have acknowledged, or a later one); anything else is refused by the
  constructor. Switching to `per_person` needs the DPIA update and the information to the workers' representatives
  (ET art. 64.5, LOPDGDD art. 87.3). In your team views, after your own `view_team` / project checks, call the public
  `Monitoring\MonitoringTeamViewGuard::authorizePersonDetail($viewer, $worker)`: it throws
  `MonitoringTeamDetailDeniedException` (show aggregates) or dispatches `TimeTrackEvents::MONITORING_PERSON_DETAIL_VIEWED`
  (`Event\MonitoringPersonDetailViewedEvent`: viewer and worker refs, policy version, purpose, time, `visibleSince`)
  and returns a `Monitoring\MonitoringPersonDetailGrant`. Store the event **synchronously** as the view record and show
  it on the worker's timeline: with no listener the guard refuses (`no_audit`), and a listener that throws stops the
  view. Show only evidence collected at or after `$grant->visibleSince` (the later of the opt-in day and the worker's
  acknowledgement; `covers($at)`), so evidence collected under an earlier purpose never becomes per-person. The worker is
  the viewer only when the user class and the id both match. `granularity($policy)` is the **maximum** granularity, for
  list pages.
- **Breaks (D11).** With `client_api.workday_provider`, the gate refuses evidence at instants outside every clocked
  `work` segment of the register (a break, before clock-in, after clock-out) with `on_break`. Implement
  `Workday\WorkdayHistorySourceInterface::dayFor(TimeTrackUserRef $user, string $date): ?WorkdayState` on the same
  service: without it buffered evidence of another day is refused (`workday_unknown`, fail closed). `todayFor()` null
  = register off; `dayFor()` returns an `idle` state for a day without clock-ins, and null for the evidence's date is
  `workday_unknown`. Segments: `type`
  `work` / `break`, RFC 3339 `start` / `end`, `end: null` while open; the day before is consulted for night shifts.
  Activity slots are checked over the whole span the timer covers.
- **Host logs (D4).** The bundle never logs request bodies; if your application logs HTTP bodies (e.g. HttpLog),
  exclude the `monitoring/*` client routes.

### Standard Symfony host

Implement the policy source and the evidence store as services and point the configuration at them; acknowledgements
go to the bundle's table (`<prefix>_monitoring_acknowledgements`, through the bundle's entity manager) and the upload
URLs are signed with a key derived from `kernel.secret`.

```php
use Nowo\TimeTrackBundle\Monitoring\MonitoringPolicy;
use Nowo\TimeTrackBundle\Monitoring\MonitoringPolicySourceInterface;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

final readonly class MonitoringPolicySource implements MonitoringPolicySourceInterface
{
    public function __construct(private MonitoringPolicyRepository $policies, private NoticeRenderer $notices) {}

    public function policyOf(TimeTrackUserRef $user): ?MonitoringPolicy
    {
        $policy = $user->id === null ? null : $this->policies->forWorker($user->id); // organisation + checklist
        if ($policy === null) {
            return null; // nothing is collected, GET me has no monitoring block
        }

        return new MonitoringPolicy(
            enabled: $policy->isEnabled(),                    // published as false while the checklist is open
            policyVersion: $policy->version(),               // bumped by every change
            noticeUrl: $this->notices->url($policy->version()),
            noticeSha256: hash('sha256', $this->notices->text($policy->version())), // what the worker must have seen
            kinds: $policy->kinds(),                         // screenshots, apps, activity
            acknowledgementRequired: $policy->widensSince($this->lastAcknowledgedVersion($user)),
            lockedUntilChecklist: !$policy->checklistComplete(),
            dpiaReference: $policy->dpiaReference(),
            dpiaRecordedAt: $policy->dpiaRecordedAt()?->format('Y-m-d'),
            blur: $policy->blur(),
            fullResolution: $policy->fullResolution(),
            excludedApps: $policy->excludedApps(),
            retentionDays: $policy->retentionDays(),
        );
    }
}
```

`acknowledgementRequired` is yours: whether this version widens collection (a new kind, more samples, full resolution,
a shorter review window, since 1.41 `teamView: per_person`) compared with the last version the worker acknowledged.
Read that version from `MonitoringAcknowledgementStoreInterface::lastAcknowledged()` (inject the interface; it is the bundle's store unless you
replaced it). The bundle publishes `acknowledgement.required` as `true` whenever the worker never acknowledged a version.

```php
use Nowo\TimeTrackBundle\Monitoring\ActivityItem;
use Nowo\TimeTrackBundle\Monitoring\MonitoringEvidenceStoreInterface;
use Nowo\TimeTrackBundle\Monitoring\ScreenshotUpload;

final readonly class MonitoringEvidenceStore implements MonitoringEvidenceStoreInterface
{
    public function storeScreenshot(ScreenshotUpload $upload): string
    {
        // Read the stream NOW (it is closed and deleted right after): re-encode with a pixel limit (drops EXIF / ICC),
        // encrypt (e.g. StorageKit), store, and return an id of 1-64 characters [A-Za-z0-9_-].
        $object = $this->storage->putEncrypted($this->reencode->webp($upload->stream(), maxPixels: 3840 * 3840));

        return $this->screenshots->add($upload->user->id, $upload->entryId, $upload->slot, $upload->takenAt, $object, $upload->blurred, $upload->policyVersion)->id;
    }

    public function markDiscarded(TimeTrackUserRef $user, string $entryId, DateTimeImmutable $slot): void { /* slot = unverified */ }

    public function discard(TimeTrackUserRef $user, string $screenshotId): bool { /* own only; soft delete, purge in 24 h */ }

    public function storeActivity(TimeTrackUserRef $user, array $items): void { /* list<ActivityItem> */ }

    public function hasScreenshot(TimeTrackUserRef $user, string $entryId, DateTimeImmutable $slot): bool { /* screenshot or discard marker */ }
}
```

```yaml
nowo_time_track:
  client_api:
    monitoring:
      policy_source: App\TimeTrack\MonitoringPolicySource
      evidence_store: App\TimeTrack\MonitoringEvidenceStore
```

Rules for both paths:

- Every call is for the authenticated worker only: read or change only `$user`'s data. `discard()` returns false for an
  id that is not theirs (the client gets a 404, no existence oracle).
- `entryId` is the worker's running timer id or the id of the timer entry it became (`timer/stop`): link screenshots
  and activity to entries by the user and the time (`takenAt` / `slot` lie inside the entry), not by id equality alone.
- The bundle passes `ScreenshotUpload` only after checking the size, the SHA-256, the magic bytes and the header
  dimensions (≤ 3840 px). It never decodes pixels: **re-encode with a pixel limit** before storing or showing anything.
- `blurred: true` images were blurred on the device at the policy's level; for full-resolution policies derive the
  blurred viewer variant yourself, asynchronously (Messenger), never in the request.
- Keep nothing per request in your adapters' properties (FrankenPHP worker mode, kernel reset false).
- The web page that acknowledges the notice calls the same `MonitoringAcknowledgementStoreInterface::acknowledge()`,
  with `clientType: 'web'` and the hash of the text it showed.
- **Unique on (user, slot).** Keep one screenshot (or discard marker) per worker and slot in your store, whatever the
  entry id, and make `hasScreenshot()` answer for the slot whatever `$entryId` says: the same worked minute has a timer
  id and later an entry id. Slots are on the UTC grid of the policy (Unix time divisible by `slotMinutes × 60`).
- **Refusing for good.** When a store method will never accept the evidence (an image you cannot decode, a slot you
  refuse), throw `Monitoring\MonitoringEvidenceRejectedException`: the client gets `422 evidence_rejected`, deletes its
  copy and never retries, and the upload intent is burnt. Any other exception means "try again later" (`503`).
- **Discarded time.** Listen to `TimeTrackEvents::MONITORING_SPAN_DISCARDED` (`Event\MonitoringSpanDiscardedEvent`:
  the worker's `TimeTrackUserRef`, `from`, `to`, `reason`) and delete the worker's screenshots, discard markers and
  activity inside `[from, to)`. The bundle dispatches it, only while monitoring is configured, after the entry is
  recorded, for every span a worker gives up: `idle_discard` (`[from, now)`: the timer stopped at the idle start),
  `idle_split` (`[from, to)`, the timer continued), `idle_reassign_stop` (idle span reassigned with `then: stop`:
  `[to, now)`) and `backdated_stop` (`timer/stop` with an `endedAt` before now, or your own code calling
  `TimerService::stop($user, $past)`, e.g. a forgotten-timer stopper: `[end, now)`). Spans may overlap (an idle
  `discard` is also a stop at `from`, so it reports `backdated_stop` too): delete idempotently. **Make your listener async**: dispatch a Messenger message with retries and return. The bundle
  catches and logs (class only) a listener that throws, so the worker never gets a 500 for a discard that is already
  committed, but then the deletion is yours to retry: never drop it. Also delete evidence on
  `TimeEntriesRemovedEvent` (time removed by a manager) and on the worker's erasure.
- **Reconcile job (recommended safety net).** Run a periodic job that deletes every screenshot, discard marker and
  activity item whose `takenAt` / slot lies outside all recorded entries and the running timer of that worker. It
  covers a lost or failed span event, any future discard path, manager removals and erasure; the event is then an
  optimisation, not the only control.
- **Activity time.** The bundle cuts the `app` seconds of a worker and slot to `slotMinutes × 60` across batches, in
  `client_api.rate_limit.cache_pool`: with several nodes that pool **must be shared** (e.g. Redis), or each node keeps
  its own count. It is not atomic across two concurrent batches, and it is updated after `storeActivity()`: if the pool
  fails at that moment the client gets a 503 and its retry stores the items again. Keep your own per-slot sum (and an
  upsert per worker, entry, slot, kind and name) as the backstop.
- **Switching monitoring off.** Once `policy_source` is unset every monitoring route answers 404, `DELETE` included: give
  workers a way to discard their screenshots on your web page.
- **Consent.** App passwords (`POST login`, whatever your authenticator returns) never grant `monitoring:write`; only the
  device flow does, after the worker approved it on the consent page (the bundle's page shows a translated label; show
  one on your own page too).
- Set `client_api.monitoring.upload_base_url` to your public `https://` origin so the signed upload URLs never follow
  a request's `Host` header or scheme. An env placeholder works (`'%env(DEFAULT_URI)%'`); one trailing `/` is accepted.
  The value is checked when a URL is signed: anything other than an exact https origin answers `503
  monitoring_unavailable` (fail closed) and logs a warning without the value.
- `hasScreenshot()` always receives `''` as `$entryId` (kept for BC): answer for the slot.

### DDD / hexagonal host

Keep the acknowledgements in your own model: alias the interface (or set
`client_api.monitoring.acknowledgement_store`) to an adapter over your repository; the bundle's table then stays empty.
Optionally replace the signer, e.g. to rotate keys held in your settings store (sign with the current key, verify with
the current and the previous one, compare with `hash_equals`).

```yaml
services:
  Nowo\TimeTrackBundle\Monitoring\MonitoringAcknowledgementStoreInterface: '@App\Infrastructure\TimeTrack\AcknowledgementStore'
  Nowo\TimeTrackBundle\Monitoring\MonitoringUploadSignerInterface: '@App\Infrastructure\TimeTrack\RotatingUploadSigner'
```

```php
final readonly class AcknowledgementStore implements MonitoringAcknowledgementStoreInterface
{
    public function __construct(private AcknowledgementRepository $acknowledgements) {} // your domain port

    public function acknowledge(TimeTrackUserRef $user, MonitoringAcknowledgement $acknowledgement): void
    {
        // Idempotent per (worker, version): keep the first record.
        $this->acknowledgements->recordOnce($user->id, $acknowledgement->policyVersion, $acknowledgement->acknowledgedAt, $acknowledgement->clientType, $acknowledgement->noticeSha256);
    }

    public function lastAcknowledged(TimeTrackUserRef $user): ?MonitoringAcknowledgement { /* highest version, or null */ }
}

final readonly class RotatingUploadSigner implements MonitoringUploadSignerInterface
{
    public function sign(MonitoringUploadClaim $claim): string
    {
        return hash_hmac('sha256', $claim->canonical(), $this->keys->current()); // read the key per call, no cache
    }

    public function verify(MonitoringUploadClaim $claim, string $signature): bool
    {
        foreach ([$this->keys->current(), $this->keys->previous()] as $key) {
            if ($key !== null && hash_equals(hash_hmac('sha256', $claim->canonical(), $key), $signature)) {
                return true;
            }
        }

        return false;
    }
}
```

The bundle keeps its own upload intents (`<prefix>_monitoring_upload_intents`: declared size, hash, slot and state of the
one-shot URL, never the image) through its entity manager in both paths; they are purged 24 h after creation.

## Standard Symfony host

The zero-config path (REQ-ARCH-001): `user_class` is your attribute-mapped `App\Entity\User`, the same object your
firewall returns. Inject `TimerService`, `TimesheetService`, `ManualEntryService` and friends into your controllers,
listen to `TimeTrackEvents`, override the Twig templates. The bundle maps its own entities (attribute mapping prepended
for its namespace) with foreign keys to your users table; you configure no `resolve_target_entities`, no extra mapping
and no `accepted_user_classes`. Everything below is opt-in.

Since 1.22 the client leave (spec 204) is opt-in too: implement `ClientUserLeaveProviderInterface` as a service of your
application that reads your leave entity and set `client_api.leave_provider` (see
[Leave on the clients](#leave-on-the-clients-122-spec-204)). The port takes the user's id string, so a standard host
looks the user up by `(string) $user->getId()`.

## Using it from a DDD / hexagonal application

TimeTrackBundle is a **bounded context with its own model and persistence**: it owns Doctrine entities
(`TimeEntry`, `ActiveTimer`, `Timesheet`, `TimesheetEvent`, `BalanceLedgerLine`, `ClientToken`, `DeviceAuthorization`,
`CalendarConnection`, `CalendarSuggestion`, `CalendarRule`, and since 1.26 `MonitoringAcknowledgementRecord` and
`MonitoringUploadIntent`) and ships its own web UI and client API. Your domain does
not extend anything from it (no traits, no mapped superclasses).

Your application layer reads it through ports that take ids and return views (since 1.35: entries, running timers,
client sessions, personal-data export and erasure; see [Read ports and personal data](#read-ports-and-personal-data-135)),
so no DQL of yours depends on the bundle's schema.

### What stays in your domain

Tasks, teams, tenants, approvers, working calendars and meetings stay in your application. The bundle reaches them
only through ports. Since 1.23.0 every port has a **framework-free twin** (REQ-ARCH-001 rule 3): it takes and returns
only bundle value objects — the user is a `ValueObject\TimeTrackUserRef` (class, id string, identifier, role names;
the object itself through `user()`), entities become snapshots (`Dto\TimeEntryView`, `ValueObject\CalendarConnectionRef`).
Implement the twin and set the same option; since 2.0 the twins are the only host port interfaces (the 1.x ones are
removed, see [UPGRADING](UPGRADING.md#removed)).

#### Building a `TimeTrackUserRef` from a Symfony user (since 1.24.0)

Use the public `Port\TimeTrackUserRefFactory` (autowire it by class, stateless) instead of calling `new TimeTrackUserRef()`
by hand; it applies the bundle's own id derivation (class without Doctrine proxy, id string or null when blank,
identifier, roles), so your ref equals the one the bundle passes to your ports.

```php
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefFactory;

final readonly class MyTaskDirectory
{
    public function __construct(private TimeTrackUserRefFactory $userRefs) {}

    public function forCurrent(UserInterface $user): TimeTrackUserRef
    {
        return $this->userRefs->fromUser($user); // or TimeTrackUserRefFactory::build($user) without the container
    }
}
```

| Port (1.23 twin, implement this) | 1.x interface (removed in 2.0) | Config key |
|---|---|---|
| `Integration\TaskDirectoryInterface` (+ `BatchTaskDirectoryInterface`) | `TaskProviderInterface` (+ `BatchTaskProviderInterface`) | `task_provider` |
| `Integration\TeamDirectoryInterface` | `TeamContextProviderInterface` | `team_context_provider` |
| `Integration\UserReferenceProviderInterface` | `UserReferenceResolverInterface` | alias the interface |
| `Security\TaskAccessPolicyInterface` | `TaskAccessCheckerInterface` | `client_api.task_access_checker` |
| `Timesheet\TimesheetScopePolicyInterface`, `TimesheetApproverPolicyInterface` | `TimesheetScopeProviderInterface`, `TimesheetApproverProviderInterface` | `timesheets.scope_provider`, `timesheets.approver_provider` |
| `WorkingTime\WorkingCalendarSourceInterface`, `WorkedTimeRecordInterface` | `WorkingCalendarProviderInterface`, `WorkedTimeSourceInterface` | `working_time.calendar_provider`, `working_time.worked_time_source` |
| `Workday\WorkdaySourceInterface` | `WorkdayProviderInterface` | `client_api.workday_provider` |
| `Meeting\MeetingSourceInterface` | `MeetingProviderInterface` | `meetings.provider` |
| `Calendar\TaskKeyLookupInterface`, `CalendarCredentialSourceInterface` | `TaskKeyResolverInterface`, `CalendarCredentialProviderInterface` | `calendar.task_key_resolver`, `calendar.credential_provider` |
| `Client\ClientUserProfileSourceInterface` (+ `ClientUserLocaleSourceInterface`, `ClientUserDaysOffSourceInterface`, `ClientUserPrivacySourceInterface`) | `ClientUserProfileProviderInterface` (+ locale, days-off, privacy extras) | `client_api.profile_provider` |
| `Client\IdleCheckSettingsPolicyInterface`, `ClientCredentialMinterInterface` | `IdleCheckSettingsProviderInterface`, `ClientCredentialIssuerInterface` | `client_api.idle_check.provider`, `client_api.credential_issuer` |
| `Repository\ImportedTimeEntryStoreInterface`, `EntryOwnerRefsRepositoryInterface` | `ImportedTimeEntryRepositoryInterface`, `EntryOwnersRepositoryInterface` | your time entry store |
| `Client\ClientUserLeaveProviderInterface` (1.22, spec 204) | — | `client_api.leave_provider` (framework-free since 1.22; tested in `DddHostLeaveTest`) |
| `Security\TimeTrackAccessCheckerInterface`, `WorkingTime\OvertimePolicyInterface`, `Integration\TimeEntryBillableDefaultProviderInterface` | — | already framework-free |
| `Monitoring\MonitoringPolicySourceInterface`, `MonitoringEvidenceStoreInterface`, `MonitoringAcknowledgementStoreInterface`, `MonitoringUploadSignerInterface` (1.26) | — | `client_api.monitoring.*` (framework-free from the start; see [Activity monitoring ports](#activity-monitoring-ports-126)) |

A service is asked through an internal gate of its twin. A configured service that does not implement the twin fails the
build (`TimeTrackPortsPass`), and so does any service still on a removed 1.x interface (`RemovedPortsPass`). Implement them as infrastructure adapters that call your application services:

```php
final readonly class TaskDirectoryAdapter implements TaskDirectoryInterface
{
    public function __construct(private TaskQueries $tasks) {} // your application port

    public function findTaskFor(string $taskId, TimeTrackUserRef $user): ?TaskReference
    {
        $task = $user->id === null ? null : $this->tasks->trackableTask($taskId, $user->id);

        return $task === null ? null : new TaskReference($task->id, $task->title, $task->boardId);
    }

    public function listTrackableFor(TimeTrackUserRef $user, TaskListQuery $query): array { /* … */ }

    public function canTrackTask(TimeTrackUserRef $user, string $taskId): bool { /* … */ }
}
```

The tested examples are `tests/Support/Ports/Twin*.php`: `TwinWorkingTimeClientApiTest`, `TwinCalendarClientApiTest`,
`TwinMeetingsApiTest` and `TwinLeaveClientApiTest` run every scenario of their 1.22 suites through the twins.

To drive the bundle, call `TimerService`, `ManualEntryService`, `TimesheetService`, `ImportedTimeEntryWriter`
(with `ImportedTimeEntryData` / `ImportedTimeEntryPerson`) and `TaskTimeMover::moveTasks()` (task ids only) from your
application layer.

### Listening to it

Subscribe to the `TimeTrackEvents` constants. The timesheet, ledger, period-lock, move/remove and calendar events
carry ids, refs and enums only, so they map 1:1 to your own domain events. Since 1.18 the events that carry the
security user or the `TimeEntry` entity also expose scalar accessors, so a listener never has to hold either:

| Event | Scalar accessors (since 1.18) |
|---|---|
| `TimerStartEvent` | `getUserId()`, `getTaskId()`, `getBoardId()` |
| `TimerStopEvent`, `TimeEntryAccessCheckEvent` | `getUserId()` (the acting user), `getEntryId()`, `getEntryView()` |
| `TimeEntryCreatedEvent`, `TimeEntryImportedEvent` | `getUserId()` (the entry owner, null when ownerless), `getEntryId()`, `getEntryView()` |
| `IdleResolvedEvent`, `TaskEffortAccessCheckEvent` | `getUserId()` |
| `TimeEntryBillableChangedEvent` (1.21) | public readonly `entryId`, `ownerId`, `actorId`, `oldBillable`, `newBillable`, `occurredAt`, `via` (no entity) |
| `TimeEntryListQueryEvent`, `TimesheetAccessCheckEvent` | `getViewerId()` |

Since 1.23.0 the events that carry the Symfony user also give its framework-free reference: `userRef()` on
`TimerStartEvent`, `TimerStopEvent`, `IdleResolvedEvent`, `TimeEntryAccessCheckEvent` and `TaskEffortAccessCheckEvent`,
`viewerRef()` on `TimesheetAccessCheckEvent` and `TimeEntryListQueryEvent`. `getUser()` / `getViewer()` (deprecated
since 1.23.0) are removed in 2.0.

`getUserId()` is the **acting** user on the timer, idle and access-check events, and the entry **owner** on
`TimeEntryCreatedEvent` / `TimeEntryImportedEvent` (`getEntryView()->userId` is always the owner): a listener that
decides "the owner may" reads the owner from the entry view, not the actor.

`getEntryView()` returns `Dto\TimeEntryView`, a read-only snapshot (id, owner id, task, board, scope, start, end,
duration, source, external source / id). The entity getters stay for compatibility. Access is vetoed with the
deny-only events (`*_ACCESS_CHECK`, `deny()`).

```php
#[AsEventListener(event: TimeTrackEvents::TIMER_STOP)]
public function onTimerStop(TimerStopEvent $event): void
{
    $entry = $event->getEntryView();
    $this->bus->dispatch(new TimeLogged($entry->id, $event->getUserId(), $entry->taskId, $entry->durationSeconds));
}
```

### The user

- Since 1.23.0, with `user_mapping: id` and `database.mapping_type: xml` (and the private entity manager, the only mode since 2.0),
  `user_class` is just your security user class with a `getId()`: the bundle stores its class and id in its own
  `<table_prefix>host_users` rows, with no relation to your users table (`DddIdHostTest`). The points below describe the
  default `relation` mapping.
- `user_class` (required) must be a Doctrine entity in the bundle's entity manager. The bundle rewrites its `user`
  associations to that class at `loadClassMetadata`; you do **not** configure `resolve_target_entities`.
- Bundle tables have foreign keys to your users table (`SET NULL` for entries and timesheets, `CASCADE` for timers,
  tokens and calendar rows).
- `user_class` may be a plain PHP class mapped in **XML** by your application (no Doctrine attribute, no bundle base
  class or trait); `tests/Fixtures/DddHost/HostUser.php` with `doctrine/HostUser.orm.xml` is the tested example.
- With the kernel kept alive and not reset (FrankenPHP worker mode), clear that entity manager at the end of each
  request: the resolver's `find()` answers from the identity map first (audit LZ-I4).
- Since 1.18 the `UserInterface` you pass to the services does **not** have to be that entity: it only needs a
  `getId()` returning the entity id. Before storing it, every service asks the
  `Nowo\TimeTrackBundle\Integration\UserReferenceProviderInterface` port (2.0; the 1.x `UserReferenceResolverInterface` id was removed); the default
  `Doctrine\DoctrineUserReferenceResolver` keeps a `user_class` instance as it is and turns a security user of a class
  listed in **`accepted_user_classes`** (since 1.19; concrete wrapper or DTO classes, never an interface) into the
  `user_class` entity with that id. Any other class, and an id with no `user_class` row, is refused with
  `AccessDeniedException` before anything is written (audit JV-T3):

```yaml
nowo_time_track:
  user_class: App\Infrastructure\Doctrine\User     # mapped in XML by your application
  accepted_user_classes: [App\Infrastructure\Security\SecurityUser]
```

- To resolve it differently, alias the interface to your own service:

```yaml
# config/services.yaml
services:
    Nowo\TimeTrackBundle\Integration\UserReferenceProviderInterface: '@App\Infrastructure\TimeTrack\UserReferences'
```

- Requirements for that wrapper (the resolver cannot check them):
  - `getId()` returns the `user_class` id **of the same person**, with the same value and the same string form as the
    entity's `getId()` (ownership is compared as strings: a base58 form against an RFC 4122 one would split one person
    into two owners).
  - List only classes of that id space in `accepted_user_classes`. A user from another provider (API key, OAuth client,
    an admin entity with its own ids) is refused unless you list its class; if you did, a matching id would get
    entries, tokens and device approvals bound to `user_class`#id.
  - `0`, negative ids, `null` and `''` are refused (`LogicException`); an id with no `user_class` row is refused
    (`AccessDeniedException`), also where the database does not enforce the foreign keys.
- Keep the `user_class` **entity** implementing `UserInterface` even when your firewall uses a separate wrapper: the
  former-person listener only reacts to the removal of a `UserInterface` entity, so without it deleting a user stops no
  timer and snapshots or anonymises nothing (entries end up ownerless with no former-person reference). It is not
  enforced, so that routing-only or read-only setups keep booting.
- `TimeEntry::getUserId()`, `ActiveTimer::getUserId()` and `Timesheet::getUserId()` (since 1.18) give the owner id
  without loading the user; `getUser()` still returns your user entity (or its proxy).
- Removing a user through the entity manager triggers `FormerPersonSnapshotListener` (stops the timer, snapshots or
  anonymises entries). If users are deleted by bulk DQL, SQL or another flow, call
  `FormerPersonTimeEntries::release($user)` before the delete. Since 1.19 `retention.former_person_listener: false`
  keeps the listener off your entity manager entirely; your "user deleted" handler then calls `release()` or
  `anonymiseUser()` itself.

### Units of work and events

- The bundle writes and reads its rows through a private entity manager on the connection and mapping of
  `database.entity_manager` (opt-in since 1.23.0, the only mode since 2.0): it never flushes, clears or closes yours,
  your transaction includes its rows (savepoints), and it is dropped at every request, message and command boundary.
  Flush your own pending changes yourself, and change TimeTrack rows through the bundle's services. See
  [CONFIGURATION](CONFIGURATION.md) and [UPGRADING](UPGRADING.md#private-manager-only).
- `TimeTrackEvents` fire after the bundle's own flush or transaction. When you wrap a call in an outer transaction of
  your own, "after the bundle's flush" is not "after your commit": dispatch your domain events after your commit.

### Known limitations

Since 2.0 REQ-ARCH-001 is met by default (opt-in paths since 1.23.0):

- Rules 1, 3, 4: framework-free host ports (the 1.23 twins, the only ones since 2.0) and user references on the events.
- Rule 6: the private entity manager is the only mode; the bundle never flushes your entity manager.
- Rule 2: `database.mapping_type: xml` and `user_mapping: id` (your users need not be Doctrine entities of the bundle's
  entity manager). The bundle never asks your entities to extend a class or use a trait.
- Services (`TimerService`, `TimesheetService`, …) are final classes without interfaces.

## Worker mode (FrankenPHP, kernel reset false)

The bundle runs under FrankenPHP worker mode with the kernel kept alive and **not reset** between requests
(`FRANKENPHP_RESET_KERNEL=false`): no service keeps request, user or tenant data, the only per-flush state
(`FormerPersonSnapshotListener`) is cleared by the bundle at `kernel.terminate`, `console.terminate` and the Messenger
worker events, users are reloaded before role checks and a closed entity manager is reset after a failed flush.
`WorkerModeNoResetTest` proves it with one kernel, two people and more than 3,000 requests. `HostTimerWorkerModeTest` does the same for `HostTimerApi` behind a host controller: a person and two agents in turn, the person's timer never stopped by an agent, and the service-account answer applied on the next request. Clearing your entity manager's identity
map between requests stays your choice (memory). Details: [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).
