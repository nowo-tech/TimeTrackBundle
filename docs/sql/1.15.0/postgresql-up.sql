-- TimeTrackBundle 1.15.0, PostgreSQL 13+ (default table prefix; replace `users` with your user table, and fk_7cb77957a76ed395 with the name of your foreign key if it differs). Entries with no user; no row is rewritten.
ALTER TABLE time_track_entries DROP CONSTRAINT FK_7CB77957A76ED395;
ALTER TABLE time_track_entries ADD person_name_snapshot VARCHAR(255) DEFAULT NULL;
ALTER TABLE time_track_entries ADD person_ref VARCHAR(64) DEFAULT NULL;
ALTER TABLE time_track_entries ADD anonymised_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL;
ALTER TABLE time_track_entries ALTER user_id DROP NOT NULL;
CREATE INDEX idx_time_track_entries_person_ref ON time_track_entries (person_ref);
ALTER TABLE time_track_entries ADD CONSTRAINT FK_7CB77957A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE;
