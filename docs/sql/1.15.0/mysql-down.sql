-- Rollback to 1.14 (MySQL 8 / MariaDB 10.6+). While an entry has no user the first ALTER is refused and changes nothing: a rollback never deletes tracked time. Decide about those entries first. Strict mode is forced because without it NOT NULL would turn their user into 0.
SET @time_track_sql_mode = @@SESSION.sql_mode;
SET SESSION sql_mode = CONCAT_WS(',', NULLIF(@@SESSION.sql_mode, ''), 'STRICT_ALL_TABLES');
ALTER TABLE time_track_entries DROP FOREIGN KEY FK_7CB77957A76ED395, DROP INDEX idx_time_track_entries_person_ref, DROP person_name_snapshot, DROP person_ref, DROP anonymised_at, CHANGE user_id user_id INT NOT NULL;
ALTER TABLE time_track_entries ADD CONSTRAINT FK_7CB77957A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE;
DROP INDEX idx_time_track_timesheets_owner_ref ON time_track_timesheets;
SET SESSION sql_mode = @time_track_sql_mode;
