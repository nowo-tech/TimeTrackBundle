-- Rollback to 1.35 (PostgreSQL 13+). One transaction: while a running timer or an entry has no task SET NOT NULL fails and nothing changes, a rollback never deletes tracked time. Decide about those rows first (stop the timers, give the entries a task). The free text of running timers without a task goes with the description column. A runner other than psql skips the backslash line and stops at the first error.
\set ON_ERROR_STOP on
BEGIN;
ALTER TABLE time_track_active_timers ALTER task_id SET NOT NULL;
ALTER TABLE time_track_active_timers DROP description;
ALTER TABLE time_track_active_timers DROP scope_key;
ALTER TABLE time_track_entries ALTER task_id SET NOT NULL;
COMMIT;
