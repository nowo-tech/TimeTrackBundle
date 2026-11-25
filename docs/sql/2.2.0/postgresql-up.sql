-- TimeTrackBundle 2.2.0, PostgreSQL 16 (default table prefix). Device-flow scope families (Beacon Desktop BD-2): a column with a constant default is metadata-only (no table rewrite); existing rows read "time". The family is compared in PHP only, never in SQL. Run with psql.
\set ON_ERROR_STOP on
BEGIN;
ALTER TABLE time_track_client_tokens ADD scope_family VARCHAR(32) DEFAULT 'time' NOT NULL;
ALTER TABLE time_track_client_tokens ADD device_key VARCHAR(36) DEFAULT NULL;
ALTER TABLE time_track_client_tokens ADD client_name VARCHAR(100) DEFAULT NULL;
ALTER TABLE time_track_device_codes ADD scope_family VARCHAR(32) DEFAULT 'time' NOT NULL;
ALTER TABLE time_track_device_codes ADD device_key VARCHAR(36) DEFAULT NULL;
COMMIT;
