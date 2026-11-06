-- Rollback to 1.14 (MySQL 8 / MariaDB 10.6+): the CHANGE fails while an entry has no user, on purpose: rolling back never deletes tracked time. Decide about those entries first.
ALTER TABLE time_track_entries DROP FOREIGN KEY FK_7CB77957A76ED395;
DROP INDEX idx_time_track_entries_person_ref ON time_track_entries;
ALTER TABLE time_track_entries DROP person_name_snapshot, DROP person_ref, DROP anonymised_at, CHANGE user_id user_id INT NOT NULL;
ALTER TABLE time_track_entries ADD CONSTRAINT FK_7CB77957A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE;
