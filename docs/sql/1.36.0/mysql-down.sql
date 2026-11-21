-- Rollback to 1.35 (MySQL 8 / MariaDB 10.6+). While an entry has no task the first ALTER is refused and changes nothing, and while a running timer has none the second one is (the entries then are back to 1.35, which is safe; apply the up script again to resume): a rollback never deletes tracked time. Decide about those rows first (stop the timers, give the entries a task). Strict mode is forced because without it NOT NULL would turn a missing task into ''. The free text of running timers without a task goes with the description column.
SET @time_track_sql_mode = @@SESSION.sql_mode;
SET SESSION sql_mode = CONCAT_WS(',', NULLIF(@@SESSION.sql_mode, ''), 'STRICT_ALL_TABLES');
ALTER TABLE time_track_entries CHANGE task_id task_id VARCHAR(36) NOT NULL;
ALTER TABLE time_track_active_timers DROP description, DROP scope_key, CHANGE task_id task_id VARCHAR(36) NOT NULL;
SET SESSION sql_mode = @time_track_sql_mode;
