-- TimeTrackBundle 2.1.0 rollback to 2.0, SQLite 3.35+ (DROP COLUMN needs unindexed columns: the indexes go first). Drops the allocation of every entry (never its time). Run with the sqlite3 CLI.
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
