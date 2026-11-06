-- Rollback to 1.14 (PostgreSQL 13+). One transaction: while an entry has no user SET NOT NULL fails and nothing changes, a rollback never deletes tracked time. Decide about those entries first. A runner other than psql skips the backslash line and stops at the first error.
\set ON_ERROR_STOP on
BEGIN;
ALTER TABLE time_track_entries ALTER user_id SET NOT NULL;
ALTER TABLE time_track_entries DROP CONSTRAINT FK_7CB77957A76ED395;
DROP INDEX idx_time_track_entries_person_ref;
ALTER TABLE time_track_entries DROP person_name_snapshot;
ALTER TABLE time_track_entries DROP person_ref;
ALTER TABLE time_track_entries DROP anonymised_at;
ALTER TABLE time_track_entries ADD CONSTRAINT FK_7CB77957A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE;
DROP INDEX idx_time_track_timesheets_owner_ref;
COMMIT;
