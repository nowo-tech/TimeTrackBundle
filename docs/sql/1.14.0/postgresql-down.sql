-- Rollback to 1.13 (PostgreSQL): drops the 1.14 calendar tables; 1.13 ignores them otherwise.
ALTER TABLE time_track_calendar_suggestions DROP CONSTRAINT FK_3C8E3EF7BA364942;
ALTER TABLE time_track_calendar_suggestions DROP CONSTRAINT FK_3C8E3EF7DD03F01;
ALTER TABLE time_track_calendar_suggestions DROP CONSTRAINT FK_3C8E3EF7A76ED395;
ALTER TABLE time_track_calendar_rules DROP CONSTRAINT FK_84F867CAA76ED395;
ALTER TABLE time_track_calendar_connections DROP CONSTRAINT FK_12CE44F6A76ED395;
DROP TABLE time_track_calendar_suggestions;
DROP TABLE time_track_calendar_rules;
DROP TABLE time_track_calendar_connections;
