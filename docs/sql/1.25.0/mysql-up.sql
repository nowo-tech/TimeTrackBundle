-- TimeTrackBundle 1.25.0, MySQL 8 / MariaDB 10.6+ (default table prefix). Two columns on time entries (the channel and the service-account mark, spec 234) and one index. No row is rewritten: existing entries get channel NULL and service_account 0.
ALTER TABLE time_track_entries ADD channel VARCHAR(16) DEFAULT NULL, ADD service_account TINYINT(1) DEFAULT 0 NOT NULL;
CREATE INDEX idx_time_track_entries_origin ON time_track_entries (service_account, channel, started_at);
