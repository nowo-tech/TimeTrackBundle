-- TimeTrackBundle 2.2.0 rollback to 2.1, PostgreSQL 16. The first statements revoke every token and drop every device request of a family other than "time": 2.1 knows no families and would accept them as time tokens on the routes that need no scope (GET me, logout). Run with psql.
\set ON_ERROR_STOP on
BEGIN;
DELETE FROM time_track_client_tokens WHERE scope_family <> 'time';
DELETE FROM time_track_device_codes WHERE scope_family <> 'time';
ALTER TABLE time_track_device_codes DROP COLUMN scope_family, DROP COLUMN device_key, DROP COLUMN link_owner_id;
ALTER TABLE time_track_client_tokens DROP COLUMN scope_family, DROP COLUMN device_key, DROP COLUMN client_name;
COMMIT;
