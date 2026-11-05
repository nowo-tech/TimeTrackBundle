-- Rollback to 1.12 (SQLite 3.35+): drops the 1.13 tables and the entry scope; 1.12 ignores them otherwise.
DROP TABLE time_track_balance_ledger;
DROP TABLE time_track_timesheet_events;
DROP TABLE time_track_timesheets;
DROP INDEX idx_time_track_entries_user_scope_started;
ALTER TABLE time_track_entries DROP COLUMN scope_key;
