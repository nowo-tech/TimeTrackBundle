# Design: time subject and activity type (Beacon spec 256)

| | |
|---|---|
| Status | **Design, decisions recorded (Owner 2026-11-23).** Q-TS-1 revised after Beacon's objection; Q-TS-2..22 as recommended. No code yet. |
| Target | TimeTrack **2.1.0** (minor after the 2.0.0 ARCH-001 major, PR #145) |
| Requested by | Beacon, spec 256 (owner-approved D-20261123-16) |
| Designed against | branch `feat/2.0-arch-001` (2.0: private entity manager only, deprecated ports removed) |
| REQs in scope | REQ-ARCH-001 (rule 3 strict), REQ-RUNTIME-003 (concurrency), REQ-SEC-006 (exact keys), FrankenPHP worker mode with `kernel.reset: false` |

## Table of contents

1. [Summary](#1-summary)
2. [Where 2.0 stands today](#2-where-20-stands-today)
3. [Concepts and invariants](#3-concepts-and-invariants)
4. [Value objects](#4-value-objects)
5. [Ports](#5-ports)
6. [Configuration and "off by default"](#6-configuration-and-off-by-default)
7. [Writes](#7-writes)
8. [Reads, query port and grouping](#8-reads-query-port-and-grouping)
9. [Client API (desktop and extension)](#9-client-api-desktop-and-extension)
10. [Export, GDPR and retention](#10-export-gdpr-and-retention)
11. [Schema and DDL](#11-schema-and-ddl)
12. [Backfill of existing entries](#12-backfill-of-existing-entries)
13. [Concurrency (REQ-RUNTIME-003)](#13-concurrency-req-runtime-003)
14. [Security](#14-security)
15. [Events](#15-events)
16. [Reuse of existing patterns and bundles](#16-reuse-of-existing-patterns-and-bundles)
17. [Objections to the request as written](#17-objections-to-the-request-as-written)
18. [Owner decisions (Q-TS, Owner 2026-11-23)](#18-owner-decisions-q-ts-owner-2026-11-23)
19. [Delivery plan and tests](#19-delivery-plan-and-tests)

---

## 1. Summary

Time today is logged on a **task**, or (since 1.36.0, `entries.allow_without_task`) on **nothing** with a free-text
description in a tenant scope. Beacon needs a **subject**: time on something that is not a task, such as a customer
(a phone call) or the organisation itself under an internal category, **or** a subject next to a task ("this task,
for customer X", Beacon spec 250 customer time), plus an optional **activity type** (e.g. `support_call`,
`internal_meeting`) on any entry.

The design adds:

- three nullable columns on entries and running timers: `subject_type`, `subject_id`, `activity_type_key`;
- a tagged, per-type port `TimeSubjectProviderInterface` (authorisation, batched labels, bounded search) and a single
  `ActivityTypeProviderInterface` (keys, labels, default billable flag per tenant), both taking only bundle value
  objects;
- the subject and activity type on every write path (timer start / start-replacing / switch / stop, idle reassign,
  manual entries, `TimeEntryOperationsInterface::correct()` / `createManual()`, the client API), with `canLog` checked on
  every write that sets or changes a subject (task access stays the gate on task entries);
- task + subject on one entry, only for subject types whose provider declares `allowsTask()`;
- an optional refusal of a stop that has neither task nor subject (`allocation_required`), for interactive stops only;
- filters and bounded grouping on the query side, `canRead` masking on every bundle read surface;
- export, GDPR export, erasure and retention that keep both fields.

With **no provider tagged** nothing changes: the columns stay NULL, the API does not advertise the feature, and a
request that names a subject is refused like an unknown type.

## 2. Where 2.0 stands today

Read from `feat/2.0-arch-001` (read-only):

| Area | 2.0 code | Relevance |
|---|---|---|
| Entity | `Entity/TimeEntry` (`task_id` nullable since 1.36, `description`, `scope_key`, `billable`, `channel`, `service_account`) | new columns go here and on `Entity/ActiveTimer` |
| Task-less time | `entries.allow_without_task` → `TimerService::$allowWithoutTask`, `ScopeKeys::forEntryWithoutTask()`, `TimerStartedWithoutTaskEvent` | subject entries reuse the scope choice and the "task-less" code path |
| Host timer API | `Timer/HostTimerApi::start()`, `startWithoutTask()`, `stop()` (take `TimeTrackUserRef`) | gains subject / activity arguments |
| Internal timer | `Service/TimerService::startAt()`, `startReplacing()`, `switchTo()`, `stopAt()`, `resolveIdle()`, `closeTimer()` | `closeTimer()` builds the entry from the timer re-read under the delete's lock |
| Id operations | `Entry/TimeEntryOperationsInterface::correct()` / `createManual()`, `TimeEntryCorrection`, `ManualTimeEntryDraft`, `TimeEntryCorrectionResult` (before / after records) | the host writes its revision from before / after |
| Version token | `Query/TimeEntryRecord::version()` (sha256 over the row, `v1`) | must cover the new fields without invalidating old tokens |
| Query port | `Query/TimeEntryQueryInterface` + `TimeEntryCriteria` (keyed, byte-exact, `MAX_VALUES` 1000, `MAX_LIMIT` 1000), **no access rule** by contract | gains filters; grouping needs a new port |
| Port wiring | `TimeTrackPortsPass` + `Port/Ports` (one slot per option, gate over the twin) | the subject port is the first **tagged** multi-service port |
| Exact keys | `Doctrine/TimeTrackExactKeyColumns` + `TimeTrackMetadataListener` (binary NO PAD collation for `scope_key`) | new columns are access keys too |
| Billable | `Integration/TimeEntryBillableDefaultProviderInterface::defaultFor(scopeKey, ?taskRef)` | precedence with the activity type default |
| CSV | `Support/TimeEntryCsv::HEADER`, formula neutralisation | append columns |
| GDPR | `PersonalData/DoctrineTimeTrackPersonalData`, `TimeEntry::anonymiseInPlace()` | keep both fields |
| Scope back-fill | `Command/BackfillEntryScopeCommand` (keyset, port per batch, skips locked periods) | precedent for §12 |
| Timesheets | `TimesheetSnapshotBuilder` (`entries_hash` = id, taskId, times, duration, source) | hash unaffected; totals can add groups |
| Client scopes | `Enum/ClientScope` (`timer:write`, `entries:write`, `tasks:read`, …) | reused, no new scope (Q-TS-12) |

## 3. Concepts and invariants

**Allocation** of an entry or running timer is exactly one of (Q-TS-1, decided Owner 2026-11-23):

| Allocation | `task_id` | `subject_type` / `subject_id` | Allowed when | Gate on write | Since |
|---|---|---|---|---|---|
| Task alone | set | NULL / NULL | always | task access (as today) | 1.0 |
| Task + subject | set | set / set | the subject type's provider declares `allowsTask(): true` (customer-like types) | task access **and** `canLog(subject)` when the subject is set or changed | 2.1 |
| Subject alone | NULL | set / set | any subject type (internal categories, customers) | `canLog(subject)` | 2.1 |
| None (unallocated) | NULL | NULL / NULL | `allow_without_task` on and `require_allocation` off | scope check (as 1.36) | 1.36 |

With `require_allocation: true` an entry must have a task or a subject (any of the first three rows).

Invariants enforced by the entity and the write services (not by a CHECK constraint, see §11):

- I-1: `subject_type` and `subject_id` are both NULL or both set.
- I-2: a task and a subject may be combined only when the subject's type allows tasks (`allowsTask()`); a task +
  subject write with a type that disallows tasks is refused (`allocation_conflict`). On a task entry the task stays the
  primary allocation: task access gates every write, task queries and moves (`TaskTimeMover`) work as today, and the
  subject is checked with `canLog` in addition whenever it is set or changed. `describe()` still filters the subject's
  label by `canRead`, independently of the task's visibility.
- I-3: `activity_type_key` is independent of the allocation: a task entry, a subject entry and an unallocated entry may
  each carry one.
- I-4: an entry never loses its time because of its allocation: no stop that the bundle itself triggers (user deletion,
  start-replacing, switch, idle resolution) is ever refused for lack of a subject (§7.3).
- I-5: subject ids and activity keys are **opaque**, compared byte for byte, never parsed, never trusted from a client
  without `canLog` (subject) or provider validation (activity type).
- I-6: the entry's tenant is its `scope_key`. A subject is always checked in that scope; an entry has no subject from
  another tenant.

## 4. Value objects

All in `Nowo\TimeTrackBundle\Subject\` (new namespace), `final readonly`, framework-free, built per call, never kept by
a service (worker mode). Constructors validate; a VO that exists is valid.

### 4.1 `TimeSubjectRef`

```php
final readonly class TimeSubjectRef
{
    public const TYPE_PATTERN  = '/^[a-z][a-z0-9_]{0,31}$/';   // e.g. customer, organisation, internal
    public const MAX_ID_LENGTH = 64;

    public function __construct(public string $type, public string $id) {}
    public function equals(self $other): bool;   // byte for byte
    public function key(): string;               // "type\0id", for grouping maps inside one call
}
```

- `type`: lower-case ASCII, 1–32 bytes (it is also the provider's tag key).
- `id`: 1–64 bytes, the `ScopeKeys::isUsable()` rule (no edge blank, no control / format / invisible character, valid
  UTF-8): the same rule REQ-SEC-006 applies to scope keys, so `acme` and `acme ` never meet.
- Throws `InvalidArgumentException`; the client API maps it to `400 invalid_request` before any provider is asked.

### 4.2 `TimeSubjectContext` (actor + tenant)

```php
final readonly class TimeSubjectContext
{
    public function __construct(public TimeTrackUserRef $actor, public string $scopeKey) {}
}
```

- `actor`: the existing `TimeTrackUserRef` (the VO every 2.0 port takes; its user object stays hidden in the WeakMap).
- `scopeKey`: the entry's tenant scope, `''` when the host has no scope provider (the same convention as
  `TimeEntryBillableDefaultProviderInterface::defaultFor()`).
- Who the actor is: the **owner of the time** on writes (also when a host writes for another person through
  `TimeEntryOperationsInterface`, Q-TS-7), the **viewer** on reads.

### 4.3 `TimeSubjectLabel`

```php
final readonly class TimeSubjectLabel
{
    public function __construct(
        public TimeSubjectRef $subject,
        public string $label,        // cleaned, 1–255 chars
        public ?string $url = null,  // https URL on the allowed origin, or null
        public ?string $hint = null, // secondary line in pickers (e.g. tax id, city), cleaned, ≤ 255 chars
    ) {}
}
```

The constructor cleans `label` and `hint` with the note rules (`NoteSanitizer`: control, bidi-override and invisible
characters removed, cut to 255) and drops a `url` that is not an absolute `https` URL without credentials (the
`ClientTaskPresenter` rule; the client API also applies `client_api.task_url.origin`). Labels are **plain text**: every
surface escapes them on output (§14).

### 4.4 `TimeSubjectLabels`

The answer of a batched read: `get(TimeSubjectRef): ?TimeSubjectLabel` (null = not readable or unknown, the caller
cannot tell which), `readable(TimeSubjectRef): bool`. Built per call, discarded with the response.

### 4.5 `TimeSubjectSearch`

```php
final readonly class TimeSubjectSearch
{
    public const MAX_QUERY_LENGTH = 100;   // characters, after cleaning
    public const MAX_LIMIT        = 50;
    public function __construct(public string $type, public string $query = '', public int $limit = 20) {}
}
```

### 4.6 `ActivityType`

```php
final readonly class ActivityType
{
    public const KEY_PATTERN = '/^[a-z0-9][a-z0-9_.-]{0,63}$/';
    public function __construct(
        public string $key,
        public string $label,             // cleaned like a subject label
        public ?bool $defaultBillable = null,
        public bool $active = true,       // false = still labelled on old entries, refused on new writes
        public ?string $group = null,     // optional grouping in pickers ("Internal", "Customer")
    ) {}
}
```

### 4.7 `ActivityTypeList`

`ActivityTypeList::of(list<ActivityType>)`: refuses duplicate keys; `find(string $key): ?ActivityType`,
`active(): list<ActivityType>`. At most 500 types per tenant (more is a host bug, refused with a logged warning and an
empty list rather than an unbounded payload).

### 4.8 `TimeAllocation` (write input)

```php
final readonly class TimeAllocation
{
    public static function task(string $taskId, ?TimeSubjectRef $subject = null, ?string $activityTypeKey = null): self;
    public static function subject(TimeSubjectRef $subject, ?string $activityTypeKey = null): self;
    public static function none(?string $activityTypeKey = null): self;   // the 1.36 task-less case
}
```

One VO for every write path, so I-1 is structural. Whether a task + subject is allowed depends on the provider's
`allowsTask()` and is checked by the guard (§7.1).

### 4.9 `TimeAllocationPatch` (correction input)

Tri-state per field, because a correction must tell *keep* from *clear*:
`TimeAllocationPatch::keep()`, `->withSubject(TimeSubjectRef)`, `->withoutSubject()`, `->withActivityType(string)`,
`->withoutActivityType()`.

## 5. Ports

### 5.1 `TimeSubjectProviderInterface` (tagged, one per type)

```php
namespace Nowo\TimeTrackBundle\Subject;

#[AutoconfigureTag('nowo_time_track.time_subject_provider')]
interface TimeSubjectProviderInterface
{
    /** The subject type this provider answers for (TimeSubjectRef::TYPE_PATTERN). Constant per class. */
    public static function subjectType(): string;

    /**
     * Whether a subject of this type may sit on a task entry next to the task (Q-TS-1): true for customer-like types,
     * false for internal categories. Constant per class (read at compile time).
     */
    public static function allowsTask(): bool;

    /** Whether the actor may log time on the subject in the context's tenant. Deny by default. */
    public function canLog(TimeSubjectContext $context, TimeSubjectRef $subject): bool;

    /**
     * Labels (and URLs) of the subjects the actor may READ in the tenant; a subject left out is not readable (or does
     * not exist: one answer). At most 1000 refs per call, all of this provider's type, distinct.
     *
     * @param list<TimeSubjectRef> $subjects
     */
    public function describe(TimeSubjectContext $context, array $subjects): TimeSubjectLabels;

    /** Subjects the actor may log on, matching the query; at most $search->limit. */
    public function search(TimeSubjectContext $context, TimeSubjectSearch $search): array; // list<TimeSubjectLabel>
}
```

Wiring:

- Autoconfigured tag (`#[AutoconfigureTag]` on the interface): a host implements the interface and is done, on standard
  Symfony and on DDD hosts (no bundle class in the host's domain, the VOs are the only bundle types it sees).
- A compiler pass (`TimeSubjectProvidersPass`) builds a `ServiceLocator` keyed by `subjectType()` and a constant
  `type => allowsTask()` map. It fails the build on a type that does not match the pattern or on two providers for one
  type.
- `TimeSubjectRegistry` (internal, stateless) holds that locator and map: `has(type)`, `provider(type)`,
  `allowsTask(type)`, `types()`. An
  **unknown type is denied** on every path (write → `subject_forbidden`, read → masked, search → empty).
- Batched reads: callers never call `describe()` per entry. `TimeSubjectLabelResolver::forPage(context, list<TimeEntryView>)`
  groups the page's refs by type and calls each provider **once per type per page** (≤ 1000 refs, the page cap), so
  a listing costs one call per subject type present, not one per row.

Why `describe()` replaces Beacon's separate `labels(ids)`, `urls` and `canRead(user, tenant, id)`: see §17 O-1. A
convenience `canRead()` is derived (`describe(ctx, [ref])->readable(ref)`) for single-entry paths.

### 5.2 `ActivityTypeProviderInterface` (single service)

```php
namespace Nowo\TimeTrackBundle\Subject;

interface ActivityTypeProviderInterface
{
    /** Every activity type of the tenant, retired ones included (active: false). */
    public function activityTypesFor(string $scopeKey): ActivityTypeList;
}
```

- Configured like the other single ports: alias the interface to your service (the `Ports` / `TimeTrackPortsPass`
  pattern, slot `nowo_time_track.port.activity_types`). Default: none → activity types off.
- The bundle validates a key on every write: it must exist **and be active** in the entry's scope
  (`activity_type_unknown`, `activity_type_inactive`). Reads label retired keys too.
- Called at most once per request path per scope; **no memo** across calls (worker mode). A host that wants caching
  does it in its own provider with a tag-aware PSR-6 pool it invalidates on change.

### 5.3 Optional built-in provider: `ScopeTimeSubjectProvider` (Q-TS-13)

Opt-in (`entries.subjects.scope_subject.enabled`, default false; `type` default `organisation`): the subject is the
tenant itself, built from the existing `TimesheetScopePolicyInterface::scopesOf()`. `canLog` = the id is the context's
scope and one of the actor's scopes; `allowsTask()` false (internal time, never next to a task); `describe` = the scope label; `search` = the actor's scopes. It gives "internal
time on the organisation under a category" with **zero host code** beyond activity types. Beacon may still register
its own `organisation` provider instead (the build refuses two for one type).

## 6. Configuration and "off by default"

```yaml
nowo_time_track:
  entries:
    allow_without_task: false        # 1.36, unchanged
    require_allocation: false        # 2.1: refuse an interactive stop / manual entry with neither task nor subject
    require_activity_type: false     # 2.1: refuse a new entry without an activity type (only with a provider)
    subjects:
      scope_subject:
        enabled: false               # 2.1: built-in "the tenant itself" provider (§5.3)
        type: organisation
      search:
        max_per_minute: 60           # client API search rate per token
```

Feature flags are **derived**, not configured:

| State | Effect |
|---|---|
| No subject provider tagged | subject fields refused like an unknown type; `GET me` reports `subjects: []`; columns stay NULL |
| No activity type provider | `activityTypeKey` refused (`activity_type_unknown`); `GET me` reports `activityTypes: false` |
| `require_allocation: true` without any provider | build fails (a host could never allocate) unless `allow_without_task` is false, in which case it is a no-op |
| Subjects need `allow_without_task`? | **No.** A subject entry is an allocated entry; `allow_without_task` keeps governing only the unallocated case |

Standard Symfony host: implement the interface, autoconfiguration tags it. DDD host: same (the interface takes only VOs);
the adapter lives in its infrastructure layer.

## 7. Writes

### 7.1 Common rule (every path)

`AllocationGuard` (internal, stateless) runs before the unit of work:

1. Structure: `TimeAllocation` already enforces I-1.
2. Task: when a task is given, task access and trackability as today (`TaskAccessPolicyInterface` /
   `TaskDirectoryInterface`): the task stays the gate of a task entry.
3. Scope: the entry's scope as today (task, with or without subject → `scopeKeyFor(task)`; subject alone / none →
   `ScopeKeys::forEntryWithoutTask()` with the requested scope, refused when not one of the owner's).
4. Subject (when set or changed): `registry->has(type)` else `subject_forbidden`; with a task,
   `registry->allowsTask(type)` else `allocation_conflict`; `provider->canLog(ctx(owner, scope), ref)` else
   `subject_forbidden` (same code for unknown type, unknown id and no access: no enumeration oracle). On a task + subject
   entry the subject is checked in the **task's** scope (I-6).
5. Activity type: `provider->activityTypesFor(scope)->find(key)` exists and is active.
6. `require_allocation` (task or subject) / `require_activity_type` when on.

The host ports are asked **before** the owner's lock (as `isServiceAccount()` is today), so a slow host provider never
holds the bundle's row locks. The unit then writes what was checked; a permission revoked between check and write lands
like the same race on tasks today (accepted, documented).

### 7.2 Paths

| Path | Today | 2.1 change |
|---|---|---|
| `HostTimerApi::start()` / `startWithoutTask()` | task / none | new `startAllocated(TimeTrackUserRef, TimeAllocation, ?note, channel, ?scopeKey)`; old methods unchanged |
| `HostTimerApi::stop()` | stop | new optional `?TimeAllocationPatch $allocation`; returns `TimerEntrySnapshot` or throws `TimeAllocationRequiredException` |
| `TimerService::startAt()` / `startReplacing()` / `switchTo()` | task or none | take `TimeAllocation`; stored on `ActiveTimer` (new columns) |
| `TimerService::stopAt()` | — | optional allocation patch applied at stop: set / change / clear the subject (next to the task when the type allows tasks) and the activity type; the task itself is never changed at stop |
| `TimerService::resolveIdle()` (`Reassign`) | to another task | may reassign the idle span to a `TimeAllocation` |
| new `TimerService::allocateRunning()` | — | sets / changes subject and activity type of the running timer (Q-TS-19) |
| `ManualEntryService::create()` / `ManualTimeEntryDraft` | task or none | `ManualTimeEntryDraft::$allocation` (new optional arg; `$taskId` kept for BC and mapped) |
| `TimeEntryOperationsInterface::correct()` | times, duration, metadata | `TimeEntryCorrection::$allocation` (`TimeAllocationPatch`, default keep) |
| `TimeEntryOperationsInterface::createManual()` | via draft | via draft |
| Client API | task only | §9 |
| Imports (`ImportedTimeEntryData`) | task | unchanged in 2.1 (out of scope) |

Correction rules:

- `canLog` is checked only when the subject **changes** (Q-TS-8): a times-only fix of an entry on a subject the owner
  lost access to stays possible, as for tasks.
- Setting or changing the subject of a task entry is allowed when the type allows tasks (else `allocation_conflict`),
  with `canLog` on the new subject; task access is the entry's existing gate. The task itself is not changed by a
  correction: moving task time stays `TaskTimeMover`, which keeps the subject (re-allocating it after a task move is the
  host's call).
- Clearing the subject of a task entry is always allowed (the task still allocates it). Clearing the subject of a
  subject-alone entry leaves it unallocated: refused with `require_allocation`, or when `allow_without_task` is off.
- A row whose type stopped allowing tasks after it was written (a provider change) keeps working for corrections that
  do not touch the subject; `check-allocation` reports it (§12.2).
- Period lock, overlap, read-only and anonymised rules are unchanged: an allocation change in a locked or submitted
  period is refused like any correction.
- `TimeEntryCorrectionResult::$changedFields` appends `subject` and `activity_type_key` **after** the existing four,
  keeping their documented order.
- The revision "keeps both": `before` and `after` are `TimeEntryRecord`s, which gain the subject and activity type
  (§8.1). The host's revision table stores them from there; the bundle stores no revision itself (unchanged model).

### 7.3 Stop without allocation

With `require_allocation: true`, an **interactive** stop (client API `timer/stop`, `HostTimerApi::stop()`, the web UI,
`TimeEntryOperationsInterface::createManual()`, `POST entries`) of a timer that has neither task nor subject, and that
carries no allocation, is refused:

- PHP: `TimeAllocationRequiredException` (extends the bundle's `RuntimeException` family, code
  `allocation_required`, carries the `TimerSnapshot` of the still-running timer).
- Client API: `409 allocation_required` with `{timer}` so the client opens its "allocate this time" dialog, then calls
  `timer/stop` again with `subject` (and optionally `activityTypeKey`) — or `timer/allocate` then `timer/stop`.
- Nothing is written; the timer keeps running (its end is fixed by the second call's `endedAt`, so the dialog's time is
  not added: the client sends the `endedAt` it showed).

**System stops are never refused** (I-4): `stopForUserDeletion()`, the stop inside `startReplacing()` and `switchTo()`,
idle resolution, and any future auto-stop store the entry **unallocated** and dispatch `TimeEntryUnallocatedEvent`
(§15) so the host can ask the person later. The query side lists them (`allocation: none`, §8.2) and
`correct()` allocates them. Refusing those stops would either lose time (owner rule 2026-11-06) or block an agent's
start-replacing.

## 8. Reads, query port and grouping

### 8.1 Views and records

- `TimeEntryView` gains `public ?TimeSubjectRef $subject = null` and `public ?string $activityTypeKey = null` (appended
  optional constructor arguments, BC). Same for `TimerSnapshot` / `TimerEntrySnapshot` / `RunningTimerView`.
- `TimeEntryRecord::version()`: the two new values are appended to the hashed array **only when set**, so the token of
  every entry that has neither is byte-identical to 2.0 (no wave of spurious `conflict`s after the upgrade), and any
  allocation change changes the token.
- Labels are **not** on the record (the query port applies no access rule, by contract since 1.35). A host labels a page
  with the public `TimeSubjectLabelResolver::forPage(TimeSubjectContext, list<TimeEntryView>): TimeSubjectLabels`, which
  applies `canRead` through `describe()`.

### 8.2 `TimeEntryCriteria` filters

New optional named arguments (all byte-exact, each list ≤ `MAX_VALUES`, an empty list matches nothing, as today):

| Argument | Type | SQL |
|---|---|---|
| `subjects` | `list<TimeSubjectRef>` | `(subject_type, subject_id) IN ((?, ?), …)` written as `OR`ed pairs grouped by type: `subject_type = ? AND subject_id IN (…)` |
| `subjectTypes` | `list<string>` | `subject_type IN (…)` |
| `activityTypeKeys` | `list<string>` | `activity_type_key IN (…)` |
| `allocation` | `AllocationFilter` enum `Any` / `TaskOnly` / `TaskAndSubject` / `SubjectOnly` / `Unallocated` | `task_id IS [NOT] NULL AND subject_type IS [NOT] NULL` |

The key rule is unchanged: a query still needs owner ids, scope keys, task ids or board ids. A subject filter alone is
not a key (a customer id is not a tenant). `matchesExactly()` re-checks the new columns in PHP like the others.
A `subjects` filter matches task + subject entries and subject-alone entries alike, so "all time for customer X"
includes the customer's task time that carries the subject.

### 8.3 Grouping: new `TimeEntryTotalsQueryInterface`

A new public service rather than a method on `TimeEntryQueryInterface` (adding a method to an interface a host might
decorate is a BC break in a minor):

```php
interface TimeEntryTotalsQueryInterface
{
    public const MAX_GROUPS     = 1000;
    public const MAX_RANGE_DAYS = 366;

    /** @return TimeEntryTotals list of TimeEntryTotal{group key VO, seconds, entries} + truncated flag */
    public function totals(TimeEntryCriteria $criteria, TimeEntryGrouping $groupBy, int $limit = 200): TimeEntryTotals;
}

enum TimeEntryGrouping { case Subject; case SubjectType; case ActivityType; case SubjectAndActivityType; case Owner; case Task; case Allocation; }
```

Bounded SQL:

- the criteria must carry a key (as above) **and** a closed start range (`startedFrom` and `startedTo`) of at most
  366 days; otherwise `InvalidArgumentException`;
- one statement: `SELECT <group cols>, SUM(duration_seconds), COUNT(*) FROM time_track_entries WHERE <criteria> AND
  user_id IS NOT NULL GROUP BY <group cols> ORDER BY SUM(duration_seconds) DESC, <group cols> LIMIT :limit + 1`;
  `limit + 1` sets `truncated` without a second query;
- served by the new indexes (§11) for tenant queries and by `idx_time_track_entries_user_scope_started` for owner
  queries; no `OFFSET`, no per-group follow-up queries;
- group keys are re-checked byte for byte in PHP and merged (a folding collation cannot merge `Acme` and `acme`: the
  columns are binary anyway, §11);
- no access rule, like the list port; the host passes the owners / scopes the viewer may see and labels the groups with
  `TimeSubjectLabelResolver` (one `describe()` per type for ≤ 1000 groups).

### 8.4 Bundle read surfaces apply `canRead`

Client API `GET entries`, `GET timer`, `GET export`, the web UI / Twig components and the CSV export resolve labels for
the page with the **viewer** as actor. A subject the viewer may not read is **masked**: `subject: {type, hidden: true,
label: "<translated 'Subject not visible'>"}` with **no id** (Q-TS-6). Time, dates and activity type stay visible (the
entry's own visibility is still decided by `TimeEntryAccess`, unchanged). Same pattern as `TimeEntry::HIDDEN_TASK_TITLE`.

## 9. Client API (desktop and extension)

Contract addition **v0.4** after v0.3.1 + timetrack-next v1.1 (additive; old clients keep working because every new field is optional and a task-less
`timer/start` was not allowed before).

| Endpoint | Scope | Change |
|---|---|---|
| `GET me` | (token) | `features.subjects: [{type, label, allowsTask}]`, `features.activityTypes: bool`, `features.requireAllocation: bool`, `features.requireActivityType: bool` |
| `POST timer/start` | `timer:write` | body: `taskId`, `subject: {type, id}`, or both when the type allows tasks (neither only when `allow_without_task` is on and `require_allocation` off), `activityTypeKey?`, `scopeKey?` (ignored with a task: the task's scope) |
| `POST timer/switch` | `timer:write` | same allocation body for the next timer |
| `POST timer/allocate` (new) | `timer:write` | `{subject?, activityTypeKey?}` on the running timer, task or task-less (`subject: null` clears it) |
| `POST timer/stop` | `timer:write` | optional `subject`, `activityTypeKey`; `409 allocation_required` (§7.3) |
| `POST timer/discard-idle` / idle reassign | `timer:write` | reassign target may be a subject |
| `POST entries` | `entries:write` | `taskId` or `subject`, `activityTypeKey?`; same `Idempotency-Key` (the body hash covers the new fields) |
| `GET subjects/search?type=&q=&limit=` (new) | `tasks:read` | `provider->search()`, `limit` ≤ 50, `q` ≤ 100 chars, rate-limited per token (`ClientApiRateLimiter`, `subjects.search.max_per_minute`) |
| `GET activity-types?scopeKey=` (new) | `tasks:read` | active types of one of the user's scopes |
| `GET entries`, `GET timer`, `GET export` | as today | `subject {type, id, label, url} \| {type, hidden, label}`, `activityType {key, label} \| null` |

Rules:

- Every subject in a request goes through §7.1 (`canLog` with the **token's user** as actor and the entry's scope);
  the id from the client is never used before that. With a task, task access is checked first (`403 task_forbidden`
  as today), then the subject.
- Errors: `400 invalid_request` (malformed ref / key), `403 subject_forbidden` (unknown type, unknown id, no access: one
  answer), `422 activity_type_unknown` / `activity_type_inactive` / `allocation_conflict` (task + subject whose type disallows
  tasks), `409 allocation_required`.
- No new client scope (Q-TS-12): existing desktop and extension tokens keep working without a new sign-in.
- Audit: `ClientApiAuditor` records `subject_type` (not the id) on writes, as it records task writes today.

## 10. Export, GDPR and retention

| Surface | Change |
|---|---|
| CSV (`TimeEntryCsv::HEADER`) | appends `subject_type`, `subject_id`, `subject_label`, `activity_type_key`, `activity_type_label` at the **end** (column order of existing consumers unchanged); labels resolved per page with the viewer; masked rows get an empty id and the hidden label; every cell keeps the formula neutralisation |
| GDPR export (`TimeTrackPersonalDataInterface`) | entries and the running timer include `subjectType`, `subjectId`, `activityTypeKey`; labels resolved with the **data subject** as actor, masked when not readable (Q-TS-17) |
| Erasure (`anonymiseInPlace()`, `TimeEntryErasure::FreeText`) | **keeps** `subject_type`, `subject_id`, `activity_type_key` and the time (they describe the work, not the person); free text is cleared as today |
| Retention purge (`TimesheetsPurgeCommand`, former-person purges) | rows are deleted whole as today; nothing specific |
| Timesheet snapshot | `entries_hash` unchanged (it never covered notes / descriptions; changing it would invalidate approved hashes); `totals` gains `bySubject` and `byActivityType` (ids only, like `byTask`) — Q-TS-14 |
| Third-party data | a subject that is itself a person (a contact) is the host's data; erasing that contact is the host's job (Q-TS-16) |

## 11. Schema and DDL

### 11.1 Column contract

| Column | Tables | Type | Rule |
|---|---|---|---|
| `subject_type` | `time_track_entries`, `time_track_active_timers` | `VARCHAR(32)` NULL | `^[a-z][a-z0-9_]{0,31}$`; NULL iff `subject_id` NULL |
| `subject_id` | both | `VARCHAR(64)` NULL | 1–64 bytes, `ScopeKeys::isUsable()`; NULL iff `subject_type` NULL; may be set next to `task_id` only for a type whose provider allows tasks |
| `activity_type_key` | both | `VARCHAR(64)` NULL | `^[a-z0-9][a-z0-9_.-]{0,63}$`; a key of the entry's scope (provider) |

- All three are **exact keys** (REQ-SEC-006): added to `TimeTrackExactKeyColumns` and given the binary NO PAD collation
  by `TimeTrackMetadataListener` on MySQL / MariaDB, like `scope_key`. On a folding collation, a filter on customer
  `ACME` would also return `acme`'s time.
- No CHECK constraint: Doctrine does not introspect CHECKs (no schema-diff noise is gained), SQLite would need a table
  rebuild to add one, and the entity enforces I-1 / I-2 on every write. The check command (§12.3) reports rows a host
  wrote by hand that break the contract.
- No foreign key: the subject lives in the host (opaque reference, like `task_id`).
- Task + subject rows need no extra column or index: `idx_time_track_entries_scope_subject` serves subject totals of
  task and task-less time alike, and the existing `time_track_entries_task_idx` serves task queries. The DDL below is
  unchanged by the Q-TS-1 revision.

Indexes (entries only; running timers are one row per user):

- `idx_time_track_entries_scope_subject` (`scope_key`, `subject_type`, `subject_id`, `started_at`)
- `idx_time_track_entries_scope_activity` (`scope_key`, `activity_type_key`, `started_at`)

Owner-keyed queries keep using `idx_time_track_entries_user_scope_started`. Key length on MySQL utf8mb4:
256 + 128 + 256 + 5 bytes, under the 3072-byte limit.

The scripts ship as `docs/sql/2.1.0/{mysql,mariadb,postgresql,sqlite}-{up,down}.sql`, with the default table prefix,
like the 1.36.0 set.

### 11.2 MySQL 8.4

```sql
-- TimeTrackBundle 2.1.0, MySQL 8.4 (default table prefix). Subject and activity type. No row is rewritten.
ALTER TABLE time_track_entries
  ADD subject_type VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  ADD subject_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  ADD activity_type_key VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  ALGORITHM=INSTANT;
ALTER TABLE time_track_active_timers
  ADD subject_type VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  ADD subject_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  ADD activity_type_key VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
  ALGORITHM=INSTANT;
CREATE INDEX idx_time_track_entries_scope_subject ON time_track_entries (scope_key, subject_type, subject_id, started_at) ALGORITHM=INPLACE LOCK=NONE;
CREATE INDEX idx_time_track_entries_scope_activity ON time_track_entries (scope_key, activity_type_key, started_at) ALGORITHM=INPLACE LOCK=NONE;
```

Down:

```sql
-- Rollback to 2.0. Drops the allocation of every entry (never its time). Export it first if you may come back.
DROP INDEX idx_time_track_entries_scope_activity ON time_track_entries;
DROP INDEX idx_time_track_entries_scope_subject ON time_track_entries;
ALTER TABLE time_track_active_timers DROP subject_type, DROP subject_id, DROP activity_type_key;
ALTER TABLE time_track_entries DROP subject_type, DROP subject_id, DROP activity_type_key;
```

### 11.3 MariaDB 11.4

Same statements with `utf8mb4_nopad_bin` (MariaDB has no `utf8mb4_0900_bin`; its 11.4 default
`utf8mb4_uca1400_ai_ci` folds case and must not be used for these keys). `ALGORITHM=INSTANT` is supported for
`ADD COLUMN` (10.4+); `CREATE INDEX … ALGORITHM=INPLACE LOCK=NONE` as above.

```sql
ALTER TABLE time_track_entries
  ADD subject_type VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin DEFAULT NULL,
  ADD subject_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin DEFAULT NULL,
  ADD activity_type_key VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin DEFAULT NULL,
  ALGORITHM=INSTANT;
ALTER TABLE time_track_active_timers
  ADD subject_type VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin DEFAULT NULL,
  ADD subject_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin DEFAULT NULL,
  ADD activity_type_key VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin DEFAULT NULL,
  ALGORITHM=INSTANT;
CREATE INDEX idx_time_track_entries_scope_subject ON time_track_entries (scope_key, subject_type, subject_id, started_at) ALGORITHM=INPLACE LOCK=NONE;
CREATE INDEX idx_time_track_entries_scope_activity ON time_track_entries (scope_key, activity_type_key, started_at) ALGORITHM=INPLACE LOCK=NONE;
```

Down: as MySQL.

### 11.4 PostgreSQL 16

Adding a nullable column without a default is metadata-only. The default deterministic collation compares exactly.
Indexes are built `CONCURRENTLY`, so they run outside the transaction.

```sql
-- TimeTrackBundle 2.1.0, PostgreSQL 16 (default table prefix). Run with psql.
\set ON_ERROR_STOP on
BEGIN;
ALTER TABLE time_track_entries ADD subject_type VARCHAR(32) DEFAULT NULL;
ALTER TABLE time_track_entries ADD subject_id VARCHAR(64) DEFAULT NULL;
ALTER TABLE time_track_entries ADD activity_type_key VARCHAR(64) DEFAULT NULL;
ALTER TABLE time_track_active_timers ADD subject_type VARCHAR(32) DEFAULT NULL;
ALTER TABLE time_track_active_timers ADD subject_id VARCHAR(64) DEFAULT NULL;
ALTER TABLE time_track_active_timers ADD activity_type_key VARCHAR(64) DEFAULT NULL;
COMMIT;
CREATE INDEX CONCURRENTLY idx_time_track_entries_scope_subject ON time_track_entries (scope_key, subject_type, subject_id, started_at);
CREATE INDEX CONCURRENTLY idx_time_track_entries_scope_activity ON time_track_entries (scope_key, activity_type_key, started_at);
```

Down:

```sql
\set ON_ERROR_STOP on
DROP INDEX CONCURRENTLY IF EXISTS idx_time_track_entries_scope_activity;
DROP INDEX CONCURRENTLY IF EXISTS idx_time_track_entries_scope_subject;
BEGIN;
ALTER TABLE time_track_active_timers DROP COLUMN subject_type, DROP COLUMN subject_id, DROP COLUMN activity_type_key;
ALTER TABLE time_track_entries DROP COLUMN subject_type, DROP COLUMN subject_id, DROP COLUMN activity_type_key;
COMMIT;
```

(A Doctrine Migrations host that wants one transaction drops `CONCURRENTLY` and accepts the write lock of the build.)

### 11.5 SQLite

`ADD COLUMN` of a nullable column needs no table rebuild (unlike the 1.36.0 `NOT NULL` change). One column per statement.

```sql
-- TimeTrackBundle 2.1.0, SQLite 3.35+ (default table prefix). Run with the sqlite3 CLI.
.bail on
BEGIN;
ALTER TABLE time_track_entries ADD COLUMN subject_type VARCHAR(32) DEFAULT NULL;
ALTER TABLE time_track_entries ADD COLUMN subject_id VARCHAR(64) DEFAULT NULL;
ALTER TABLE time_track_entries ADD COLUMN activity_type_key VARCHAR(64) DEFAULT NULL;
ALTER TABLE time_track_active_timers ADD COLUMN subject_type VARCHAR(32) DEFAULT NULL;
ALTER TABLE time_track_active_timers ADD COLUMN subject_id VARCHAR(64) DEFAULT NULL;
ALTER TABLE time_track_active_timers ADD COLUMN activity_type_key VARCHAR(64) DEFAULT NULL;
CREATE INDEX idx_time_track_entries_scope_subject ON time_track_entries (scope_key, subject_type, subject_id, started_at);
CREATE INDEX idx_time_track_entries_scope_activity ON time_track_entries (scope_key, activity_type_key, started_at);
COMMIT;
```

Down (SQLite 3.35+ supports `DROP COLUMN` for unindexed columns, so the indexes go first):

```sql
.bail on
BEGIN;
DROP INDEX idx_time_track_entries_scope_activity;
DROP INDEX idx_time_track_entries_scope_subject;
ALTER TABLE time_track_active_timers DROP COLUMN activity_type_key;
ALTER TABLE time_track_active_timers DROP COLUMN subject_id;
ALTER TABLE time_track_active_timers DROP COLUMN subject_type;
ALTER TABLE time_track_entries DROP COLUMN activity_type_key;
ALTER TABLE time_track_entries DROP COLUMN subject_id;
ALTER TABLE time_track_entries DROP COLUMN subject_type;
COMMIT;
```

SQLite compares `=` with `BINARY` by default, which is exact.

## 12. Backfill of existing entries

### 12.1 Options

| Option | For | Against |
|---|---|---|
| A. Host DBAL migration + documented SQL snippet + column contract | the mapping (which old task-less entry is which customer, which description means which category) is **host data**; one set-based `UPDATE … FROM`/`JOIN` per tenant is orders of magnitude faster than a PHP loop; no new bundle port that is used exactly once; runs in the host's migration pipeline where it is versioned and reviewed | writes the bundle's table outside the bundle (REQ-ARCH-001 rule 6 spirit); couples to the table name / prefix; skips the bundle's validation |
| B. Bundle command `entries:backfill-allocation` + a host port `TimeAllocationBackfillProviderInterface::allocationsFor(list<TimeEntryView>)` (the `BackfillEntryScopeCommand` pattern) | validated writes, keyset + resumable, skips locked periods, no host SQL on bundle tables | a port and a command for a one-off; the scope back-fill had a reason the subject back-fill lacks (the scope port already existed and the bundle itself needs scopes for timesheets); the host still has to compute the mapping, so it writes the same logic in PHP |
| C. Bundle command reading a mapping file (entry id → subject / activity) | generic, no new port, validated | the host still builds the file with SQL; two steps instead of one; big files in worker / container setups |

### 12.2 Recommendation: **A, with two safeguards** (agrees with the owner's lean)

1. The bundle ships the **column contract** (§11.1) in `docs/UPGRADING.md` "To 2.1.0" and a documented snippet per
   platform, e.g. MySQL / MariaDB:

   ```sql
   -- Example 1: task-less entries that were customer calls; host table crm_call_log maps entry ids to customers.
   -- Only rows without a subject are touched, so the migration is idempotent.
   UPDATE time_track_entries e
     JOIN crm_call_log m ON m.time_entry_id = e.id
      SET e.subject_type = 'customer', e.subject_id = m.customer_id, e.activity_type_key = 'support_call'
    WHERE e.task_id IS NULL AND e.subject_type IS NULL AND e.anonymised_at IS NULL;

   -- Example 2 (spec 250 customer time on tasks): give existing task entries the customer of their task.
   -- host_task_customer is a host view or table (task_id, customer_id); 'customer' must be a type whose provider
   -- allowsTask(). Same statement on time_track_active_timers for timers already running, if wanted.
   UPDATE time_track_entries e
     JOIN host_task_customer m ON m.task_id = e.task_id
      SET e.subject_type = 'customer', e.subject_id = m.customer_id
    WHERE e.task_id IS NOT NULL AND e.subject_type IS NULL AND e.anonymised_at IS NULL;
   ```

   PostgreSQL uses `UPDATE … FROM`; SQLite 3.33+ also supports `UPDATE … FROM`, e.g. for example 2:

   ```sql
   UPDATE time_track_entries AS e
      SET subject_type = 'customer', subject_id = m.customer_id
     FROM host_task_customer AS m
    WHERE m.task_id = e.task_id AND e.task_id IS NOT NULL AND e.subject_type IS NULL AND e.anonymised_at IS NULL;
   ```

   On a large table run example 2 per tenant (`AND e.scope_key = ?`) or in id ranges, to keep each transaction short.
2. The bundle ships a **read-only** check command `nowo:time-track:entries:check-allocation [--scope=] [--fix-report]`
   (keyset, bounded batches) that reports rows breaking the contract: half-set pair, task + subject whose type
   disallows tasks (`allowsTask()` false), malformed type / id / key, unknown type (no provider), activity key unknown or inactive in the row's scope. Exit code non-zero on
   findings, so a host can run it in CI / after the migration. It writes nothing.

Why A is acceptable under REQ-ARCH-001: the rule targets **runtime** writes that bypass the bundle's rules and entity
manager. A schema migration is already host-owned (the host applies `docs/sql/*`); a one-off data step in the same
migration, constrained by a published contract and verified by the bundle's check command, is the same class of
operation. Worker mode is not affected (the private entity manager holds no entity across requests). Version tokens
of back-filled rows change, so an edit form opened before the migration gets `conflict` instead of overwriting:
the correct outcome.

Locked and submitted periods: the back-fill only classifies time, the `entries_hash` does not cover the new columns, so
back-filling approved periods is allowed (Q-TS-15); the check command reports how many back-filled rows sit in locked
periods so the host can tell its approvers. Runtime corrections in locked periods stay refused.

## 13. Concurrency (REQ-RUNTIME-003)

Every timer write already runs under the owner's write lock (lock order user → timer → entries,
`UserSerialisedTimeEntryRepositoryInterface`), and `closeTimer()` builds the entry from the timer **re-read under the
delete's lock** (`removeIfPresent`). The design keeps that order and adds no new lock.

| Race | Outcome |
|---|---|
| **Double stop** (two devices, task or task + subject) | unchanged: only the call that deletes the timer row records the entry (`removeIfPresent`, CL-05); the loser gets `null` → `404 no_active_timer`. If the two carried different subjects, the winner's allocation is stored; the loser's client re-reads the entry and may `correct()` it with its version. |
| **Stop without allocation vs stop with a subject** | the refusal is decided inside the unit on the re-read timer; a refused stop throws before the delete commits, so the unit rolls back and the timer stays for the other call. Whichever unit locks first wins; neither loses time. |
| **Stop racing `timer/allocate`** | both take the owner's lock. Allocate first → the stop's re-read timer carries the subject and the entry records it. Stop first → allocate finds no timer → `404 no_active_timer`; the client then corrects the new entry by version. No lost update, no half state. |
| **Allocate on a task timer racing a switch** | both take the owner's lock. Allocate first → the switch's stop records the subject on the old task's entry. Switch first → allocate lands on the new timer (it is the running one); the client sees the result in the response and may `correct()` the old entry. |
| **Task access revoked while a task + subject timer runs** | unchanged from today: the stop records the entry (time is never lost); the next write that changes the subject re-checks task access and `canLog`. |
| **Allocate racing allocate** | serialised by the owner's lock; last write wins on a running timer (no version on timers, as for today's description / metadata). Documented. |
| **Correct racing correct** | unchanged optimistic model: `expectedVersion` is checked under the row lock; the version covers subject and activity type (§8.1), so a concurrent allocation change makes the second correction `conflict`, never an overwrite. |
| **Correct racing the back-fill** | the back-fill's `UPDATE` changes the version; the correction gets `conflict`. The snippet only touches unallocated rows, so re-running after a conflict is safe. |
| **Permission revoked between check and write** | provider asked before the lock (§7.1); the write lands. Same accepted window as tasks; the next write re-checks. |
| **Activity type retired between check and write** | same window; the entry keeps a key that later reads label as retired. |
| **Lock wait / deadlock** | surfaces as `TransientStorageFailure` / `CONFLICT_001` with nothing committed, as today; the client API returns its existing retryable answer. |

Worker mode (`kernel.reset: false`): no memo anywhere. The provider registry is an immutable `ServiceLocator`;
`TimeSubjectLabels`, `ActivityTypeList` and `TimeSubjectSearch` are per call; nothing is stored on a service. The
bundle's worker tests (memory and state leak, `docs/FRANKENPHP-WORKER-AUDIT.md`) gain a case that labels two pages
for two viewers in one kernel and asserts the second sees nothing of the first.

## 14. Security

- **Task + subject**: task access stays the gate of a task entry, exactly as today; `canLog(subject)` is checked in
  addition on any write that sets or changes the subject, and a type that disallows tasks is refused next to a task.
  Reading a task entry with a subject masks the subject (by `canRead`) independently of the task.
- **canLog on every write that sets or changes a subject**, on every path in §7.2, including `HostTimerApi`,
  `TimeEntryOperationsInterface` and the client API. A subject id from the client is used only after `canLog` for the
  token's user in the entry's scope.
- **canRead on every bundle read surface** (§8.4) through the batched `describe()`; unreadable subjects are masked
  without their id. The host-facing query ports stay rule-free by contract and document that the host must label with
  `TimeSubjectLabelResolver` (which applies `canRead`).
- **Deny by default**: unknown type, provider exception (logged, answered as denied on reads and as
  `subject_forbidden` on writes, never 500 with host details), id that fails the VO rule.
- **No oracle**: unknown type, unknown id and no access share `subject_forbidden`; search returns only what the provider
  returns for the actor.
- **Labels escaped**: VO cleaning (control / bidi / invisible characters, 255 chars) plus output escaping on every
  surface: Twig autoescape (no `|raw`, REQ-UI checks `check-no-raw-html-form`), JSON encoding, CSV formula
  neutralisation; URLs only `https` on the allowed origin.
- **Search bounded**: `limit` ≤ 50, query ≤ 100 characters, results cut to `limit` by the bundle even if the provider
  returns more, per-token rate limit on the client API, no wildcard expansion by the bundle (the query is passed as data).
  Search results are a UX hint: the write re-checks `canLog` (Q-TS-4).
- **Tenant isolation**: the context always carries the entry's scope; a subject is never checked against another
  tenant; exact collation on all three columns.
- **Audit**: client API audit rows record the subject type, not the id (no host business ids in TimeTrack's audit log).
- GHSA: not applicable (new feature, nothing shipped yet).

## 15. Events

| Event | Change |
|---|---|
| `TimerStopEvent`, `TimeEntryCreatedEvent` | `getSubject(): ?TimeSubjectRef`, `getActivityTypeKey(): ?string` |
| `TimerStartEvent` / `TimerStartedWithoutTaskEvent` | both gain `getSubject()` / `getActivityTypeKey()`: a task timer (with or without subject) dispatches `TIMER_START`, a subject-alone timer `TIMER_START_WITHOUT_TASK` (`TIMER_START` stays task-only for BC of its listeners) |
| new `TimerAllocatedEvent` (`nowo_time_track.timer.allocated`) | running timer's allocation changed (old / new) |
| new `TimeEntryUnallocatedEvent` (`nowo_time_track.time_entry.unallocated`) | a system stop stored an unallocated entry while `require_allocation` is on (§7.3) |
| `TimeEntryCorrected` path | the host's revision reads before / after from `TimeEntryCorrectionResult` (records carry both fields) |

Events are dispatched after the unit, as today.

## 16. Reuse of existing patterns and bundles

| Need | Reused | New |
|---|---|---|
| Entries without a task, scope choice | `allow_without_task`, `ScopeKeys::forEntryWithoutTask()`, `startWithoutTaskAt()` | allocation on that path |
| Port shape | `TimeTrackUserRef`, deny-by-default twins, `Ports` / `TimeTrackPortsPass` for the single activity port | first tagged multi-provider port (`ServiceLocator`) |
| Batched permission | `BatchTaskDirectoryInterface::filterTrackableFor()` idea | `describe()` |
| Masking | `TimeEntry::HIDDEN_TASK_TITLE` per-surface masking | `hidden` subject |
| Revisions | `TimeEntryCorrectionResult` before / after + `version()` | two more fields |
| Query | `TimeEntryCriteria` (keyed, exact, capped) | filters; `TimeEntryTotalsQueryInterface` |
| Exact keys | `TimeTrackExactKeyColumns` + MigrationsKit `check-key-collations` | three more columns |
| Client API | `ClientScope` (`timer:write`, `entries:write`, `tasks:read`), `ClientApiRateLimiter`, idempotency store, `ClientTaskPresenter` URL rule | two GET endpoints, `timer/allocate` |
| Billable | `TimeEntryBillableDefaultProviderInterface` | precedence with `ActivityType::$defaultBillable` (Q-TS-9) |
| Scope back-fill | `BackfillEntryScopeCommand` structure | read-only `check-allocation` command |
| Picker UI (host or a later bundle component) | UiKit combobox (Wiki anchors decision 2026-11-16) + UiKit dialog | — (no UI component in 2.1, Q-TS-20) |
| Subject search in a host | a host may back its provider with SearchKit providers | — |

Nothing new is introduced where an existing nowo bundle covers it; no new dependency.

## 17. Objections to the request as written

Status after Beacon's review and the owner's answers (2026-11-23) is given on each line.

- **O-1 [Accepted by Beacon]** **Separate `labels(ids)`, `urls` and `canRead(user, tenant, id)` invite N+1 and a label leak.** A page of 100
  entries would call `canRead` 100 times, and a `labels()` call that does not itself apply `canRead` returns names the
  viewer may not see if a caller forgets the check. Proposed: one batched `describe(context, refs)` that returns labels
  and URLs **only for readable subjects**; `canRead` is derived from it. `canLog` stays single (writes are single).
- **O-2 [Accepted by Beacon]** **"Refuse a stop with neither task nor subject" cannot apply to every stop.** User-deletion stops, the stop inside
  start-replacing / switch and idle resolution are triggered by the system; refusing them loses time (owner rule
  2026-11-06) or blocks an agent's start. Proposed: refuse only interactive stops, behind `require_allocation`
  (default false), store system stops unallocated and dispatch `TimeEntryUnallocatedEvent`. 1.36 hosts that rely on
  task-less stops keep working.
- **O-3 [Replaced by the revised Q-TS-1]** ~~Subject on task entries is ambiguous; proposed task XOR subject.~~
  Beacon objected: spec 250 customer time already sits on task entries ("this task, for customer X"). Decided: task
  alone, task + subject (only for types with `allowsTask()`), or subject alone; task access stays the gate of a task
  entry and `canLog(subject)` is checked in addition when the subject is set or changed (§3, §7.1).
- **O-4 [Accepted by Beacon]** **Two billable defaults.** `ActivityType::$defaultBillable` overlaps `TimeEntryBillableDefaultProviderInterface`.
  Proposed precedence: explicit flag > activity type default > host billable provider > null (Q-TS-9).
- **O-5 [Accepted by Beacon]** **"Query port: filter and group … with canRead".** The query port is rule-free by contract since 1.35 (B7); adding
  a per-row `canRead` there would make it provider-bound and N-bound. Proposed: filters and grouping stay rule-free; the
  bundle's own surfaces and the public `TimeSubjectLabelResolver` apply `canRead`.
- **O-6 [Accepted by Beacon]** **"Indexed with owner/tenant".** Owner-keyed reads are already served by `(user_id, scope_key, started_at)`;
  a second owner-leading index would only cost writes. Proposed: two tenant-leading indexes (§11.1).
- **O-7 [Settled by Q-TS-22]** **Back-fill as a bundle hook.** Argued in §12: the mapping is host data; a bundle port used once adds surface
  without adding safety that the read-only check command does not give.
- **O-8 [Documented]** **Per-tenant activity type validation on write requires a provider call per write.** Fine for interactive writes;
  `createManual()` in bulk (seeding, imports) should pass the same scope for a batch, and the provider should be cheap.
  Documented, no memo (worker mode).

## 18. Owner decisions (Q-TS, Owner 2026-11-23)

All answered by the owner on 2026-11-23: Q-TS-2..22 as recommended, Q-TS-1 revised after Beacon's objection.

- **Q-TS-1** May an entry carry both a task and a subject? **Decided (Owner 2026-11-23, revised after Beacon's
  objection):** Yes. Allowed: task alone, task + subject, subject alone; with `require_allocation: true` an entry needs
  one or the other. Each provider declares `allowsTask()` (true for customer-like types, false for internal
  categories); a task + subject write with a type that disallows tasks is refused (`allocation_conflict`). On a task
  entry with a subject, task access stays the gate and `canLog(subject)` is checked on any write that sets or changes
  the subject; `describe()` still filters the label by `canRead`. (Recommendation was task XOR subject.)
- **Q-TS-2** When is a stop without allocation refused? **Decided (Owner 2026-11-23, as recommended):** Only interactive stops / manual entries, only with
  `entries.require_allocation: true` (default false, Beacon sets it); system stops are stored unallocated and raise
  `TimeEntryUnallocatedEvent`.
- **Q-TS-3** Replace Beacon's `labels` / `urls` / `canRead` with one batched `describe()`? **Decided (Owner 2026-11-23, as recommended):** Yes (O-1); keep
  `canLog` and `search` as requested.
- **Q-TS-4** Does the bundle re-check `canLog` on each search result? **Decided (Owner 2026-11-23, as recommended):** No; search is a hint, every write re-checks.
- **Q-TS-5** Snapshot the subject label on the entry (like `task_title_snapshot`)? **Decided (Owner 2026-11-23, as recommended):** No; labels are live from the
  provider, a deleted subject shows "Unknown subject". Avoids storing host / third-party names in TimeTrack.
- **Q-TS-6** What does a viewer see for a subject they may not read? **Decided (Owner 2026-11-23, as recommended):** `{type, hidden: true, label: "Subject not
  visible"}` without the id; time and activity type stay visible.
- **Q-TS-7** Whose `canLog` when a host or manager writes for another person? **Decided (Owner 2026-11-23, as recommended):** The owner's (the person whose time
  it is); the host already authorises who may act for that owner.
- **Q-TS-8** Does a correction re-check `canLog` when the subject does not change? **Decided (Owner 2026-11-23, as recommended):** No, only when it changes (as
  tasks today).
- **Q-TS-9** Billable precedence? **Decided (Owner 2026-11-23, as recommended):** explicit > activity type default > `TimeEntryBillableDefaultProviderInterface` >
  null.
- **Q-TS-10** Retired activity types? **Decided (Owner 2026-11-23, as recommended):** Provider returns them with `active: false`; refused on new writes and on a
  correction that sets them, labelled on reads.
- **Q-TS-11** Is an activity type ever mandatory? **Decided (Owner 2026-11-23, as recommended):** Option `entries.require_activity_type` (default false), effective
  only with a provider.
- **Q-TS-12** New client scope (`subjects:read`) or reuse? **Decided (Owner 2026-11-23, as recommended):** Reuse `tasks:read` (search, activity types) and
  `timer:write` / `entries:write` (writes); no re-sign-in for existing desktop / extension tokens.
- **Q-TS-13** Ship a built-in `organisation` provider over the scope policy? **Decided (Owner 2026-11-23, as recommended):** Yes, opt-in, default off.
- **Q-TS-14** Timesheet snapshot: change `entries_hash`, add totals? **Decided (Owner 2026-11-23, as recommended):** Keep the hash; add `bySubject` and
  `byActivityType` totals.
- **Q-TS-15** May the back-fill touch locked / approved periods? **Decided (Owner 2026-11-23, as recommended):** Yes (classification only, hash unaffected); the
  check command reports the count; runtime corrections there stay refused.
- **Q-TS-16** Bulk operations when the host merges or deletes a subject (`reassignSubject(from, to)`, `detachSubject`)?
  **Decided (Owner 2026-11-23, as recommended):** Not in 2.1; plan for 2.2 as bounded, keyset `TimeEntryOperations` methods. In 2.1 a deleted subject renders as
  unknown.
- **Q-TS-17** GDPR export: labels or ids only? **Decided (Owner 2026-11-23, as recommended):** Ids and key always; labels resolved with the data subject as actor,
  masked when not readable.
- **Q-TS-18** Index set? **Decided (Owner 2026-11-23, as recommended):** The two tenant-leading indexes of §11.1, nothing owner-leading.
- **Q-TS-19** Add `POST timer/allocate` (change the subject while the timer runs)? **Decided (Owner 2026-11-23, as recommended):** Yes, under the owner's lock,
  last write wins, `TimerAllocatedEvent`.
- **Q-TS-20** Ship a picker / "allocate this time" Twig component in 2.1? **Decided (Owner 2026-11-23, as recommended):** No; 2.1 ships API + events, a UiKit
  combobox-based component follows in 2.2 if Beacon wants it.
- **Q-TS-21** Imported entries (`ImportedTimeEntryData`) with a subject? **Decided (Owner 2026-11-23, as recommended):** Out of scope for 2.1; imports stay
  task-based.
- **Q-TS-22** Back-fill mechanism? **Decided (Owner 2026-11-23, as recommended):** Host DBAL migration + documented snippet + column contract, plus the bundle's
  read-only `check-allocation` command (§12.2).

## 19. Delivery plan and tests

Single minor **2.1.0**, after 2.0.0 is tagged, in this order (each a PR):

1. VOs, ports, registry, compiler pass, configuration, `GET me` features (no behaviour change without providers).
2. Schema: entity / timer columns, exact-key declaration, `docs/sql/2.1.0/*`, check command.
3. Write paths (§7) + events; `TimeEntryRecord::version()`; correction patch.
4. Query filters, `TimeEntryTotalsQueryInterface`, label resolver, masking on bundle surfaces, CSV, GDPR.
5. Client API v0.4 (endpoints, errors, rate limit, idempotency hash), `docs/CLIENT-API.md`, `docs/UPGRADING.md`
   "To 2.1.0", `docs/CONCURRENCY.md` rows of §13.

Tests (in addition to the house gates):

- zero providers: every new field refused, columns NULL, `GET me` unchanged except empty features;
- unknown type / unknown id / no access give the same answer; provider exception is denied, not 500;
- half-pair refused on every path; task + subject accepted only for `allowsTask()` types (refused otherwise, also
  at stop, allocate and correct); task access checked before `canLog` on task + subject writes; `require_allocation` refuses interactive stops only, never system stops;
- concurrency (MySQL 8.4, MariaDB 11.4, PostgreSQL 16, SQLite): double stop with different subjects, refused stop vs
  allocated stop, stop vs allocate, correct vs correct on allocation, correct vs back-fill `UPDATE`;
- version token unchanged for unallocated entries, changed by any allocation change;
- one `describe()` per type per page (spy provider), ≤ 1000 refs; grouping query count = 1, range > 366 days refused;
- labels with `<script>`, bidi overrides, `=cmd` in CSV, `javascript:` / `http:` URLs;
- erasure keeps the three columns and the time; GDPR export shows them;
- worker mode: two viewers in one kernel, no carry-over.
