-- TimeTrackBundle 2.2.0 rollback to 2.1, SQLite 3.35+. The first statement revokes every token of a family other than "time": 2.1 knows no families and would accept them as time tokens on the routes that need no scope (GET me, logout). Run with the sqlite3 CLI.
.bail on
BEGIN;
DELETE FROM time_track_client_tokens WHERE scope_family <> 'time';
ALTER TABLE time_track_device_codes DROP COLUMN device_key;
ALTER TABLE time_track_device_codes DROP COLUMN scope_family;
ALTER TABLE time_track_client_tokens DROP COLUMN client_name;
ALTER TABLE time_track_client_tokens DROP COLUMN device_key;
ALTER TABLE time_track_client_tokens DROP COLUMN scope_family;
COMMIT;
