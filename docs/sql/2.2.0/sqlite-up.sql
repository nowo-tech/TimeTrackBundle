-- TimeTrackBundle 2.2.0, SQLite 3.35+ (default table prefix). Device-flow scope families (Beacon Desktop BD-2): ADD COLUMN with a constant default needs no table rebuild; one column per statement; existing rows read "time". Run with the sqlite3 CLI.
.bail on
BEGIN;
ALTER TABLE time_track_client_tokens ADD COLUMN scope_family VARCHAR(32) DEFAULT 'time' NOT NULL;
ALTER TABLE time_track_client_tokens ADD COLUMN device_key VARCHAR(36) DEFAULT NULL;
ALTER TABLE time_track_client_tokens ADD COLUMN client_name VARCHAR(100) DEFAULT NULL;
ALTER TABLE time_track_device_codes ADD COLUMN scope_family VARCHAR(32) DEFAULT 'time' NOT NULL;
ALTER TABLE time_track_device_codes ADD COLUMN device_key VARCHAR(36) DEFAULT NULL;
ALTER TABLE time_track_device_codes ADD COLUMN link_owner_id VARCHAR(191) DEFAULT NULL;
COMMIT;
