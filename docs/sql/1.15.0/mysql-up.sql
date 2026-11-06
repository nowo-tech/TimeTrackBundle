-- TimeTrackBundle 1.15.0, MySQL 8 / MariaDB 10.6+ (default table prefix; replace `users` and INT with your user table and PK type, and FK_7CB77957A76ED395 with the name of your foreign key if it differs). Entries with no user; no row is rewritten.
ALTER TABLE time_track_entries DROP FOREIGN KEY FK_7CB77957A76ED395;
ALTER TABLE time_track_entries ADD person_name_snapshot VARCHAR(255) DEFAULT NULL, ADD person_ref VARCHAR(64) DEFAULT NULL, ADD anonymised_at DATETIME DEFAULT NULL, CHANGE user_id user_id INT DEFAULT NULL;
CREATE INDEX idx_time_track_entries_person_ref ON time_track_entries (person_ref);
ALTER TABLE time_track_entries ADD CONSTRAINT FK_7CB77957A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL;
