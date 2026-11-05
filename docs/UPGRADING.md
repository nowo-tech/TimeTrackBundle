# Upgrading

## Table of contents

- [Unreleased](#unreleased)
  - [To 1.14.0](#to-1140)
- [To 1.13.0](#to-1130)
- [To 1.12.0](#to-1120)
- [To 1.11.0](#to-1110)
- [To 1.10.3](#to-1103)
- [To 1.10.2](#to-1102)
- [To 1.10.1](#to-1101)
- [To 1.10.0](#to-1100)
- [To 1.9.2](#to-192)
- [To 1.9.1](#to-191)
- [To 1.9.0](#to-190)
  - [To 1.9.0](#to-190-1)
- [To 1.8.0](#to-180)
- [To 1.7.0](#to-170)
- [To 1.6.0](#to-160)
- [To 1.5.2](#to-152)
- [From 1.5.0 to 1.5.1](#from-150-to-151)
- [From 1.4.0 to 1.5.0](#from-140-to-150)
- [From 1.3.7 to 1.4.0](#from-137-to-140)
- [From 1.3.6 to 1.3.7](#from-136-to-137)
- [From 1.3.5 to 1.3.6](#from-135-to-136)
- [From 1.3.4 to 1.3.5](#from-134-to-135)
- [From 1.3.2 to 1.3.3](#from-132-to-133)
- [1.2.0 (2026-08-03)](#120-2026-08-03)
  - [Install / update](#install--update)
  - [Behaviour change (manage roles)](#behaviour-change-manage-roles)
  - [New optional config](#new-optional-config)
  - [Breaking changes](#breaking-changes)
- [1.1.0 (2026-07-30)](#110-2026-07-30)
  - [Layout integration (REQ-UI-001)](#layout-integration-req-ui-001)
  - [CSS framework (REQ-UI-001)](#css-framework-req-ui-001)
- [1.0.x patch releases](#10x-patch-releases)
  - [1.0.6 (2026-07-29)](#106-2026-07-29)
  - [1.0.5 (2026-07-16)](#105-2026-07-16)
  - [1.0.4 (2026-07-13)](#104-2026-07-13)
  - [1.0.3 (2026-07-08)](#103-2026-07-08)
  - [1.0.2 (2026-07-07)](#102-2026-07-07)
  - [1.0.1 (2026-07-06)](#101-2026-07-06)
- [1.0.0 (2026-07-06)](#100-2026-07-06)
  - [Requirements](#requirements)
  - [Installation checklist](#installation-checklist)
  - [Client apps (repository only)](#client-apps-repository-only)
- [To 1.3.5](#to-135)
- [To 1.3.4](#to-134)
- [To 1.3.2](#to-132)
- [To 1.3.1](#to-131)
- [To 1.3.0](#to-130)
  - [Twig Extra Bundle (REQ-TWIG-004)](#twig-extra-bundle-req-twig-004)
  - [Twig-CS-Fixer (maintainers)](#twig-cs-fixer-maintainers)

## Unreleased

### To 1.14.0

From **1.13.0** (contract `timetrack-next` v1.1: calendar suggestions §6, `POST entries` and `resolve-keys` §6.6 / §10.3,
meetings API §22):

```bash
composer update nowo-tech/time-track-bundle
```

**Schema migration required (every host).** 1.14 adds three tables that are always mapped, whatever the configuration
(empty until calendar suggestions are enabled): `time_track_calendar_connections`, `time_track_calendar_suggestions` and
`time_track_calendar_rules`. Run `doctrine:migrations:diff` (the diff is exactly the statements below for the default
prefix) or apply the reference SQL of your platform. Replace `users` and `INT` with your user table and its primary-key
type (Beacon: `INT`). No data is rewritten; the files are also in `docs/sql/1.14.0/`, and the demo ships the MySQL one as
`demo/symfony8/migrations/Version20261107000000.php`. The meetings API adds no table.

<details><summary>MySQL 8 / MariaDB (docs/sql/1.14.0/mysql-up.sql)</summary>

```sql
-- TimeTrackBundle 1.14.0, MySQL 8 / MariaDB 10.6+ (default table prefix; replace `users` and INT with your user table and PK type). Calendar suggestions (always mapped); the meetings API adds no table.
CREATE TABLE time_track_calendar_connections (id VARCHAR(36) NOT NULL, status VARCHAR(16) NOT NULL, consented_at DATETIME NOT NULL, last_sync_at DATETIME DEFAULT NULL, last_error_code VARCHAR(32) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, scope_key VARCHAR(64) NOT NULL, provider VARCHAR(16) NOT NULL, mode VARCHAR(8) NOT NULL, credential_ref VARCHAR(128) DEFAULT NULL, consent_version VARCHAR(16) NOT NULL, user_id INT NOT NULL, UNIQUE INDEX uniq_time_track_calendar_connections (user_id, scope_key, provider), INDEX IDX_12CE44F6A76ED395 (user_id), PRIMARY KEY (id));
CREATE TABLE time_track_calendar_suggestions (id VARCHAR(36) NOT NULL, status VARCHAR(12) NOT NULL, created_at DATETIME NOT NULL, starts_at DATETIME NOT NULL, ends_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, event_ref CHAR(64) NOT NULL, series_ref CHAR(64) DEFAULT NULL, group_key VARCHAR(48) DEFAULT NULL, task_id VARCHAR(36) NOT NULL, match_kind VARCHAR(8) NOT NULL, entry_id VARCHAR(36) DEFAULT NULL, connection_id VARCHAR(36) NOT NULL, user_id INT NOT NULL, INDEX idx_time_track_calendar_suggestions_user (user_id, status, starts_at), UNIQUE INDEX uniq_time_track_calendar_suggestions_ref (connection_id, event_ref), INDEX IDX_3C8E3EF7BA364942 (entry_id), INDEX IDX_3C8E3EF7DD03F01 (connection_id), INDEX IDX_3C8E3EF7A76ED395 (user_id), PRIMARY KEY (id));
CREATE TABLE time_track_calendar_rules (id VARCHAR(36) NOT NULL, created_at DATETIME NOT NULL, scope_key VARCHAR(64) NOT NULL, pattern VARCHAR(120) NOT NULL, task_id VARCHAR(36) NOT NULL, group_matches TINYINT DEFAULT 1 NOT NULL, user_id INT NOT NULL, INDEX idx_time_track_calendar_rules_user (user_id, scope_key), INDEX IDX_84F867CAA76ED395 (user_id), PRIMARY KEY (id));
ALTER TABLE time_track_calendar_connections ADD CONSTRAINT FK_12CE44F6A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE;
ALTER TABLE time_track_calendar_suggestions ADD CONSTRAINT FK_3C8E3EF7BA364942 FOREIGN KEY (entry_id) REFERENCES time_track_entries (id) ON DELETE SET NULL;
ALTER TABLE time_track_calendar_suggestions ADD CONSTRAINT FK_3C8E3EF7DD03F01 FOREIGN KEY (connection_id) REFERENCES time_track_calendar_connections (id) ON DELETE CASCADE;
ALTER TABLE time_track_calendar_suggestions ADD CONSTRAINT FK_3C8E3EF7A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE;
ALTER TABLE time_track_calendar_rules ADD CONSTRAINT FK_84F867CAA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE;
```

Rollback:

```sql
-- Rollback to 1.13 (MySQL / MariaDB): drops the 1.14 calendar tables; 1.13 ignores them otherwise.
ALTER TABLE time_track_calendar_suggestions DROP FOREIGN KEY FK_3C8E3EF7BA364942;
ALTER TABLE time_track_calendar_suggestions DROP FOREIGN KEY FK_3C8E3EF7DD03F01;
ALTER TABLE time_track_calendar_suggestions DROP FOREIGN KEY FK_3C8E3EF7A76ED395;
ALTER TABLE time_track_calendar_rules DROP FOREIGN KEY FK_84F867CAA76ED395;
ALTER TABLE time_track_calendar_connections DROP FOREIGN KEY FK_12CE44F6A76ED395;
DROP TABLE time_track_calendar_suggestions;
DROP TABLE time_track_calendar_rules;
DROP TABLE time_track_calendar_connections;
```

</details>

<details><summary>PostgreSQL 13+ (docs/sql/1.14.0/postgresql-up.sql)</summary>

```sql
-- TimeTrackBundle 1.14.0, PostgreSQL 13+ (default table prefix; replace `users` and INT with your user table and PK type). Calendar suggestions (always mapped); the meetings API adds no table.
CREATE TABLE time_track_calendar_connections (id VARCHAR(36) NOT NULL, status VARCHAR(16) NOT NULL, consented_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_sync_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_error_code VARCHAR(32) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, scope_key VARCHAR(64) NOT NULL, provider VARCHAR(16) NOT NULL, mode VARCHAR(8) NOT NULL, credential_ref VARCHAR(128) DEFAULT NULL, consent_version VARCHAR(16) NOT NULL, user_id INT NOT NULL, PRIMARY KEY (id));
CREATE UNIQUE INDEX uniq_time_track_calendar_connections ON time_track_calendar_connections (user_id, scope_key, provider);
CREATE INDEX IDX_12CE44F6A76ED395 ON time_track_calendar_connections (user_id);
CREATE TABLE time_track_calendar_suggestions (id VARCHAR(36) NOT NULL, status VARCHAR(12) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, starts_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ends_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, event_ref CHAR(64) NOT NULL, series_ref CHAR(64) DEFAULT NULL, group_key VARCHAR(48) DEFAULT NULL, task_id VARCHAR(36) NOT NULL, match_kind VARCHAR(8) NOT NULL, entry_id VARCHAR(36) DEFAULT NULL, connection_id VARCHAR(36) NOT NULL, user_id INT NOT NULL, PRIMARY KEY (id));
CREATE INDEX idx_time_track_calendar_suggestions_user ON time_track_calendar_suggestions (user_id, status, starts_at);
CREATE UNIQUE INDEX uniq_time_track_calendar_suggestions_ref ON time_track_calendar_suggestions (connection_id, event_ref);
CREATE INDEX IDX_3C8E3EF7BA364942 ON time_track_calendar_suggestions (entry_id);
CREATE INDEX IDX_3C8E3EF7DD03F01 ON time_track_calendar_suggestions (connection_id);
CREATE INDEX IDX_3C8E3EF7A76ED395 ON time_track_calendar_suggestions (user_id);
CREATE TABLE time_track_calendar_rules (id VARCHAR(36) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, scope_key VARCHAR(64) NOT NULL, pattern VARCHAR(120) NOT NULL, task_id VARCHAR(36) NOT NULL, group_matches BOOLEAN DEFAULT true NOT NULL, user_id INT NOT NULL, PRIMARY KEY (id));
CREATE INDEX idx_time_track_calendar_rules_user ON time_track_calendar_rules (user_id, scope_key);
CREATE INDEX IDX_84F867CAA76ED395 ON time_track_calendar_rules (user_id);
ALTER TABLE time_track_calendar_connections ADD CONSTRAINT FK_12CE44F6A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE;
ALTER TABLE time_track_calendar_suggestions ADD CONSTRAINT FK_3C8E3EF7BA364942 FOREIGN KEY (entry_id) REFERENCES time_track_entries (id) ON DELETE SET NULL NOT DEFERRABLE;
ALTER TABLE time_track_calendar_suggestions ADD CONSTRAINT FK_3C8E3EF7DD03F01 FOREIGN KEY (connection_id) REFERENCES time_track_calendar_connections (id) ON DELETE CASCADE NOT DEFERRABLE;
ALTER TABLE time_track_calendar_suggestions ADD CONSTRAINT FK_3C8E3EF7A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE;
ALTER TABLE time_track_calendar_rules ADD CONSTRAINT FK_84F867CAA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE;
```

Rollback:

```sql
-- Rollback to 1.13 (PostgreSQL): drops the 1.14 calendar tables; 1.13 ignores them otherwise.
ALTER TABLE time_track_calendar_suggestions DROP CONSTRAINT FK_3C8E3EF7BA364942;
ALTER TABLE time_track_calendar_suggestions DROP CONSTRAINT FK_3C8E3EF7DD03F01;
ALTER TABLE time_track_calendar_suggestions DROP CONSTRAINT FK_3C8E3EF7A76ED395;
ALTER TABLE time_track_calendar_rules DROP CONSTRAINT FK_84F867CAA76ED395;
ALTER TABLE time_track_calendar_connections DROP CONSTRAINT FK_12CE44F6A76ED395;
DROP TABLE time_track_calendar_suggestions;
DROP TABLE time_track_calendar_rules;
DROP TABLE time_track_calendar_connections;
```

</details>

<details><summary>SQLite 3.35+ (docs/sql/1.14.0/sqlite-up.sql)</summary>

```sql
-- TimeTrackBundle 1.14.0, SQLite 3.35+ (default table prefix; replace `users` with your user table). Foreign keys are declared inline. Calendar suggestions (always mapped); the meetings API adds no table.
CREATE TABLE time_track_calendar_connections (id VARCHAR(36) NOT NULL, status VARCHAR(16) NOT NULL, consented_at DATETIME NOT NULL, last_sync_at DATETIME DEFAULT NULL, last_error_code VARCHAR(32) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, scope_key VARCHAR(64) NOT NULL, provider VARCHAR(16) NOT NULL, mode VARCHAR(8) NOT NULL, credential_ref VARCHAR(128) DEFAULT NULL, consent_version VARCHAR(16) NOT NULL, user_id INTEGER NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_12CE44F6A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE);
CREATE UNIQUE INDEX uniq_time_track_calendar_connections ON time_track_calendar_connections (user_id, scope_key, provider);
CREATE INDEX IDX_12CE44F6A76ED395 ON time_track_calendar_connections (user_id);
CREATE TABLE time_track_calendar_suggestions (id VARCHAR(36) NOT NULL, status VARCHAR(12) NOT NULL, created_at DATETIME NOT NULL, starts_at DATETIME NOT NULL, ends_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, event_ref CHAR(64) NOT NULL, series_ref CHAR(64) DEFAULT NULL, group_key VARCHAR(48) DEFAULT NULL, task_id VARCHAR(36) NOT NULL, match_kind VARCHAR(8) NOT NULL, entry_id VARCHAR(36) DEFAULT NULL, connection_id VARCHAR(36) NOT NULL, user_id INTEGER NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_3C8E3EF7BA364942 FOREIGN KEY (entry_id) REFERENCES time_track_entries (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3C8E3EF7DD03F01 FOREIGN KEY (connection_id) REFERENCES time_track_calendar_connections (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3C8E3EF7A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE);
CREATE INDEX idx_time_track_calendar_suggestions_user ON time_track_calendar_suggestions (user_id, status, starts_at);
CREATE UNIQUE INDEX uniq_time_track_calendar_suggestions_ref ON time_track_calendar_suggestions (connection_id, event_ref);
CREATE INDEX IDX_3C8E3EF7BA364942 ON time_track_calendar_suggestions (entry_id);
CREATE INDEX IDX_3C8E3EF7DD03F01 ON time_track_calendar_suggestions (connection_id);
CREATE INDEX IDX_3C8E3EF7A76ED395 ON time_track_calendar_suggestions (user_id);
CREATE TABLE time_track_calendar_rules (id VARCHAR(36) NOT NULL, created_at DATETIME NOT NULL, scope_key VARCHAR(64) NOT NULL, pattern VARCHAR(120) NOT NULL, task_id VARCHAR(36) NOT NULL, group_matches BOOLEAN DEFAULT 1 NOT NULL, user_id INTEGER NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_84F867CAA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE);
CREATE INDEX idx_time_track_calendar_rules_user ON time_track_calendar_rules (user_id, scope_key);
CREATE INDEX IDX_84F867CAA76ED395 ON time_track_calendar_rules (user_id);
```

Rollback:

```sql
-- Rollback to 1.13 (SQLite 3.35+): drops the 1.14 calendar tables; 1.13 ignores them otherwise.
DROP TABLE time_track_calendar_suggestions;
DROP TABLE time_track_calendar_rules;
DROP TABLE time_track_calendar_connections;
```

</details>

**Calendar suggestions are off by default** (`calendar.enabled: false`). To turn them on, implement and configure:

- `Calendar\TaskKeyResolverInterface` (`calendar.task_key_resolver`, required): which task keys a title carries and the
  tasks they mean in a tenant scope (Beacon: `#<first 8 hex of the task UUID>` and task URLs, Q-B8);
- `Calendar\CalendarCredentialProviderInterface` (`calendar.credential_provider`, required for `modes: [server]`): a
  short-lived access token per sync from your encrypted store, `revoke()` (delete the token, revoke the grant) and the
  *Connect* URL of your OAuth flow (Q-B9). The bundle never stores a token;
- record each user's consent with `Calendar\CalendarConnectionService::connect()` after your OAuth flow (and revoke it
  with `revoke()`, which calls your `revoke()` first);
- install `symfony/http-client` (now a `suggest`): the Google / Microsoft adapters use the framework `http_client`
  (without it a sync fails with `no_http_client`);
- schedule `nowo:time-track:calendar:sync` (e.g. every 15 minutes): it syncs due connections only inside their owner's
  working hours (`ClientUserProfileProviderInterface::getWorkingHours()`; unknown hours are never inside) and purges
  expired suggestions.

OAuth scopes (least privilege): Google `https://www.googleapis.com/auth/calendar.events.readonly`, Microsoft
`Calendars.ReadBasic` + `offline_access`. Only the **primary** calendar is read, `calendar.lookback_days` (14) back, and
only events whose title carries a resolvable key or matches a user rule become suggestions; nothing else of an event is
stored (no title, description, attendees, location, link or provider id — only keyed HMAC references). Delete the user's
connections with their account (FK `ON DELETE CASCADE`) and call your own token revoke on account erasure.

**New events**: `nowo_time_track.calendar.connection_changed` (`CalendarConnectionChangedEvent`),
`nowo_time_track.calendar.sync_finished` (`CalendarSyncFinishedEvent`) and
`nowo_time_track.calendar.suggestion_decided` (`CalendarSuggestionDecidedEvent`): ids, codes, counts and the consent
version only, for your audit rows (`time_track.calendar.*`). `nowo_time_track.time_entry.created`
(`TimeEntryCreatedEvent`) fires for entries created without a timer (`POST entries`, accepted suggestions): update the
TaskBoard bundle to the patch that listens to it so task totals include them.

**Manual entries are off by default** (`manual_entries.enabled: false`). With it on, `POST /api/time-track/entries`
creates `manual` / `calendar` entries (≤ 24 h, not in the future, at most `manual_entries.max_age_days` old, no overlap,
period lock). A host replacement of `TimeEntryRepositoryInterface` may implement
`OverlappingTimeEntryRepositoryInterface` for an exact overlap query; without it the overlap check reads the two days
before the new entry.

**Client API (additive).** New scopes `entries:write`, `suggestions:read`, `suggestions:write` are never granted by
default (as for the 1.13 scopes): request them in the device flow. New routes (404 while their feature is off):
`calendar/connections` (GET, and DELETE `/{id}`), `suggestions`, `suggestions/accept`, `suggestions/dismiss`,
`suggestions/resolve-keys`, `POST entries`. CORS preflights now allow `DELETE`. `GET me.features.calendar_suggestions`
and `features.manual_entries` become real.

## To 1.13.0

From **1.12.0** (contract `timetrack-next` v1.0):

```bash
composer update nowo-tech/time-track-bundle
```

**Schema migration required (every host).** 1.13 adds one nullable column to `time_track_entries` and three tables
that are always mapped, whatever the configuration (empty until timesheets are enabled): `time_track_timesheets`,
`time_track_timesheet_events` (audit trail) and `time_track_balance_ledger`. Run `doctrine:migrations:diff` (the diff
is exactly the statements below for the default prefix) or apply the reference SQL of your platform. Replace `users`
and `INT` with your user table and its primary-key type (Beacon: `INT`). The names `IDX_…` / `FK_…` are the ones
Doctrine derives for the default `time_track_` prefix; with another prefix, let `doctrine:migrations:diff` name them.
No data is rewritten; the files are also in `docs/sql/1.13.0/`, and the demo ships the MySQL one as
`demo/symfony8/migrations/Version20261106000000.php`.

<details><summary>MySQL 8 / MariaDB (docs/sql/1.13.0/mysql-up.sql)</summary>

```sql
-- TimeTrackBundle 1.13.0, MySQL 8 / MariaDB 10.6+ (default table prefix; replace `users` and INT with your user table and PK type).
ALTER TABLE time_track_entries ADD scope_key VARCHAR(64) DEFAULT NULL;
CREATE INDEX idx_time_track_entries_user_scope_started ON time_track_entries (user_id, scope_key, started_at);
CREATE TABLE time_track_timesheets (id VARCHAR(36) NOT NULL, period_kind VARCHAR(8) NOT NULL, period_start DATE NOT NULL, period_end DATE NOT NULL, status VARCHAR(16) NOT NULL, locked_at DATETIME DEFAULT NULL, lock_reason VARCHAR(16) DEFAULT NULL, submitted_at DATETIME DEFAULT NULL, submitted_by_ref VARCHAR(64) DEFAULT NULL, decided_at DATETIME DEFAULT NULL, decided_by_ref VARCHAR(64) DEFAULT NULL, decision_comment VARCHAR(1000) DEFAULT NULL, totals JSON DEFAULT NULL, entries_hash CHAR(64) DEFAULT NULL, head_hash CHAR(64) DEFAULT NULL, version INT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, owner_ref VARCHAR(64) NOT NULL, scope_key VARCHAR(64) NOT NULL, timezone VARCHAR(64) NOT NULL, user_id INT DEFAULT NULL, INDEX idx_time_track_timesheets_queue (scope_key, status, period_start), INDEX idx_time_track_timesheets_lock (user_id, scope_key, locked_at, period_end), UNIQUE INDEX uniq_time_track_timesheets_period (user_id, scope_key, period_kind, period_start), INDEX IDX_B3267224A76ED395 (user_id), PRIMARY KEY (id));
CREATE TABLE time_track_timesheet_events (id VARCHAR(36) NOT NULL, occurred_at DATETIME NOT NULL, hash CHAR(64) NOT NULL, seq INT NOT NULL, action VARCHAR(24) NOT NULL, from_status VARCHAR(16) NOT NULL, to_status VARCHAR(16) NOT NULL, actor_ref VARCHAR(64) DEFAULT NULL, actor_role VARCHAR(16) NOT NULL, client VARCHAR(16) NOT NULL, prev_hash CHAR(64) DEFAULT NULL, comment VARCHAR(1000) DEFAULT NULL, entries_hash CHAR(64) DEFAULT NULL, totals JSON DEFAULT NULL, timesheet_id VARCHAR(36) NOT NULL, UNIQUE INDEX uniq_time_track_timesheet_events_seq (timesheet_id, seq), INDEX IDX_6F44086ABDD46BE (timesheet_id), PRIMARY KEY (id));
CREATE TABLE time_track_balance_ledger (id VARCHAR(36) NOT NULL, effective_date DATE NOT NULL, created_at DATETIME NOT NULL, hash CHAR(64) NOT NULL, purged_before DATE DEFAULT NULL, owner_ref VARCHAR(64) NOT NULL, scope_key VARCHAR(64) NOT NULL, kind VARCHAR(24) NOT NULL, seconds INT NOT NULL, prev_hash CHAR(64) DEFAULT NULL, reason VARCHAR(500) DEFAULT NULL, reverses_id VARCHAR(36) DEFAULT NULL, actor_ref VARCHAR(64) DEFAULT NULL, timesheet_id VARCHAR(36) DEFAULT NULL, user_id INT DEFAULT NULL, INDEX idx_time_track_balance_ledger_user (user_id, scope_key, effective_date), INDEX idx_time_track_balance_ledger_timesheet (timesheet_id), INDEX idx_time_track_balance_ledger_reverses (reverses_id), UNIQUE INDEX uniq_time_track_balance_ledger_chain (owner_ref, scope_key, prev_hash), INDEX IDX_24B61F4DA76ED395 (user_id), PRIMARY KEY (id));
ALTER TABLE time_track_timesheets ADD CONSTRAINT FK_B3267224A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL;
ALTER TABLE time_track_timesheet_events ADD CONSTRAINT FK_6F44086ABDD46BE FOREIGN KEY (timesheet_id) REFERENCES time_track_timesheets (id) ON DELETE CASCADE;
ALTER TABLE time_track_balance_ledger ADD CONSTRAINT FK_24B61F4DABDD46BE FOREIGN KEY (timesheet_id) REFERENCES time_track_timesheets (id) ON DELETE SET NULL;
ALTER TABLE time_track_balance_ledger ADD CONSTRAINT FK_24B61F4DA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL;
```

Rollback:

```sql
-- Rollback to 1.12 (MySQL / MariaDB): drops the 1.13 tables and the entry scope; 1.12 ignores them otherwise.
ALTER TABLE time_track_balance_ledger DROP FOREIGN KEY FK_24B61F4DABDD46BE;
ALTER TABLE time_track_balance_ledger DROP FOREIGN KEY FK_24B61F4DA76ED395;
ALTER TABLE time_track_timesheet_events DROP FOREIGN KEY FK_6F44086ABDD46BE;
ALTER TABLE time_track_timesheets DROP FOREIGN KEY FK_B3267224A76ED395;
DROP TABLE time_track_balance_ledger;
DROP TABLE time_track_timesheet_events;
DROP TABLE time_track_timesheets;
DROP INDEX idx_time_track_entries_user_scope_started ON time_track_entries;
ALTER TABLE time_track_entries DROP scope_key;
```

</details>

<details><summary>PostgreSQL 13+ (docs/sql/1.13.0/postgresql-up.sql)</summary>

```sql
-- TimeTrackBundle 1.13.0, PostgreSQL 13+ (default table prefix; replace `users` and INT with your user table and PK type).
ALTER TABLE time_track_entries ADD scope_key VARCHAR(64) DEFAULT NULL;
CREATE INDEX idx_time_track_entries_user_scope_started ON time_track_entries (user_id, scope_key, started_at);
CREATE TABLE time_track_timesheets (id VARCHAR(36) NOT NULL, period_kind VARCHAR(8) NOT NULL, period_start DATE NOT NULL, period_end DATE NOT NULL, status VARCHAR(16) NOT NULL, locked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, lock_reason VARCHAR(16) DEFAULT NULL, submitted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, submitted_by_ref VARCHAR(64) DEFAULT NULL, decided_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, decided_by_ref VARCHAR(64) DEFAULT NULL, decision_comment VARCHAR(1000) DEFAULT NULL, totals JSON DEFAULT NULL, entries_hash CHAR(64) DEFAULT NULL, head_hash CHAR(64) DEFAULT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_ref VARCHAR(64) NOT NULL, scope_key VARCHAR(64) NOT NULL, timezone VARCHAR(64) NOT NULL, user_id INT DEFAULT NULL, PRIMARY KEY (id));
CREATE INDEX idx_time_track_timesheets_queue ON time_track_timesheets (scope_key, status, period_start);
CREATE INDEX idx_time_track_timesheets_lock ON time_track_timesheets (user_id, scope_key, locked_at, period_end);
CREATE UNIQUE INDEX uniq_time_track_timesheets_period ON time_track_timesheets (user_id, scope_key, period_kind, period_start);
CREATE INDEX IDX_B3267224A76ED395 ON time_track_timesheets (user_id);
CREATE TABLE time_track_timesheet_events (id VARCHAR(36) NOT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, hash CHAR(64) NOT NULL, seq INT NOT NULL, action VARCHAR(24) NOT NULL, from_status VARCHAR(16) NOT NULL, to_status VARCHAR(16) NOT NULL, actor_ref VARCHAR(64) DEFAULT NULL, actor_role VARCHAR(16) NOT NULL, client VARCHAR(16) NOT NULL, prev_hash CHAR(64) DEFAULT NULL, comment VARCHAR(1000) DEFAULT NULL, entries_hash CHAR(64) DEFAULT NULL, totals JSON DEFAULT NULL, timesheet_id VARCHAR(36) NOT NULL, PRIMARY KEY (id));
CREATE UNIQUE INDEX uniq_time_track_timesheet_events_seq ON time_track_timesheet_events (timesheet_id, seq);
CREATE INDEX IDX_6F44086ABDD46BE ON time_track_timesheet_events (timesheet_id);
CREATE TABLE time_track_balance_ledger (id VARCHAR(36) NOT NULL, effective_date DATE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, hash CHAR(64) NOT NULL, purged_before DATE DEFAULT NULL, owner_ref VARCHAR(64) NOT NULL, scope_key VARCHAR(64) NOT NULL, kind VARCHAR(24) NOT NULL, seconds INT NOT NULL, prev_hash CHAR(64) DEFAULT NULL, reason VARCHAR(500) DEFAULT NULL, reverses_id VARCHAR(36) DEFAULT NULL, actor_ref VARCHAR(64) DEFAULT NULL, timesheet_id VARCHAR(36) DEFAULT NULL, user_id INT DEFAULT NULL, PRIMARY KEY (id));
CREATE INDEX idx_time_track_balance_ledger_user ON time_track_balance_ledger (user_id, scope_key, effective_date);
CREATE INDEX idx_time_track_balance_ledger_timesheet ON time_track_balance_ledger (timesheet_id);
CREATE INDEX idx_time_track_balance_ledger_reverses ON time_track_balance_ledger (reverses_id);
CREATE UNIQUE INDEX uniq_time_track_balance_ledger_chain ON time_track_balance_ledger (owner_ref, scope_key, prev_hash);
CREATE INDEX IDX_24B61F4DA76ED395 ON time_track_balance_ledger (user_id);
ALTER TABLE time_track_timesheets ADD CONSTRAINT FK_B3267224A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE;
ALTER TABLE time_track_timesheet_events ADD CONSTRAINT FK_6F44086ABDD46BE FOREIGN KEY (timesheet_id) REFERENCES time_track_timesheets (id) ON DELETE CASCADE NOT DEFERRABLE;
ALTER TABLE time_track_balance_ledger ADD CONSTRAINT FK_24B61F4DABDD46BE FOREIGN KEY (timesheet_id) REFERENCES time_track_timesheets (id) ON DELETE SET NULL NOT DEFERRABLE;
ALTER TABLE time_track_balance_ledger ADD CONSTRAINT FK_24B61F4DA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE;
```

Rollback:

```sql
-- Rollback to 1.12 (PostgreSQL): drops the 1.13 tables and the entry scope; 1.12 ignores them otherwise.
ALTER TABLE time_track_balance_ledger DROP CONSTRAINT FK_24B61F4DABDD46BE;
ALTER TABLE time_track_balance_ledger DROP CONSTRAINT FK_24B61F4DA76ED395;
ALTER TABLE time_track_timesheet_events DROP CONSTRAINT FK_6F44086ABDD46BE;
ALTER TABLE time_track_timesheets DROP CONSTRAINT FK_B3267224A76ED395;
DROP TABLE time_track_balance_ledger;
DROP TABLE time_track_timesheet_events;
DROP TABLE time_track_timesheets;
DROP INDEX idx_time_track_entries_user_scope_started;
ALTER TABLE time_track_entries DROP scope_key;
```

</details>

<details><summary>SQLite 3.35+ (docs/sql/1.13.0/sqlite-up.sql)</summary>

```sql
-- TimeTrackBundle 1.13.0, SQLite 3.35+ (default table prefix; replace `users` with your user table). Foreign keys are declared inline.
ALTER TABLE time_track_entries ADD COLUMN scope_key VARCHAR(64) DEFAULT NULL;
CREATE INDEX idx_time_track_entries_user_scope_started ON time_track_entries (user_id, scope_key, started_at);
CREATE TABLE time_track_timesheets (id VARCHAR(36) NOT NULL, period_kind VARCHAR(8) NOT NULL, period_start DATE NOT NULL, period_end DATE NOT NULL, status VARCHAR(16) NOT NULL, locked_at DATETIME DEFAULT NULL, lock_reason VARCHAR(16) DEFAULT NULL, submitted_at DATETIME DEFAULT NULL, submitted_by_ref VARCHAR(64) DEFAULT NULL, decided_at DATETIME DEFAULT NULL, decided_by_ref VARCHAR(64) DEFAULT NULL, decision_comment VARCHAR(1000) DEFAULT NULL, totals CLOB DEFAULT NULL, entries_hash CHAR(64) DEFAULT NULL, head_hash CHAR(64) DEFAULT NULL, version INTEGER DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, owner_ref VARCHAR(64) NOT NULL, scope_key VARCHAR(64) NOT NULL, timezone VARCHAR(64) NOT NULL, user_id INTEGER DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_B3267224A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE);
CREATE INDEX idx_time_track_timesheets_queue ON time_track_timesheets (scope_key, status, period_start);
CREATE INDEX idx_time_track_timesheets_lock ON time_track_timesheets (user_id, scope_key, locked_at, period_end);
CREATE UNIQUE INDEX uniq_time_track_timesheets_period ON time_track_timesheets (user_id, scope_key, period_kind, period_start);
CREATE INDEX IDX_B3267224A76ED395 ON time_track_timesheets (user_id);
CREATE TABLE time_track_timesheet_events (id VARCHAR(36) NOT NULL, occurred_at DATETIME NOT NULL, hash CHAR(64) NOT NULL, seq INTEGER NOT NULL, "action" VARCHAR(24) NOT NULL, from_status VARCHAR(16) NOT NULL, to_status VARCHAR(16) NOT NULL, actor_ref VARCHAR(64) DEFAULT NULL, actor_role VARCHAR(16) NOT NULL, client VARCHAR(16) NOT NULL, prev_hash CHAR(64) DEFAULT NULL, comment VARCHAR(1000) DEFAULT NULL, entries_hash CHAR(64) DEFAULT NULL, totals CLOB DEFAULT NULL, timesheet_id VARCHAR(36) NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_6F44086ABDD46BE FOREIGN KEY (timesheet_id) REFERENCES time_track_timesheets (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE);
CREATE UNIQUE INDEX uniq_time_track_timesheet_events_seq ON time_track_timesheet_events (timesheet_id, seq);
CREATE INDEX IDX_6F44086ABDD46BE ON time_track_timesheet_events (timesheet_id);
CREATE TABLE time_track_balance_ledger (id VARCHAR(36) NOT NULL, effective_date DATE NOT NULL, created_at DATETIME NOT NULL, hash CHAR(64) NOT NULL, purged_before DATE DEFAULT NULL, owner_ref VARCHAR(64) NOT NULL, scope_key VARCHAR(64) NOT NULL, kind VARCHAR(24) NOT NULL, seconds INTEGER NOT NULL, prev_hash CHAR(64) DEFAULT NULL, reason VARCHAR(500) DEFAULT NULL, reverses_id VARCHAR(36) DEFAULT NULL, actor_ref VARCHAR(64) DEFAULT NULL, timesheet_id VARCHAR(36) DEFAULT NULL, user_id INTEGER DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_24B61F4DABDD46BE FOREIGN KEY (timesheet_id) REFERENCES time_track_timesheets (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_24B61F4DA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE);
CREATE INDEX idx_time_track_balance_ledger_user ON time_track_balance_ledger (user_id, scope_key, effective_date);
CREATE INDEX idx_time_track_balance_ledger_timesheet ON time_track_balance_ledger (timesheet_id);
CREATE INDEX idx_time_track_balance_ledger_reverses ON time_track_balance_ledger (reverses_id);
CREATE UNIQUE INDEX uniq_time_track_balance_ledger_chain ON time_track_balance_ledger (owner_ref, scope_key, prev_hash);
CREATE INDEX IDX_24B61F4DA76ED395 ON time_track_balance_ledger (user_id);
```

Rollback:

```sql
-- Rollback to 1.12 (SQLite 3.35+): drops the 1.13 tables and the entry scope; 1.12 ignores them otherwise.
DROP TABLE time_track_balance_ledger;
DROP TABLE time_track_timesheet_events;
DROP TABLE time_track_timesheets;
DROP INDEX idx_time_track_entries_user_scope_started;
ALTER TABLE time_track_entries DROP COLUMN scope_key;
```

</details>

Rollback in general: drop the three tables and the column; 1.12 ignores them otherwise.

**Optional triggers (append-only audit).** The bundle never updates or deletes audit rows except through the retention
purge. Hosts that want the database to enforce it (spec `198` FR-017 pattern) MAY add triggers that refuse `UPDATE` on
`time_track_timesheet_events` / `time_track_balance_ledger` and `DELETE` for every database user but the one that runs
`nowo:time-track:timesheets:purge`. The bundle does not create them (privileges vary).

**`scope_key` back-fill (with timesheets on).** New entries get the tenant scope of their task at write time. Entries
recorded before 1.13 have none until you run (idempotent, resumable, keyset by id):

```bash
php bin/console nowo:time-track:entries:backfill-scope --dry-run
php bin/console nowo:time-track:entries:backfill-scope --batch=500
```

Entries the scope provider cannot place stay without a scope: they belong to no timesheet and are shown to their owner
only, as *unassigned*.

**Hosts that write entries themselves MUST call the period lock.** With timesheets on, every bundle write path refuses
time in a locked or submitted period (`PeriodLockedException` → 409 `period_locked` with `lockedThrough`,
`PeriodSubmittedException` → 409 `period_submitted`): backdated `timer/start`, `timer/switch`, idle decisions,
`ImportedTimeEntryWriter` (new outcome `locked`, counted in `ImportedTimeEntryBatchResult::$locked`), imported-entry
removals (single `remove()` throws; bulk `removeForTasks()` / `removeForBoard()` / `removeForSource()` keep the locked
entries; `removeForUser()` — account erasure — is not blocked), `TeamAccessGuard::canEditEntry()` /
`canDeleteImportedEntry()` (false whatever a `TimeEntryAccessCheckEvent` listener grants). Code that inserts or edits
`TimeEntry` rows directly must call `PeriodLockGuard::assertWritable($user, $scopeKey, $instant, WriteKind::…)` first.
Retention purges are never blocked. With timesheets off nothing changes and no query is made.

**Client API (additive).** New scopes `workday:read`, `workday:write`, `balance:read`, `timesheet:read`,
`timesheet:write` are never granted by default: a token without stored scopes (issued before 1.9) and a login whose
`ClientAuthResult` names none keep the four pre-1.13 scopes (before 1.13 "no scopes" meant "every scope"; with the new
scopes that would have widened old tokens). Grant them explicitly (device flow `scopes`, or your authenticator). `GET me`
gains `features.timesheets`, `balance`, `calendar_suggestions`, `manual_entries`, `task_effort` and `timesheet`;
`features.workday` is real. Entries gain `scopeKey` and `locked`, the CSV export gains `scope_key` and `locked` columns
(appended), tasks and timers gain `estimateSeconds`, `loggedSeconds`, `remainingSeconds`. Clients that compare whole
payloads must accept the new keys. Beacon: wire the new routes (`config/routes.yaml` imports the loader, nothing to do
there) and implement `Workday\WorkdayProviderInterface` for spec 198 (`client_api.workday_provider`).

**Task effort.** Let your task provider fill `TaskReference::$estimateSeconds` (TaskBoard `estimatedMinutes × 60`); the
bundle sums `loggedSeconds` from its entries unless the provider gives `TaskReference::$loggedSeconds`. It is the caller's
own time; the total of every user is shown only to users your listener on `nowo_time_track.task_effort.access_check`
grants (those who can manage the task; denied by default, see SECURITY). Tasks and timers carry `loggedSecondsScope`
(`own` / `all`) so clients can say whose time the figure counts; a provider that fills `loggedSeconds` is labelled by the
same rule. Ledger refusals (`LedgerRefusedException::errorCode()`) now have `timesheet.error.<code>` messages too.

**Timesheet web actions (CSRF).** `TimesheetFormHandler` verifies the CSRF token itself and refuses every post when no
CSRF token manager exists: keep `framework.csrf_protection` enabled (`symfony/security-csrf`). The decision forms now
carry hidden `scope` and `period` fields; owner submit / withdraw act on the actor's own period.

**Rate limits and personal data.** Put a per-actor `RateLimiter` on your ledger (and decision) routes: the bundle does not
limit web ledger writes in 1.13. Add `Timesheet\TimesheetPersonalDataExporter::export($ownerRef)` to your personal-data
export (timesheets, their audit rows and the ledger).

**Balance bounds.** `working_time.balance.start_date` is honoured for at most 3660 days before the end of a range; beyond
that no balance is computed (warning `balance_span_exceeded`) until a locked timesheet or a later start date shortens the
span. `GET timesheets?period=` answers 404 for keys after the next period or outside 2000–2099, and only the current and
previous periods get a stored row when viewed.

**UiKit 2.29.** `nowo-tech/ui-kit-bundle` is now `^2.29` (dialog anatomy of the components). Run
`composer update nowo-tech/ui-kit-bundle` if your lock is older.

**Components instead of overrides.** Build the *My timesheet*, *Approvals* and *Balance* pages from
`nowo_time_track_component()` and post their forms to your routes, handled by `Timesheet\TimesheetFormHandler`
([WORKING-TIME.md](WORKING-TIME.md#components-no-template-overrides)).

**`TimeEntrySource` has two new cases**, `manual` and `calendar` (live, editable; their write paths arrive in 1.14). A
host `match` over the enum without a default arm must add them.

**Bulk removals with timesheets on** need the entry store to implement `FilteredImportedTimeEntryRepositoryInterface`
(the bundle's Doctrine store does). A host replacement of `TimeEntryRepositoryInterface` that only implements
`ImportedTimeEntryRepositoryInterface` makes `removeForTasks()` / `removeForBoard()` / `removeForSource()` throw
`LogicException` (fail closed) while timesheets are on.

## To 1.12.0

From **1.11.0**:

```bash
composer update nowo-tech/time-track-bundle
```

No schema change, no configuration change.

**`GET me.workingHours` is normalised.** If your `ClientUserProfileProviderInterface::getWorkingHours()` returns the
documented 1.9 `days` shape, the clients now receive it as `slots` (before, they could not read it and treated it as
"outside working hours"). The `slots` shape is also accepted and is the recommended one. Check that your value is valid:
an unknown or non-canonical time zone (`europe/madrid`), a string day (`"1"`), a bad time (`" 9:00"`, `09:00:00`),
`from` = `to` (`00:00`–`00:00` does not mean "all day"), more than 100 ranges or a missing `slots` / `days` key is now
sent as `{timezone, slots: []}` (fail closed: never inside working hours, so the clients send no notifications limited
to working hours) and logged as a `warning` with the reason, instead of being passed through. `null` is sent only when
your provider returns `null` (unknown). Overnight ranges are split at
midnight and overlapping ones merged ([CLIENT-API](CLIENT-API.md#working-hours-normalised-since-112)).

**`GET me.daysOff` (new, optional).** Always present, `null` by default. To publish holidays and vacations, let your
profile provider also implement `ClientUserDaysOffProviderInterface`:

```php
public function getDaysOff(UserInterface $user, DateTimeImmutable $from, DateTimeImmutable $to): ?array
{
    // $from = start of today, $to = end of today + 60 days, in the user's working-hours time zone.
    return [
        ['date' => '2026-12-08', 'kind' => 'holiday', 'label' => 'Inmaculada Concepción'],
        ['date' => '2026-12-28', 'kind' => 'vacation'],
    ];
}
```

Dates are local `YYYY-MM-DD` in that time zone; `kind` is `holiday` or `vacation` (any case); `label` is optional plain
text (cleaned of control / bidi / zero-width characters, cut to 80 grapheme clusters; do not HTML-escape it). A
malformed entry is dropped alone and logged as a `warning`; a value that is not a list is sent as `null`. The method runs
on every `GET me`: cache it if it reads a calendar service.

**Privacy.** Labels reach the user's devices and are stored there. Send public holiday names only, `null` for
vacations, and never a leave reason. TimeTrack Desktop 0.5.0+ (unreleased) reads `daysOff`; the extension ignores it. A class implementing
`ClientUserProfileProviderInterface` keeps working unchanged.

**Imported entries (audit follow-ups).**

- `ImportedTimeEntryRepositoryInterface` is now the service `TimeEntryRepositoryInterface` resolves to (your
  replacement or decorator), no longer the raw Doctrine store. If you replace or decorate `TimeEntryRepositoryInterface`
  and read imported entries, implement `ImportedTimeEntryRepositoryInterface` on that service (a decorator delegates
  the methods) — getting the interface otherwise throws `LogicException` — or alias the interface yourself.
- A masked imported entry (`taskVisible: false`) now has `description: null` in the client API and an empty CSV
  `description` cell. Host lists that call `toArray(false, …)` get the same.
- The writer refuses `startedAt` before 1970 (`startedAt` / `too_old`) and `endedAt` more than one day in the future
  (`endedAt` / `future`). Skip or fix such rows before `upsertMany()`.
- After `remove*()` (DQL bulk deletes), entries of the removed rows that your entity manager had loaded are detached.
  Do not keep using `TimeEntry` objects of removed rows.
- The masked title is translated into the user's language only when the bundle ships it (`de`, `en`, `es`, `fr`, `it`,
  `nl`, `pt`); otherwise the translator's default locale is used.
- Optional: let your task provider implement `Integration\BatchTaskProviderInterface::filterTrackable()` so masking
  makes one call per entry owner (large exports).

## To 1.11.0

**Schema (every host).** Additive and nullable; existing rows are unchanged. Doctrine hosts run
`doctrine:migrations:diff`; the diff must be exactly this (table name without your `table_prefix`):

```sql
-- MySQL / MariaDB
ALTER TABLE time_track_entries
  ADD external_source VARCHAR(64) DEFAULT NULL, ADD external_id VARCHAR(128) DEFAULT NULL,
  ADD billable TINYINT(1) DEFAULT NULL, ADD description LONGTEXT DEFAULT NULL;
CREATE UNIQUE INDEX uniq_time_track_entries_external ON time_track_entries (external_source, external_id);
CREATE INDEX idx_time_track_entries_source ON time_track_entries (source);
CREATE INDEX time_track_entries_ended_idx ON time_track_entries (ended_at, id);
-- PostgreSQL: BOOLEAN / TEXT. SQLite: one ADD COLUMN per column, BOOLEAN / CLOB.
```

**`time_track_entries_ended_idx` already exists?** Beacon added it in its own migration (`Version20261121090000`)
with the same name and columns, so for Beacon the diff has no `CREATE INDEX` for it: keep that migration as it is.
If **your** host created an equivalent index under **another name**, the diff creates a second one. Either drop yours
in the same migration (`DROP INDEX <name> ON time_track_entries;` on MySQL, `DROP INDEX <name>;` on PostgreSQL /
SQLite), or rename it when it already has the columns `(ended_at, id)` (`ALTER TABLE time_track_entries RENAME INDEX
<name> TO time_track_entries_ended_idx` on MySQL 8, `ALTER INDEX <name> RENAME TO time_track_entries_ended_idx` on
PostgreSQL) and remove the `CREATE INDEX` line. A single-column `(ended_at)` index is covered by the new one: drop it.

A reference migration (MySQL) ships in the demo: `demo/symfony8/migrations/Version20261105000000.php`.

**Downgrade.** 1.10 cannot hydrate `source = 'import'` (`TimeEntrySource::from()` throws): delete the imported rows
(`DELETE FROM time_track_entries WHERE source = 'import'`) before going back. The columns and indexes can stay.

**Code.**

- `TimeEntrySource` has a new case `Import`: an exhaustive `match` on it must handle `import`.
- `TimeEntry::toArray()` has more keys (`billable`, `description`, `readOnly`, `taskVisible`); the CSV export has
  three more columns at the end. Positional CSV readers keep working.
- Live-time logic in the host (overlap checks, working-hours registers, correction windows, forgotten-timer stoppers,
  clock-in links) must read **live entries only**: `TimeEntrySource::isLive()` or
  `ImportedTimeEntryRepositoryInterface::findLiveByUserAndPeriod()`, or set
  `TimeEntryListQueryEvent::setIncludeImported(false)`.
- Retention purges (yours, or the bundle's later) must skip `TimeEntryRetentionPolicy::excludedSources()` (imported
  entries are kept as history unless `retention.include_imported: true`).
- **Deleting tasks or boards never deletes tracked time** (owner rule 2026-11-05). `removeForTasks()` /
  `removeForBoard()` / `remove()` are for an **explicit manager action** on imported time only; do not call them from
  a task or board deletion. Account erasure may call `removeForUser()`.
- `GET me` has a new key `locale` (string or null). To fill it, let your `client_api.profile_provider` service also
  implement `Client\ClientUserLocaleProviderInterface`; otherwise the user's `getLocale()` is used when it exists.
- `upsertMany()` refuses a batch with two rows for the same `(externalSource, externalId)` (`rows` / `duplicate`):
  send each identity once per call.
- Concurrent imports: a unique violation is retried once in the writer only with a `ManagerRegistry` and outside any
  host transaction; otherwise `UniqueConstraintViolationException` is rethrown (nothing written) and the host retries
  the slice. Do not call the writer inside your own transaction. A custom `ImportedTimeEntryRepositoryInterface`
  store implements `canRetryAfterFailedSave()` and `attachUser()`.
- **Task titles are masked per surface.** Overrides of `reports.html.twig` (including a surgical
  `nowo_ui_list_row` override) must keep the `hidden_task_entry_ids` check; host lists and exports of entries (e.g.
  *My time*, monthly summaries, inspection exports) must call `TimerService::hiddenTaskEntryIds()` and use
  `toArray(false, $translatedTitle)` / `reports.task_not_visible` for those ids. `taskTitleSnapshot` and `toArray()`
  without arguments return the real title.
- The client API's own `GET entries` and `GET export` always include the user's imported entries (GDPR art. 15 / 20)
  and ignore `setIncludeImported(false)`.
- A custom `TimeEntryRepositoryInterface` store keeps working; only `ImportedTimeEntryWriter` needs
  `ImportedTimeEntryRepositoryInterface` (it throws `LogicException` otherwise).
- Clients (Desktop, Extension): render `source: import` as *Imported*, never offer edit, and skip `readOnly` entries
  for *Resume* and recent tasks.

## To 1.10.3

From **1.10.2**: no schema change, no config change.

```bash
composer update nowo-tech/time-track-bundle
```

- Notes no longer keep U+061C, U+200E or U+200F (EF2-I7).
- Hosts calling `TimerService::start()` / `startAt()` with `['note' => …]` in the metadata get it cleaned like a client
  note; a non-string or blank note is dropped from the metadata (EF2-I9). Other metadata keys are unchanged.
- A timer note at 500 code points does not take later notes (they are dropped, earlier text wins), as documented in
  CLIENT-API (EF2-I8).

## To 1.10.2

From **1.10.1**: no schema change, no config change.

```bash
composer update nowo-tech/time-track-bundle
```

- **Stop and switch notes are merged, not replaced.** An entry's `metadata.note` is now the start note, then every
  idle `keep` note, then the stop / switch note, one per line, cut at 500 code points (earlier text first). Before,
  a stop note replaced the notes collected on the timer. Code that reads `metadata.note` and expected only the stop
  note should split on `\n` or take the last line.
- **Follow-up timers start without a note** (idle `split`, reassign `then: continue`); the note stays on the entry up
  to the idle start.
- **Notes are cleaned**: no bidi controls, no U+200B / U+FEFF, control characters folded to one space, trimmed. Hosts
  calling `TimerService` directly get the same cleaning; `ActiveTimer::appendNote()`'s `$maxLength` is now optional.
- **`IdleResolvedEvent::getNote()` is raw user text** (EF2-I5). It is cleaned but not escaped: escape it for HTML, CSV
  or any other output, and do not write it to logs.
- **Known race (EF2-I1, unchanged).** The keep note is appended with a read-modify-write of the timer row: two keeps
  sent at the same moment for the same timer can lose one note, and a keep that races a stop answers `200` although
  the entry does not get its note. It only affects the user's own annotation; clients send one decision at a time.

## To 1.10.1

From **1.10.0**: no schema change, no BC break.

```bash
composer update nowo-tech/time-track-bundle
```

- `IdleCheckSettings` has a fourth constructor argument, `userCanDisable` (default `false`): an
  `IdleCheckSettingsProviderInterface` written for 1.10.0 keeps working and reports `user_can_disable: false`. Pass
  `true` for users who may turn the idle check off (Beacon: personal accounts), or set
  `client_api.idle_check.user_can_disable` for the instance default.
- `timer/discard-idle` `keep` with a `note` now updates the running timer (`metadata.note`). Listeners of
  `nowo_time_track.idle.resolved` can read it with `IdleResolvedEvent::getNote()`; do not log the text.

## To 1.10.0

Client API contract v0.3. From **1.9.2**: no schema change, no config change needed.

```bash
composer update nowo-tech/time-track-bundle
```

- **Task provider (optional).** Fill the new `TaskReference` arguments to show the project and an "open in" link in
  the clients: `new TaskReference($id, $title, $boardId, $boardTitle, projectId: …, projectTitle: …, url: …)`. The
  `url` must be an absolute `https://` URL of the task page on the host's public origin; anything else is published as
  `null`. `TaskProviderInterface` is unchanged.
- **Behind a TLS-terminating proxy.** URLs are compared with the request origin; without `framework.trusted_proxies`
  the request looks like `http://…` and every URL is dropped. Either trust the proxy or set the origin explicitly from
  your public URL parameter: `nowo_time_track.client_api.task_url.origin: '%app.public_url%'` (exact
  `https://host[:port]`, no path, no trailing slash; validated when the container is built).
- **New client API behaviour** (additive): `startedAt` on `timer/start`, `endedAt` on `timer/stop`, `reassign` on
  `timer/discard-idle`, `features` / `limits` on `GET me`. Tune or disable with `client_api.backdate.*`,
  `client_api.idle.allow_reassign` and `client_api.idle_check.*` ([CONFIGURATION](CONFIGURATION.md#client-api-110)).
- **`IdleResolution` has a new case `Reassign`.** A host `match` over it without a `default` arm throws
  `UnhandledMatchError` for reassign decisions; add the case.
- **`TimerService::resolveIdle()`** returns a third key, `reassigned` (`null` except for reassign), and takes optional
  `targetTaskId`, `followUp`, `clientType`, `note` arguments. `start()` / `switchTo()` now throw
  `TaskNotTrackableException` (a subclass of `InvalidArgumentException`, so existing catches still work).
- **Own (non-Doctrine) time entry store:** implement `LatestTimeEntryRepositoryInterface::findLatestEndedAt()` for the
  backdate overlap check in one query. Without it the bundle reads the user's entries of the backdate window plus one
  day through `findByUserAndPeriod()`, so an entry that **started more than one day before the window and ended inside
  it** is missed and a backdated start may overlap it (EF-I6). The Doctrine store implements the interface.
- **Task URL origin and the `Host` header (EF-I1).** With `client_api.task_url.origin` unset, the expected origin is
  the request's, which comes from the caller's `Host` header: a caller sending `Host: evil.test` gets URLs on
  `https://evil.test` published in its own response (never in other users' responses; no shared caching). Set
  `client_api.task_url.origin` (Beacon does) or `framework.trusted_hosts`.
- **Client timestamps (1.10).** `startedAt`, `endedAt`, `from` and `to` are parsed strictly: a lower-case `z` is
  accepted, surrounding whitespace is no longer trimmed (`400`), and `from` / `to` are compared on whole seconds.
- **Listeners of `nowo_time_track.timer.stop`** now also receive the entry of a reassigned idle span (the time moved to
  another task), so per-task totals stay right. `IdleResolvedEvent` (`nowo_time_track.idle.resolved`) lets you audit
  idle decisions and `TimerStartEvent::getStartTime()` / `TimerStopEvent::getEndTime()` backdated starts and stops.
- **Overlap scope.** The backdate overlap check covers this bundle's entries only; time logged elsewhere (e.g.
  TaskBoardBundle's manual "Log time") is not considered.

## To 1.9.2

From **1.9.1**: no schema change, no BC break.

```bash
composer update nowo-tech/time-track-bundle
```

- `timer/discard-idle` now refuses `from` / `to` offsets outside `-12:00`..`+14:00` (`400 invalid_request`) and stores
  the instant the client sent (EF-M1). Entries recorded before the update with a non-UTC offset (or by a `Z` client on
  a server whose PHP time zone is not UTC) may be shifted; review them if you need exact history.
- Hosts calling `TimerService` directly should pass date-times in the server time zone (Doctrine stores the wall time
  without its offset).

## To 1.9.1

From **1.9.0**:

```bash
composer update nowo-tech/time-track-bundle
```

From **1.9.0**: no schema change, no BC break.

- `GET me` may now carry `minSupportedVersion`. To send it, set
  `client_distribution.min_supported_version.extension|desktop|web` (semver) or point
  `client_distribution.min_supported_version.provider` at your `ClientMinSupportedVersionProviderInterface` service.
  `ClientDistributionProviderInterface` is unchanged.
- **Own (non-Doctrine) active timer store:** it keeps working, but it **keeps the 1.x stop race** (two concurrent
  `timer/stop` / `timer/switch` calls can record the same entry twice) until it implements
  `ConditionalActiveTimerRepositoryInterface::removeIfPresent()` (conditional delete + the callback in one
  transaction). The bundled Doctrine store already does.
- `TimerService::stop()` / `resolveIdle()` may now return `null` when a concurrent call stopped the timer first
  (`timer/stop` / `timer/discard-idle` then answer `404 no_active_timer`). A `timer/switch` whose stop lost to a
  concurrent stop starts the new task (`201`) without recording a second entry.
- `resolveIdle()` may throw `ActiveTimerConflictException` (API: `409 timer_running`) when a concurrent start took the
  timer slot before a split continued.
- `minSupportedVersion` values from a host provider must be semver; others are omitted from `GET me`.

## To 1.9.0

From **1.8.0**:

```bash
composer update nowo-tech/time-track-bundle
```

### To 1.9.0

From **1.8.0**: `composer update nowo-tech/time-track-bundle`, then the schema migration below. Client API contract
v0.2 ([CLIENT-API](CLIENT-API.md)); no change to the manage pages.

**Schema** (MySQL; reference: `demo/symfony8/migrations/Version20261103000000.php`; use your `table_prefix`). Schema
only — no data migration, safe with `doctrine_migrations.transactional: false`:

```sql
ALTER TABLE time_track_client_tokens ADD credential_id VARCHAR(191) DEFAULT NULL, ADD scopes JSON DEFAULT NULL;
CREATE INDEX time_track_client_tokens_credential_idx ON time_track_client_tokens (credential_id);
CREATE TABLE time_track_device_codes (id VARCHAR(36) NOT NULL, status VARCHAR(16) NOT NULL, credential_id VARCHAR(191) DEFAULT NULL, last_polled_at DATETIME DEFAULT NULL, scopes JSON NOT NULL, device_code_hash VARCHAR(64) NOT NULL, user_code_hash VARCHAR(64) NOT NULL, client_type VARCHAR(16) NOT NULL, client_name VARCHAR(100) NOT NULL, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL, ip_prefix VARCHAR(64) DEFAULT NULL, user_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_4978FDD0DCB31CE8 (device_code_hash), INDEX time_track_device_codes_user_code_idx (user_code_hash), INDEX time_track_device_codes_expires_idx (expires_at), INDEX IDX_4978FDD0A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
ALTER TABLE time_track_device_codes ADD CONSTRAINT FK_4978FDD0A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE;
```

Or run `doctrine:migrations:diff` and review. Tokens issued before the upgrade keep working with every scope.

**Kept (BC)**

- `ClientAuthenticatorInterface` and `ClientAuthResult::success($user)`; the new `$credentialId` / `$scopes` arguments
  are optional (none = no credential, all scopes). A `$scopes` list must be non-empty and hold known scopes only
  (`timer:read`, `timer:write`, `tasks:read`, `entries:read`), otherwise `InvalidArgumentException`: pass `null` for
  every scope.
- `ClientTokenRepositoryInterface` is unchanged (host decorators keep compiling); bulk revocation uses the new
  `CredentialClientTokenRepositoryInterface`, implemented by the Doctrine repository.
- Login request, route names and paths; `ClientAuthService::login()` / `resolveUser()`; `TimerService::heartbeat()`.
- Without any `client_api.*` key the host interfaces fall back to 1.x behaviour (credential always active, task check
  through the task provider, opaque device credentials) and the device flow stays off.

**Behaviour changes of the client API (clients, not hosts)**

- Error bodies are `{"error": "<code>", "message": "..."}` (1.x: `{"error": "<message>"}`).
- `timer/start` on a missing or foreign task: `403 task_forbidden` (1.x: `400`). Free `metadata`: `400
  metadata_not_allowed` unless `client_api.allow_metadata: true`; send `source` and `note`.
- `heartbeat` is deprecated (removed in 2.0): `isIdle` is ignored and no longer stored in the timer metadata; use
  `timer/discard-idle`.
- `GET me` adds `displayName`, `scopes`, `workingHours`; `login` adds `scopes`; `GET entries` returns own entries by
  default (`limit` ≤ 100, `offset`) — `userId` still works through the team guard but is deprecated; `GET tasks`
  `limit` ≤ 50 (1.x: 100).
- Per-IP (300/min) and per-token (60/min) rate limits on the shared pool `client_api.rate_limit.cache_pool`
  (default `cache.app`; use Redis in production). Every per-IP limit, including the 1.x client-login throttle, now
  counts an IPv6 client by its /64.
- Client login: new per-username bucket `clients.login_rate_limit.max_attempts_per_username` (default 5 ×
  `max_attempts` = 50 failures per 15 min, any IP); a successful login clears it.
- Device flow: `device/code` answers `400 invalid_scope` for an empty or unknown scope list (omit `scopes` for every
  scope) and `429` above `client_api.device_flow.max_pending_per_prefix` (20 per /24 or /64 within `code_ttl`) or
  `max_pending` (10 000 unexpired requests).
- CORS: exact origins only. The 1.x `tauri://` prefix rule became the exact Tauri webview origins
  (`client_api.allow_tauri_webview_origins`, deprecated, true until 2.0); `clients.cors_allow_any_extension_origin`
  and `*` are deprecated.

**Deprecations (removed in 2.0)**: `POST heartbeat`, `entries?userId=`, `clients.cors_allow_any_extension_origin`,
`*` in `clients.cors_allowed_origins`, `client_api.allow_tauri_webview_origins: true`.

**Beacon-style hosts**: implement `ClientCredentialStateInterface`, `ClientCredentialIssuerInterface` and
`TaskAccessCheckerInterface`; call `ClientTokenRevoker::revokeByCredential()` when an app password is deleted; set
`device_flow.default_page: false` + `verification_uri` for your own page ([USAGE](USAGE.md#host-integration-client-api-19)),
sending `X-Frame-Options: DENY` and `Content-Security-Policy: frame-ancestors 'none'` on it.

## To 1.8.0

From **1.7.0**:

```bash
composer update nowo-tech/time-track-bundle
```

Route names, paths, controllers, form field names / CSRF id and the client API are unchanged. The manage pages now use
the UiKit page contract (REQ-UI-003); hosts that render them should check the points below.

- **Dependency:** `nowo-tech/ui-kit-bundle` `^2.20` (UiKit 1.x is no longer supported).
- **Layout:** the pages render inside `nowo_ui_kit.layouts.admin` (UiKit default: its own admin layout). Point it at
  your layout or at a one-file bridge (see [CONFIGURATION](CONFIGURATION.md#web-ui-and-templates-req-ui-001--req-ui-003)),
  or set `nowo_time_track.web_ui.layout_template`. With the 1.x default (`templates.layout` unset) the bundle demo
  shell (dark nav bar with Board / Timer / Reports links) is gone: add the navigation to your layout.
- **Deprecated config (removed in 2.0):** `templates.layout` → `web_ui.layout_template`; `templates.css_framework` →
  `nowo_ui_kit.css_framework`; `templates.index` / `templates.reports` → `web_ui.templates.index` / `.reports`. The old
  keys keep working in 1.x and trigger a deprecation. A layout set through them that only has the 1.x blocks
  (`title`, `stylesheets`, `body`, `javascripts`, optionally `nowo_ui_content` inside `body`) is wrapped in
  `_legacy_layout.html.twig` (runtime deprecation): the page renders once inside `body`, host CSS / JS keep loading.
- **Template overrides:** a full-file override of `time_track/index.html.twig` / `reports.html.twig` written for 1.x
  keeps rendering: it still receives `active_timer`, `tasks`, `stop_form`, `start_forms` / `entries`, `entry_page`,
  `from`, `to`, and `{% extends nowo_time_track_layout %}` renders inside the UiKit admin layout through the
  `layout.html.twig` shim. New overrides should extend `@!NowoTimeTrackBundle/time_track/<page>.html.twig` and fill
  a data block instead (see [TEMPLATES](TEMPLATES.md)).
- **Markup / CSS hooks:** host CSS or tests targeting the 1.x classes (`.card`, `.flash-success`, `.btn-primary` in
  the bundle pages, `[data-test-layout]`) must move to the UiKit classes / `data-testid="time-track-timer"`,
  `time-track-reports`, `time-track-stop`, `time-track-start-<task id>`.

- **Reports pagination:** the reports page now shows `reports.page_size` entries (default **50**) per page with
  previous / next links; set `nowo_time_track.reports.page_size` (max 500) to change it. A host override of
  `time_track/reports.html.twig` keeps receiving `entries` (now the entries of the current page) and can render
  `entry_page` (`Nowo\TimeTrackBundle\Dto\TimeEntryPage`) for the links.
- **Custom repositories:** a host `TimeEntryRepositoryInterface` implementation keeps working (the page is sliced in
  memory); implement `PaginatedTimeEntryRepositoryInterface` to page in the database.
- **Logging:** records go to the Monolog channel `time_track`; exclude it in your handlers (`channels: ['!time_track']`)
  if you do not want them.
- **Subclasses / manual construction:** the new constructor arguments (clock, logger, reports page size) are optional
  and trailing; code that builds `TimerService`, `ClientAuthService` or the controllers by hand needs no change.

## To 1.7.0

`nowo-tech/form-kit-bundle` (`^2.14`) is now required. Register `Nowo\FormKitBundle\NowoFormKitBundle` in
`config/bundles.php` if Flex did not (the form still renders without it, but the profile is only applied with it).
Templates, field names and ids are unchanged: no override needs an update. To theme the bundle forms, configure
`nowo_form_kit.profiles.time_track` (see [CONFIGURATION](CONFIGURATION.md#formkit-profile-req-twig-005)).

## To 1.6.0

From **1.5.2**:

```bash
composer update nowo-tech/time-track-bundle
```

- **Template override (REQ-TWIG-005):** `time_track/index.html.twig` no longer emits a hand-rolled `<form>` with `_token` / `action` / `task_id` hidden inputs. `TimeTrackManageController::index()` now always passes `stop_form` (FormView) and `start_forms` (FormView per task id). If you override `@NowoTimeTrackBundle/time_track/index.html.twig`, render those with `form_start` / child loop / `form_end` (see [USAGE](USAGE.md)). The posted field names and CSRF id are unchanged, so old overrides keep working, but they should be migrated.
- **Dependency:** the bundle now requires `symfony/form` (`^7.4 || ^8.0`); FrameworkBundle enables `framework.form` automatically when it is installed.

## To 1.5.2

From **1.5.1**:

```bash
composer update nowo-tech/time-track-bundle
```

## From 1.5.0 to 1.5.1

From **1.5.0** — Bridge defaults no longer re-registered by the resource scan

```bash
composer update nowo-tech/time-track-bundle
```

- No breaking changes. No application upgrade steps.

## From 1.4.0 to 1.5.0

From **1.4.0** — security - per-IP login throttle, credential-less CORS with explicit extension origins; full coverage.

```bash
composer update nowo-tech/time-track-bundle
```

- **CORS for browser extensions:** any `chrome-extension://` / `moz-extension://` origin is no longer accepted by default. The bundled Chrome extension calls the API from extension pages with `host_permissions`, which Chrome exempts from CORS, so it keeps working. If another client depends on CORS, add its exact origin (e.g. `chrome-extension://<id>`) to `clients.cors_allowed_origins`, or set `clients.cors_allow_any_extension_origin: true` to restore the previous behaviour.
- `Access-Control-Allow-Credentials` is no longer sent. Clients must authenticate with the `Authorization: Bearer` header (as the bundled clients do), not cookies.
- **Login throttle:** an IP is now also blocked after `max_attempts_per_ip` failed logins across all usernames (default 5 × `max_attempts` = 50 per 15 min). Raise `clients.login_rate_limit.max_attempts_per_ip` for many users behind one NAT.
- `security.manager_can_edit_entries` is deprecated (no bundle route uses it). Remove it from your config; if your own code calls `TeamAccessGuard::canEditEntry()`, move the manager rule to a `nowo_time_track.time_entry.access_check` listener.

## From 1.3.7 to 1.4.0

From **1.3.7** — security - strict report dates, bounded API queries, 409 on concurrent timer start, login timing.

```bash
composer update nowo-tech/time-track-bundle
```

- Report and entries dates must be ATOM or `Y-m-d`; other formats (e.g. "yesterday") fall back to the defaults instead of causing HTTP 500.
- API `limit` above 100 is capped, and date ranges are limited to 366 days.
- A concurrent timer start now returns 409 instead of 500.

## From 1.3.6 to 1.3.7

From **1.3.6** — dependency refresh and repository maintenance.

```bash
composer update nowo-tech/time-track-bundle
```

- No breaking changes. No application upgrade steps.

## From 1.3.5 to 1.3.6

Dependency refresh only. No breaking changes. No application upgrade steps.

```bash
composer update nowo-tech/time-track-bundle
```

## From 1.3.4 to 1.3.5

From **1.3.4** — `access_roles` fail-closed; Doctrine `SortDirection`.

```bash
composer update nowo-tech/time-track-bundle
php bin/console cache:clear
```

- Empty `security.access_roles` denies the manage UI. Set roles / custom checker, or demo-only `allow_unauthenticated`.
- Ensure `doctrine/orm` is `^3.7` (SortDirection).

## From 1.3.2 to 1.3.3

No breaking changes. **No application upgrade steps.**

```bash
composer update nowo-tech/time-track-bundle
```

# Upgrading

This document describes how to upgrade between versions of TimeTrack Bundle.

## 1.2.0 (2026-08-03)

Minor release: REQ-UI-002 manage-UI access checker (`access_roles` / `access_checker` / `allow_unauthenticated`) plus SecurityBundle compile-time guard. Demo no longer path-mounts sibling optional bundles.

### Install / update

```bash
composer require nowo-tech/time-track-bundle:^1.2
php bin/console cache:clear
```

### Behaviour change (manage roles)

| Topic | Before | 1.2.0 |
| --- | --- | --- |
| Default manage gate | `#[IsGranted('ROLE_USER')]` on controller | `TimeTrackAccessCheckerInterface` driven by `security.access_roles` (default `[ROLE_USER]`) |
| Apps without SecurityBundle | Could boot | Boot fails with `LogicException` unless `allow_unauthenticated: true` |

**Demos / trusted local kernels** without SecurityBundle:

```yaml
nowo_time_track:
    security:
        allow_unauthenticated: true   # never in production
```

**Production** (recommended): keep `allow_unauthenticated: false`, ensure SecurityBundle is installed, and grant at least one of `access_roles` (or provide a custom `access_checker`).

### New optional config

```yaml
nowo_time_track:
    security:
        access_roles: [ROLE_USER]
        access_checker: null
        allow_unauthenticated: false
```

### Breaking changes

Apps that load the manage UI without SecurityBundle must either install/configure SecurityBundle or set `allow_unauthenticated: true` for non-production use. Hosts that relied only on firewall `access_control` without matching `access_roles` should align roles.

## 1.1.0 (2026-07-30)

Host layout / CSS stack integration without forking timer pages (REQ-UI-001). Aligned with TaskBoardBundle **1.3.0**.

```bash
composer require nowo-tech/time-track-bundle:^1.1.0
php bin/console cache:clear
```

### Layout integration (REQ-UI-001)

- Timer/report pages now `{% extends nowo_time_track_layout %}` (Twig global from `nowo_time_track.templates.layout`).
- **Hosts:** set `templates.layout` to the project layout (or a one-file bridge). Do not fork `index.html.twig` / `reports.html.twig` only to change chrome.
- Custom Twig overrides that still hard-code `@NowoTimeTrackBundle/layout.html.twig` should switch to the global so config takes effect.
- Styles/scripts blocks on pages call `{{ parent() }}` so host assets remain when using the project layout.

### CSS framework (REQ-UI-001)

- New config `templates.css_framework` (default **`tabler`**) exposed as Twig global `nowo_time_track_css_framework`.
- Accepted values: `bootstrap`, `bootstrap4`, `bootstrap5`, `tabler`, `tailwind`, `foundation`, `custom`, `none`.
- Align with TaskBoardBundle (`templates.css_framework`). Set the same value as your host admin stack; with `custom`, keep Bootstrap/Tabler-compatible class names styled by the host.

```yaml
nowo_time_track:
    templates:
        layout: 'base.html.twig'
        css_framework: tabler   # or bootstrap5 | custom
```

## 1.0.x patch releases

### 1.0.6 (2026-07-29)

- **Security** — Manage UI start/stop POSTs require CSRF token `nowo_time_track_manage`. Update custom Twig overrides of the timer UI to include the token (see demo/`index.html.twig`).
- FrankenPHP banner / demo PHP **8.5**; CI matrix Symfony **7.4 / 8.0 / 8.1** (Symfony **7.0** dropped from CI — package constraints unchanged unless you relied on CI-only coverage).
- Contributors: PHPStan baseline removed; `phpstan-frankenphp` rulesets after `composer install`.

```bash
composer update nowo-tech/time-track-bundle
php bin/console cache:clear
```

### 1.0.5 (2026-07-16)

- **Translations** — New locale files for **de**, **fr**, **it**, **nl**, **pt**. No config changes required; Symfony picks them up when the app locale matches.
- **Repository-only** — Code of Conduct, GitHub CI docs, and git hygiene tooling (REQ-GIT-001). No bundle API, config key, or migration changes.

```bash
composer update nowo-tech/time-track-bundle
```

### 1.0.4 (2026-07-13)

Repository-only: security documentation, coverage reporting script, Cursor rules, and dev lockfile updates. No bundle API, config key, or migration changes for consumers.

```bash
composer update nowo-tech/time-track-bundle
```

### 1.0.3 (2026-07-08)

Repository-only: GitHub Spec Kit scaffolding, baseline specs, and documentation. No bundle API, config key, or migration changes for consumers.

```bash
composer update nowo-tech/time-track-bundle
```

### 1.0.2 (2026-07-07)

Repository-only: demo `reference.php` strict-types alignment. No bundle API, config key, or migration changes.

```bash
composer update nowo-tech/time-track-bundle
```

### 1.0.1 (2026-07-06)

Repository-only: CI Code Style job fix and demo `reference.php` strict-types. No bundle API, config key, or migration changes for consumers.

```bash
composer update nowo-tech/time-track-bundle
```

## 1.0.0 (2026-07-06)

First stable release. No upgrade steps required.

### Requirements

- **PHP** >= 8.2 (Symfony 8.x requires PHP 8.4+).
- **Symfony** ^7.4 || ^8.0.
- **Doctrine ORM** ^2.15 || ^3.0 with `doctrine/doctrine-bundle` ^2.10 || ^3.0.

### Installation checklist

1. Install the package:

   ```bash
   composer require nowo-tech/time-track-bundle
   ```

2. Configure `user_class` and enable clients if you use the extension or desktop agent:

   ```yaml
   # config/packages/nowo_time_track.yaml
   nowo_time_track:
       user_class: App\Entity\User
       clients:
           enabled: true
   ```

3. Run Doctrine migrations (see demo migration `demo/symfony8/migrations/Version20250706120000.php` for reference schema).

4. Add security rules — Bearer auth for `/api/time-track/*`, session auth for `/tools/time-track`:

   ```yaml
   # config/packages/security.yaml
   security:
       access_control:
           - { path: ^/api/time-track, roles: PUBLIC_ACCESS }
           - { path: ^/tools/time-track, roles: ROLE_USER }
   ```

5. **Optional:** wire **TaskBoardBundle** providers — see [TaskBoard integration](TASK-BOARD-INTEGRATION.md).

### Client apps (repository only)

- **Browser extension** — load unpacked from `extension/chrome/`; enable `clients.enabled` and CORS on the host app.
- **Desktop agent** — build from `desktop/` with Tauri 2; default demo API URL is `http://localhost:8024`.

Neither client is part of the Packagist archive (`demo/` and client folders are for development and integration testing).

## To 1.3.5

From **1.3.4** — `access_roles` fail-closed; Doctrine `SortDirection`.

```bash
composer update nowo-tech/time-track-bundle
php bin/console cache:clear
```

- Empty `security.access_roles` denies the manage UI. Set roles / custom checker, or demo-only `allow_unauthenticated`.
- Ensure `doctrine/orm` is `^3.7` (SortDirection).

## To 1.3.4

From **1.3.3** — REQ-CS-008 Igor FrankenPHP worker audit (igor-php require-dev, igor.json, make igor).

```bash
composer update nowo-tech/time-track-bundle
php bin/console cache:clear
```

No breaking changes and no host migration. `TeamAccessGuard` and `ClientAuthService` now reload the user entity (`EntityManager::refresh()`) before role checks, which adds one `SELECT` when an admin-role check runs and one per client API request. Code that instantiates these services or the `DoctrineOrm*Repository` classes manually keeps working: the new `?ManagerRegistry` argument is optional (without it, no refresh or closed-manager reset happens). For `clients.login_rate_limit.cache_pool`, keep using a pool shared by all workers (Redis, filesystem, APCu); an array pool is per worker and unbounded.
- No application upgrade steps for require-dev Igor tooling (REQ-CS-008). Consumers do not pull `igor-php/igor-php` transitively.

## To 1.3.2

No application upgrade steps.

```bash
composer update nowo-tech/time-track-bundle
php bin/console cache:clear
```

## To 1.3.1

No application upgrade steps. **Demos only:** Hot Reload Bundle `^1.4` (FrankenPHP Mercure/`hot_reload`, `dev`/`test`).

```bash
composer update nowo-tech/time-track-bundle
php bin/console cache:clear
```

## To 1.3.0

From **1.2.0** — Adds FormKit and/or UiKit where applicable, Twig Extra (REQ-TWIG-004), and Twig-CS-Fixer. Register TwigExtraBundle, NowoFormKitBundle, and NowoUiKitBundle if Flex did not. See CHANGELOG.

```bash
composer update nowo-tech/time-track-bundle
php bin/console cache:clear
```

### Twig Extra Bundle (REQ-TWIG-004)

Hosts that render this bundle's Twig templates must install:

```bash
composer require twig/extra-bundle twig/string-extra
```

and enable `Twig\Extra\TwigExtraBundle\TwigExtraBundle`. Flex recipes usually register it automatically.

### Twig-CS-Fixer (maintainers)

Package maintainers: `composer twig:lint` / `composer twig:fix` use `.twig-cs-fixer.php` over `src/` (and `templates/` when present).

