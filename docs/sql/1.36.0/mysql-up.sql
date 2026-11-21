-- TimeTrackBundle 1.36.0, MySQL 8 / MariaDB 10.6+ (default table prefix). Entries and running timers without a task (entries.allow_without_task): task_id becomes nullable, and running timers get description and scope_key. No row is rewritten. Each statement is one atomic change.
ALTER TABLE time_track_active_timers ADD description LONGTEXT DEFAULT NULL, ADD scope_key VARCHAR(64) DEFAULT NULL, CHANGE task_id task_id VARCHAR(36) DEFAULT NULL;
ALTER TABLE time_track_entries CHANGE task_id task_id VARCHAR(36) DEFAULT NULL;
