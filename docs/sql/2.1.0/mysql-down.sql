-- TimeTrackBundle 2.1.0 rollback to 2.0, MySQL 8.4 (on MySQL before 8.0.17 replace utf8mb4_0900_bin with utf8mb4_bin). Drops the allocation of every entry (never its time): export it first if you may come back.
DROP INDEX idx_time_track_entries_scope_activity ON time_track_entries;
DROP INDEX idx_time_track_entries_scope_subject ON time_track_entries;
ALTER TABLE time_track_active_timers DROP subject_type, DROP subject_id, DROP activity_type_key;
ALTER TABLE time_track_entries DROP subject_type, DROP subject_id, DROP activity_type_key;
