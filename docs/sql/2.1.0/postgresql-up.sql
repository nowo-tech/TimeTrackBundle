-- TimeTrackBundle 2.1.0, PostgreSQL 16 (default table prefix). Subject and activity type (Beacon spec 256). Adding a nullable column without a default is metadata-only; the default deterministic collation compares exactly. The indexes are built CONCURRENTLY, outside the transaction (a Doctrine Migrations host that wants one transaction drops CONCURRENTLY and accepts the write lock of the build). Run with psql.
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
