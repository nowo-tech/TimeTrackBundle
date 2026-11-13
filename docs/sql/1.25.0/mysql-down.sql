-- TimeTrackBundle 1.25.0 rollback, MySQL 8 / MariaDB 10.6+ (default table prefix). Drops the channel and the service-account mark of every entry.
DROP INDEX idx_time_track_entries_origin ON time_track_entries;
ALTER TABLE time_track_entries DROP channel, DROP service_account;
