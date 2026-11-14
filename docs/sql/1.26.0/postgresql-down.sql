-- Rollback to 1.25 (PostgreSQL 13+): drops the 1.26 monitoring tables (acknowledgements and upload intents); 1.25 ignores them otherwise. Keep a copy of the acknowledgements first if your organisation needs them as evidence.
ALTER TABLE time_track_monitoring_acknowledgements DROP CONSTRAINT FK_18089BE9A76ED395;
ALTER TABLE time_track_monitoring_upload_intents DROP CONSTRAINT FK_59FDD04DA76ED395;
DROP TABLE time_track_monitoring_acknowledgements;
DROP TABLE time_track_monitoring_upload_intents;
