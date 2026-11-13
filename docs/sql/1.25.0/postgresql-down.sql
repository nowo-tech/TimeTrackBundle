-- TimeTrackBundle 1.25.0 rollback, PostgreSQL 13+ (default table prefix). Drops the channel and the service-account mark of every entry.
DROP INDEX idx_time_track_entries_origin;
ALTER TABLE time_track_entries DROP channel;
ALTER TABLE time_track_entries DROP service_account;
