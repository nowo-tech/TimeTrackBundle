-- Rollback to 1.25 (SQLite 3.35+): drops the 1.26 monitoring tables (acknowledgements and upload intents); 1.25 ignores them otherwise. Keep a copy of the acknowledgements first if your organisation needs them as evidence.
DROP TABLE time_track_monitoring_acknowledgements;
DROP TABLE time_track_monitoring_upload_intents;
