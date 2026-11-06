-- TimeTrackBundle 1.15.0, SQLite 3.35+ (default table prefix; replace `users` with your user table). SQLite cannot change a column or a foreign key: the table is rebuilt, with foreign keys off so the rebuild touches no other table. Run it outside a transaction.
PRAGMA foreign_keys = OFF;
CREATE TABLE time_track_entries_new (id VARCHAR(36) NOT NULL, created_at DATETIME NOT NULL, external_source VARCHAR(64) DEFAULT NULL, external_id VARCHAR(128) DEFAULT NULL, billable BOOLEAN DEFAULT NULL, description CLOB DEFAULT NULL, scope_key VARCHAR(64) DEFAULT NULL, person_name_snapshot VARCHAR(255) DEFAULT NULL, person_ref VARCHAR(64) DEFAULT NULL, anonymised_at DATETIME DEFAULT NULL, task_id VARCHAR(36) NOT NULL, task_title_snapshot VARCHAR(255) NOT NULL, board_id_snapshot VARCHAR(36) DEFAULT NULL, started_at DATETIME NOT NULL, ended_at DATETIME NOT NULL, duration_seconds INTEGER NOT NULL, source VARCHAR(16) NOT NULL, metadata CLOB DEFAULT NULL, user_id INTEGER DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_7CB77957A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE);
INSERT INTO time_track_entries_new (id, created_at, external_source, external_id, billable, description, scope_key, task_id, task_title_snapshot, board_id_snapshot, started_at, ended_at, duration_seconds, source, metadata, user_id) SELECT id, created_at, external_source, external_id, billable, description, scope_key, task_id, task_title_snapshot, board_id_snapshot, started_at, ended_at, duration_seconds, source, metadata, user_id FROM time_track_entries;
DROP TABLE time_track_entries;
ALTER TABLE time_track_entries_new RENAME TO time_track_entries;
CREATE INDEX time_track_entries_user_idx ON time_track_entries (user_id);
CREATE INDEX time_track_entries_task_idx ON time_track_entries (task_id);
CREATE INDEX time_track_entries_started_idx ON time_track_entries (started_at);
CREATE INDEX time_track_entries_ended_idx ON time_track_entries (ended_at, id);
CREATE INDEX idx_time_track_entries_source ON time_track_entries (source);
CREATE UNIQUE INDEX uniq_time_track_entries_external ON time_track_entries (external_source, external_id);
CREATE INDEX idx_time_track_entries_user_scope_started ON time_track_entries (user_id, scope_key, started_at);
CREATE INDEX idx_time_track_entries_person_ref ON time_track_entries (person_ref);
PRAGMA foreign_keys = ON;
