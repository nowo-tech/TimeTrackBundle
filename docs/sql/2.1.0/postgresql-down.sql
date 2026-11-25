-- TimeTrackBundle 2.1.0 rollback to 2.0, PostgreSQL 16. Drops the allocation of every entry (never its time): export it first if you may come back. Run with psql.
\set ON_ERROR_STOP on
DROP INDEX CONCURRENTLY IF EXISTS idx_time_track_entries_scope_activity;
DROP INDEX CONCURRENTLY IF EXISTS idx_time_track_entries_scope_subject;
BEGIN;
ALTER TABLE time_track_active_timers DROP COLUMN subject_type, DROP COLUMN subject_id, DROP COLUMN activity_type_key;
ALTER TABLE time_track_entries DROP COLUMN subject_type, DROP COLUMN subject_id, DROP COLUMN activity_type_key;
COMMIT;
