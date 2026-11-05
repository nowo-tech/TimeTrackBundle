# Feature Specification: Calendar suggestions, manual entries and meetings API (TimeTrack 1.14.0)

**Feature Branches**: `feat/1.14.0-b1-calendar-model`, `feat/1.14.0-b2-entries-suggestions-api`,
`feat/1.14.0-b3-meetings-api` (stacked, Q-O15)  
**Created**: 2026-11-05  
**Status**: Implemented (1.14.0, unreleased; REQ-SEC-004 audit pending, Q-O10)  
**Input**: `.uic-work/timetrack-next/CONTRACT.md` v1.1 — §3.5–§3.9, §6, §8–§13 (1.14 parts), §22; owner Q-O5..Q-O8,
Q-O12, Q-O14..Q-O22; Beacon Q-B8, Q-B9, Q-B13..Q-B24; Beacon spec 210 *Calendar meetings and reminders*.

**Related docs**: [`docs/CLIENT-API.md`](../../docs/CLIENT-API.md#changes-in-114-calendar-suggestions-manual-entries),
[`docs/MEETINGS.md`](../../docs/MEETINGS.md), [`docs/USAGE.md`](../../docs/USAGE.md#calendar-suggestions-114),
[`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md#calendar-suggestions-114),
[`docs/SECURITY.md`](../../docs/SECURITY.md#calendar-suggestions-and-meetings-114),
[`docs/UPGRADING.md`](../../docs/UPGRADING.md#to-1140)  
**Code inventory (traceability)**: [`code-inventory.md`](code-inventory.md)

## User scenarios

1. **Dailies become one suggestion group** (P1). A user connects Google in Beacon (consent recorded, token in Beacon).
   The sync (inside working hours) reads the last 14 days of the primary calendar; five `#ab12cd34 Daily` occurrences
   of one series in one ISO week become one group; accepting it creates five `calendar` entries. One in a locked week is
   reported `period_locked`, the rest are created (AC-16).
2. **Keyless events through a rule** (P2). The user adds the literal rule `stand-up → task`; matching events are
   suggested and grouped per week; nothing else of an unmatched event is kept (only a count).
3. **Disconnect deletes everything** (P1). Revoke asks the host to delete the token and revoke the grant, then deletes
   the connection, its suggestions and the user's rules in that scope (AC-17).
4. **A past entry by hand** (P2). `POST entries` creates a `manual` entry (≤ 24 h, ≤ 31 days old, no overlap, lock
   aware, idempotent).
5. **Desktop reminders** (P1). TimeTrack Desktop polls `GET me/meetings` (scope `meetings:read`) and reminds the user;
   private meetings show as busy with the join link; nothing about the meeting is stored or logged by the bundle.

## Requirements (traceability to the contract)

| Contract | Requirement | Where |
| --- | --- | --- |
| §3.5–§3.8 | Three always-mapped tables, reference DDL per platform | `Entity/Calendar*`, `docs/sql/1.14.0/` |
| §6.1, Q-B9 | Host tokens per sync, never stored; revoke via host | `Calendar/CalendarCredential*`, `CalendarConnectionService` |
| §6.2, Q-O5 | Google / Graph read-only adapters, field masks, primary calendar, bounded HTTP | `Calendar/*EventSource`, `CalendarHttpClient` |
| §6.3, Q-O6, Q-O8 | Drop rules, host keys, literal rules, trackable check | `Calendar/CalendarEventMatcher`, `TaskKeyResolverInterface` |
| §6.4–§6.5 | Grouping, accept per occurrence, dismiss | `CalendarSyncService::groupKey()`, `CalendarSuggestionService` |
| §6.6 | `resolve-keys`, `POST entries` | `TimeTrackCalendarApiController`, `ManualEntryService` |
| §6.7, AC-19 | Sync job inside working hours, purge | `CalendarSyncService::syncDue()`, `CalendarSyncCommand` |
| §9.1 | Events | `Event/CalendarConnectionChangedEvent`, `CalendarSyncFinishedEvent`, `CalendarSuggestionDecidedEvent`, `TimeEntryCreatedEvent` |
| §10.1, §10.3, Q-O14 | Routes, scopes (explicit grant), `GET me` flags | `TimeTrackCalendarApiController`, `ClientScope`, `TimeTrackClientApiController::me()` |
| §13 | `calendar.*`, `manual_entries.*` | `DependencyInjection/Configuration` |
| §22 | Meetings API | `Meeting/*`, `TimeTrackMeetingsApiController`, `docs/schemas/meetings.v1.json` |
| Q-B22 | *Track the meeting* via `timer/switch` + `metadata.origin = meeting` | `TimeTrackClientApiController::timerSwitch()`, `TimerService::switchTo()` |

## Success criteria

- AC-14..AC-20 and AC-22..AC-30 covered by tests (hand-written fake payloads only; marker scans over rows, logs and
  events); 100 % statement coverage; PHPStan; functional suite on SQLite, MySQL 8 and PostgreSQL 16.
