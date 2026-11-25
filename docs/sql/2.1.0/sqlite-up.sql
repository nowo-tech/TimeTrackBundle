-- TimeTrackBundle 2.1.0, SQLite 3.35+ (default table prefix). Subject and activity type (Beacon spec 256): ADD COLUMN of a nullable column needs no table rebuild; one column per statement. SQLite compares = with BINARY by default, which is exact. Run with the sqlite3 CLI.
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
