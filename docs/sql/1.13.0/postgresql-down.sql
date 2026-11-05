-- Rollback to 1.12 (PostgreSQL): drops the 1.13 tables and the entry scope; 1.12 ignores them otherwise.
ALTER TABLE time_track_balance_ledger DROP CONSTRAINT FK_24B61F4DABDD46BE;
ALTER TABLE time_track_balance_ledger DROP CONSTRAINT FK_24B61F4DA76ED395;
ALTER TABLE time_track_timesheet_events DROP CONSTRAINT FK_6F44086ABDD46BE;
ALTER TABLE time_track_timesheets DROP CONSTRAINT FK_B3267224A76ED395;
DROP TABLE time_track_balance_ledger;
DROP TABLE time_track_timesheet_events;
DROP TABLE time_track_timesheets;
DROP INDEX idx_time_track_entries_user_scope_started;
ALTER TABLE time_track_entries DROP scope_key;
