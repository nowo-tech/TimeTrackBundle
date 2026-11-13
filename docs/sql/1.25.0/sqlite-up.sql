-- TimeTrackBundle 1.25.0, SQLite 3.35+ (default table prefix). Two columns on time entries (the channel and the service-account mark, spec 234) and one index. No row is rewritten.
ALTER TABLE time_track_entries ADD COLUMN channel VARCHAR(16) DEFAULT NULL;
ALTER TABLE time_track_entries ADD COLUMN service_account BOOLEAN DEFAULT 0 NOT NULL;
CREATE INDEX idx_time_track_entries_origin ON time_track_entries (service_account, channel, started_at);
