-- TimeTrackBundle 2.2.0 rollback to 2.1, MySQL 8.4. The first statement revokes every token of a family other than "time": 2.1 knows no families and would accept them as time tokens on the routes that need no scope (GET me, logout).
DELETE FROM time_track_client_tokens WHERE scope_family <> 'time';
ALTER TABLE time_track_device_codes DROP scope_family, DROP device_key;
ALTER TABLE time_track_client_tokens DROP scope_family, DROP device_key, DROP client_name;
