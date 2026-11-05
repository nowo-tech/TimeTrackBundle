# Working time, hours balance and timesheets (1.13)

> **Not legal advice.** This page explains what the bundle computes and stores. Whether a host meets the Spanish
> working-time record (art. 34.9 ET, RD-ley 8/2019), the overtime rules (art. 35 ET) or GDPR / LOPDGDD is decided with
> the host's legal counsel, collective agreement (*convenio*) and worker representatives.

## Table of contents

- [What 1.13 adds](#what-113-adds)
- [One legal register](#one-legal-register)
- [Tenant scopes](#tenant-scopes)
- [Hours balance](#hours-balance)
- [Overtime](#overtime)
- [Balance ledger](#balance-ledger)
- [Timesheets](#timesheets)
- [Period lock](#period-lock)
- [Audit chains](#audit-chains)
- [Components (no template overrides)](#components-no-template-overrides)
- [Commands](#commands)
- [Retention and erasure](#retention-and-erasure)
- [Host checklist](#host-checklist)

## What 1.13 adds

| Feature | Switch | Default |
| --- | --- | --- |
| Hours balance, overtime, ledger, `GET balance` | `working_time.enabled` | off |
| Timesheets (submit / approve / lock), `GET timesheets` | `timesheets.enabled` | off |
| Workday client API (R-GDPR §8) | `client_api.workday_provider` | none |
| Calendar suggestions, `POST entries` | 1.14 | — |
| Budget versus actual | 1.15 | — |

The new tables are always mapped (one migration for everybody, see [UPGRADING](UPGRADING.md#to-1130)); they stay empty
until a feature is enabled.

## One legal register

When the host has a working-time register (Beacon spec 198), it is the only legal record. The bundle reads it through
`WorkingTime\WorkedTimeSourceInterface` (`isLegalRecord(): true`) and never writes it. Without one, the default
`TaskTimeWorkedTimeSource` sums **live task time** of the scope (imports excluded, overlapping timers merged): every
balance payload and component then carries `legalRecord: false` / `source: task_time`, and UIs MUST show *"Task time, not
a working-time record"* (the components do). Task time alone does not record the start and end of the working day.

## Tenant scopes

Every entry, timesheet and ledger line carries a host `scope_key` (Beacon `org-<uuid>`) from
`Timesheet\TimesheetScopeProviderInterface` (`timesheets.scope_provider`, or `scope_mode: single` = one scope `''`).
A user in two organisations has two timesheets per week. Approvers come from
`Timesheet\TimesheetApproverProviderInterface` **per scope**; nothing crosses scopes, and a scope is never taken from a
request without checking `scopesFor($user)` contains it. Entries written before 1.13 get their scope from
`nowo:time-track:entries:backfill-scope`; entries the provider cannot place belong to no timesheet (*unassigned*).

## Hours balance

```
expected(d)  ← WorkingCalendarProviderInterface   (schedule − holidays − leave)
worked(d)    ← WorkedTimeSourceInterface          (register, or live task time)
diff(d)      = worked(d) − expected(d)             closed days only (before today in the user's zone)
balance(t)   = Σ diff(d) + Σ ledger seconds (opening, adjustment, overtime_paid, their reversals)
```

- Calendar: `WorkingTime\WorkingCalendarProviderInterface::days()` returns one `CalendarDay` per local date
  (`working`, `rest`, `holiday`, `vacation`, `leave`, `absence`, `compensatory`, `none`; kinds only, never causes;
  `label` for public holidays only). The default `DerivedWorkingCalendar` derives it from the 1.12 working hours and days
  off. Every day is validated and fails closed to `none` (warning log with the reason only).
- `WorkingTimeBalanceService::report($user, $scope, $from, $to)` (≤ 366 days; the client API allows 92) returns a
  `BalanceReport`: days, ISO weeks, totals, `balanceSeconds`, ledger lines, `source`, `legalRecord`, policy, annual
  overtime counter. Today and later days are shown with partial worked time and the `open_day` flag but never counted.
- The running balance continues from the **last locked timesheet** (its approved snapshot's `balanceSeconds`), else
  from `working_time.balance.start_date`, else from 366 days before the end of the range. Set a start date for a running
  balance across years when periods are not approved.
- Bounds: dates between 2000-01-01 and 2099-12-31, the end at most 366 days after today (`InvalidArgumentException`,
  400 on the API), and at most 3660 days computed from the running-balance start to the end of the range: beyond that the
  report is null and `balance_span_exceeded` is logged. Approving (locking) periods keeps the span short.

## Overtime

`OvertimePolicyInterface` (host, per *convenio*) or the default `ConfigOvertimePolicy`:

| Rule | Config | Default |
| --- | --- | --- |
| Daily | `overtime.daily_mode`: `expected` (above the day's expected time), `ordinary_max` (above the agreement's cap or `ordinary_max_seconds`, 9 h), `none` (flexible hours: weekly rule only) | `expected` |
| Weekly | `overtime.weekly_mode`: `expected` or `legal_max` (`weekly_max_seconds`, 40 h), minus the daily part | `expected` |
| Annual | ratified − compensated within 4 months (FIFO), flags `overtime_cap_near` (80 %) / `overtime_cap_exceeded` (100 % of `annual_cap_seconds`, 80 h) | — |
| Flags | `long_day` (> 12 h), `rest_short` (< 12 h since the previous day), `break_missing` (register sources: > 6 h with < 15 min break) | informational |

The bundle flags and totals; it never pays, converts or blocks work. The policy id and version (the default's version
changes with every configured value) are stored in approved snapshots.

## Balance ledger

`WorkingTime\BalanceLedger::add($actor, $owner, $scope, LedgerKind, $seconds, $effectiveDate, $reason, $reversesId)`:
append-only lines chained per owner and scope. `opening` (once unless reversed), `adjustment`, `overtime_paid`
(negative), `overtime_compensated` (positive; Beacon writes it when compensatory leave is approved) and `reversal`
(the opposite of the reversed line) are for approvers of the owner in that scope — never the owner. Reasons are required
for opening, adjustment, overtime paid and reversal (≤ 500 characters). `overtime_ratified` is written only by the
approval (`working_time.overtime.ratify_on_approval`) and reversed by a reopen. Lines whose effective date is in a locked
period are refused. Errors: `LedgerRefusedException::errorCode()` (`forbidden`, `reason_required`, `invalid_seconds`,
`invalid_date`, `opening_exists`, `not_found`, `already_reversed`, `not_reversible`, `ratify_only`, `invalid_request`,
`conflict` — another line was appended to the same chain at the same time; retry). The bundle does not rate-limit
ledger writes in 1.13: put a per-actor `RateLimiter` on your ledger route.

## Timesheets

`Timesheet\TimesheetService` (one per user, scope and ISO week — or month with `timesheets.period: month`):

```
open ─submit (owner)─▶ submitted ─approve (approver)─▶ approved [locked]
 ▲ ◀──withdraw (owner)──┘   └─reject (approver, comment)─▶ rejected ─submit─▶ submitted
 └──── reopen (approver / reopen_roles, reason) ◀── approved / auto-locked
open ─auto-lock (system)─▶ open [locked: auto]
```

- Submit: owner only (no submit on behalf); refused with `timer_running` (a timer of that scope started in the period),
  `not_started`, `not_ended` (`submit_requires_period_end: true`). Stores the snapshot and the entries hash.
- Approve: an approver of the owner in the scope; **nobody approves their own timesheet, admin roles included**. The
  entries hash is recomputed: a change since submit answers `stale`; two simultaneous decisions: one wins, the other gets
  `conflict`. Approving hard-locks the period.
- Reject: comment of 10–1000 characters, shown to the owner until the next submit. Approvers never edit entries.
- Reopen: approver or `timesheets.reopen_roles` holder, reason required; keeps every audit row and reverses the ratified
  overtime.
- Other users' timesheets answer `not_found`, exactly like a non-existent one (no existence oracle).
- Rows: the owner's row is created on the first view of the current or previous period, on submit and on auto-lock;
  viewing any other period answers an unstored open draft (`Timesheet::isDraft()`, `id: null` on the API) and
  `TimesheetService::peek()` (used by `GET me`) never writes. `period()` accepts keys up to the next period.
- A timer whose task has no scope (the scope provider returns null) does not block the submit of a period; its stop
  later becomes a late entry (`TIMESHEET_LATE_ENTRY`). Scope providers should place every trackable task.
- Events (ids only, after commit): `TIMESHEET_SUBMITTED`, `…_WITHDRAWN`, `…_APPROVED`, `…_REJECTED`, `…_REOPENED`,
  `…_AUTO_LOCKED` (`TimesheetTransitionEvent`), deny-only `TIMESHEET_ACCESS_CHECK` (fired on every non-owner view, for
  the "who viewed whose timesheet" audit), `TIMESHEET_LATE_ENTRY`, `PERIOD_LOCK_REFUSED`, `BALANCE_LEDGER_LINE_ADDED`.

## Period lock

`Timesheet\PeriodLockGuard::assertWritable($user, ?$scopeKey, $instant, WriteKind)` refuses time in a locked
(`PeriodLockedException`, 409 `period_locked` + `lockedThrough`) or submitted (`PeriodSubmittedException`, 409
`period_submitted`) period. A null scope is checked against every scope of the user. Enforced on every bundle write
path: timer start (backdated or now), switch (before the running timer stops), idle discard / split / reassign, the
import writer (outcome `locked`, never written), imported-entry removals, `TeamAccessGuard::canEditEntry()` /
`canDeleteImportedEntry()` (after the listener: a grant is ignored), the ledger. A stop is always allowed; an entry
written into a period locked while its timer ran fires `TIMESHEET_LATE_ENTRY`. Retention purges and account erasure
(`ImportedTimeEntryWriter::removeForUser()`) are never blocked. **Host code that writes entries must call the guard.**

## Audit chains

Each timesheet transition appends one row to `time_track_timesheet_events` (`seq` 1, 2, …; `hash = sha256(prev_hash ‖
canonical_json(row))`; the timesheet keeps the head hash). Ledger lines are chained per owner and scope. The repositories
have no update or delete method (only the retention purge deletes). `nowo:time-track:timesheets:verify` recomputes every
chain and names the first broken link. A unique `(owner_ref, scope_key, prev_hash)` index stops concurrent appends from
forking a ledger chain. After a retention purge a ledger chain restarts at the first line kept (marked `purged_before`;
not hashed), so `verify` keeps passing; a chain with its oldest lines deleted by hand, or with two starts, is reported.
The ledger has no stored head: the newest lines deleted by hand are not detected. Hosts MAY add database triggers that refuse `UPDATE` / `DELETE` on both tables for
every user but the purge user; the bundle does not create them.

## Components (no template overrides)

Hosts compose their pages (*My timesheet*, *Approvals*, *Balance*) from configurable components; the bundle's
templates are never overridden:

```twig
{{ nowo_time_track_component('TimeTrack:TimesheetWeekGrid', {timesheet: timesheet, viewer: app.user, options: {
    action_urls: {submit: path('app_timesheet_action', {action: 'submit'}), approve: path('app_timesheet_action', {action: 'approve'}), reject: path('app_timesheet_action', {action: 'reject'}), reopen: path('app_timesheet_action', {action: 'reopen'})},
    density: 'compact', show_expected: true, labels: {task: 'my_app.timesheet.task'},
}}) }}
{{ nowo_time_track_component('TimeTrack:BalanceCard', {report: report, options: {range: 'month', show_overtime: true}}) }}
{{ nowo_time_track_component('TimeTrack:LedgerTable', {lines: lines, viewer: app.user, owner_id: ownerId, scope_key: scope, options: {action_urls: {add_adjustment: path('app_ledger_add'), reversal: path('app_ledger_reverse')}}}) }}
```

| Component | Service | Options |
| --- | --- | --- |
| `TimeTrack:TimesheetWeekGrid` | `Ui\TimesheetViewBuilder::weekGrid()` | `labels`, `actions` (submit, withdraw, approve, reject, reopen), `action_urls`, `density` (`comfortable` / `compact`), `show_expected`, `show_diff`, `read_only` |
| `TimeTrack:BalanceCard` | `::balanceCard()` | `labels`, `range` (week / month / year / custom), `show_overtime`, `legal_badge` |
| `TimeTrack:LedgerTable` | `::ledgerTable()` | `labels`, `columns`, `actions` (add_adjustment, reversal), `action_urls`, `density` |

An action appears only when the guard allows it for the viewer **and** the host gave its URL. Reject, reopen,
adjustment and reversal open a UiKit 2.29 dialog with the anatomy (header, scrolling body with the form, footer with
Cancel and the primary submit). Approvers see task titles masked when they cannot see the task, and notes only with
`timesheets.approver_sees_notes: true`; imported entries of a task the owner can no longer see are masked for everybody,
the owner included. The grid and the ledger table refuse (`TimesheetException('not_found')`) a viewer who may not see the
owner's data in that scope, and the ledger table drops lines of another owner or scope — still, load the data through
`TimesheetService::find()` / `view()` and `BalanceLedger::linesOf()`. In the host controller behind each URL:

```php
$timesheet = $formHandler->handleDecision($request, $this->getUser(), $action); // Timesheet\TimesheetFormHandler
$line      = $formHandler->handleLedger($request, $this->getUser(), $owner, $scopeKey);
```

The handler checks the form and the CSRF token itself (ids `nowo_time_track_timesheet` / `nowo_time_track_ledger`;
every post is refused without a CSRF token manager, whatever `framework.form.csrf_protection` says) and runs the action
through the service, which applies the guard; owner actions use the posted `scope` + `period` of the actor's own period; catch `TimesheetException` / `LedgerRefusedException` and show the translated
`timesheet.error.<code>`.

## Commands

| Command | When |
| --- | --- |
| `nowo:time-track:entries:backfill-scope [--batch=500] [--dry-run]` | once after enabling timesheets or working time (entries of locked or submitted periods are left without a scope and counted) |
| `nowo:time-track:timesheets:auto-lock [--lookback-days=62]` | daily, with `timesheets.auto_lock_after_days` |
| `nowo:time-track:timesheets:purge [--batch=500]` | daily or monthly (retention) |
| `nowo:time-track:timesheets:verify [--scope=] [--user=]` | on demand / monitoring |

## Retention and erasure

Timesheets, their audit rows and ledger lines are kept `timesheets.retention_years` (4–10, default 4) after the end of
the period's month, then deleted by `timesheets:purge` — the only delete path. The purge removes, per ledger chain, the
chain-order prefix of lines effective before the cut-off (a line is never removed from the middle of a chain) and keeps
a timesheet as long as a kept ledger line points to it. On account erasure the owner FK becomes
`NULL` (`ON DELETE SET NULL`) and `owner_ref` / `actor_ref` keep the identity until the retention ends (art. 17.3.b
GDPR). Entries follow the host's entry retention; the lock never blocks it.

Personal-data export (art. 15 / 20): `Timesheet\TimesheetPersonalDataExporter::export($ownerRef)` returns
`{timesheets: [{id, scopeKey, period, status, lockedAt, lockReason, submittedAt, decidedAt, decisionComment, totals,
createdAt, events: [{action, fromStatus, toStatus, actorRole, client, occurredAt, comment}]}], ledger: [line…]}` — by
`owner_ref`, across scopes, without other people's ids. Add it to your exporter next to the time entries.

## Host checklist

1. Run the migration and `entries:backfill-scope`.
2. Implement the scope and approver providers (Beacon: `org-<uuid>`, `working_time.manage_team`, fallback
   `time_track.timesheet.approve`), and optionally the calendar, worked-time source (register), overtime policy and
   workday provider.
3. Wire the components into your pages and the form handler into your controllers; permissions are yours (deny by
   default, 404 for others' resources). Keep CSRF on (the handler checks the token itself) and rate-limit the ledger and
   decision routes per actor. Grant `nowo_time_track.task_effort.access_check` to users who manage a task.
4. Add `TimesheetPersonalDataExporter` to your personal-data export.
5. Schedule `auto-lock` (if used), `purge`; audit from the events; notify only inside the recipient's working hours.
6. Update the worker notice, RAT and DPIA (timesheets: purpose, who approves, what approvers see, retention; balance:
   source and whether it is the legal register).
