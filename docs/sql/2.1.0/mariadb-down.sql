-- TimeTrackBundle 2.1.0 rollback to 2.0, MariaDB 10.6+ / 11.4 (never the folding default utf8mb4_uca1400_ai_ci). Drops the allocation of every entry (never its time): export it first if you may come back.
DROP INDEX idx_time_track_entries_scope_activity ON time_track_entries;
DROP INDEX idx_time_track_entries_scope_subject ON time_track_entries;
ALTER TABLE time_track_active_timers DROP subject_type, DROP subject_id, DROP activity_type_key;
ALTER TABLE time_track_entries DROP subject_type, DROP subject_id, DROP activity_type_key;
