-- TimeTrackBundle 1.36.0, PostgreSQL 13+ (default table prefix). Entries and running timers without a task (entries.allow_without_task): task_id becomes nullable, and running timers get description and scope_key. No row is rewritten. One transaction. A runner other than psql skips the backslash line and stops at the first error.
\set ON_ERROR_STOP on
BEGIN;
ALTER TABLE time_track_active_timers ADD description TEXT DEFAULT NULL;
ALTER TABLE time_track_active_timers ADD scope_key VARCHAR(64) DEFAULT NULL;
ALTER TABLE time_track_active_timers ALTER task_id DROP NOT NULL;
ALTER TABLE time_track_entries ALTER task_id DROP NOT NULL;
COMMIT;
