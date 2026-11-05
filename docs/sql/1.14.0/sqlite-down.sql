-- Rollback to 1.13 (SQLite 3.35+): drops the 1.14 calendar tables; 1.13 ignores them otherwise.
DROP TABLE time_track_calendar_suggestions;
DROP TABLE time_track_calendar_rules;
DROP TABLE time_track_calendar_connections;
