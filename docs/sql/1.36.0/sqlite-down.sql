-- Rollback to 1.35 (SQLite 3.35+). While a running timer or an entry has no task the INSERT fails and the transaction ends with nothing changed: a rollback never deletes tracked time. Decide about those rows first (stop the timers, give the entries a task). The free text of running timers without a task goes with the description column.
.bail on
PRAGMA foreign_keys = OFF;
BEGIN;
CREATE TABLE time_track_active_timers_old (id VARCHAR(36) NOT NULL, started_at DATETIME NOT NULL, last_heartbeat_at DATETIME NOT NULL, task_id VARCHAR(36) NOT NULL, task_title_snapshot VARCHAR(255) NOT NULL, board_id_snapshot VARCHAR(36) DEFAULT NULL, client_type VARCHAR(16) NOT NULL, metadata CLOB DEFAULT NULL, user_id INTEGER NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_243D7466A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE);
INSERT INTO time_track_active_timers_old (id, started_at, last_heartbeat_at, task_id, task_title_snapshot, board_id_snapshot, client_type, metadata, user_id) SELECT id, started_at, last_heartbeat_at, task_id, task_title_snapshot, board_id_snapshot, client_type, metadata, user_id FROM time_track_active_timers;
DROP TABLE time_track_active_timers;
ALTER TABLE time_track_active_timers_old RENAME TO time_track_active_timers;
CREATE UNIQUE INDEX time_track_active_timers_user_unique ON time_track_active_timers (user_id);
CREATE TABLE time_track_entries_old (id VARCHAR(36) NOT NULL, created_at DATETIME NOT NULL, external_source VARCHAR(64) DEFAULT NULL, external_id VARCHAR(128) DEFAULT NULL, billable BOOLEAN DEFAULT NULL, description CLOB DEFAULT NULL, scope_key VARCHAR(64) DEFAULT NULL, person_name_snapshot VARCHAR(255) DEFAULT NULL, person_ref VARCHAR(69) DEFAULT NULL, anonymised_at DATETIME DEFAULT NULL, channel VARCHAR(16) DEFAULT NULL, service_account BOOLEAN DEFAULT 0 NOT NULL, task_id VARCHAR(36) NOT NULL, task_title_snapshot VARCHAR(255) NOT NULL, board_id_snapshot VARCHAR(36) DEFAULT NULL, started_at DATETIME NOT NULL, ended_at DATETIME NOT NULL, duration_seconds INTEGER NOT NULL, source VARCHAR(16) NOT NULL, metadata CLOB DEFAULT NULL, user_id INTEGER DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_7CB77957A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE);
INSERT INTO time_track_entries_old (id, created_at, external_source, external_id, billable, description, scope_key, person_name_snapshot, person_ref, anonymised_at, channel, service_account, task_id, task_title_snapshot, board_id_snapshot, started_at, ended_at, duration_seconds, source, metadata, user_id) SELECT id, created_at, external_source, external_id, billable, description, scope_key, person_name_snapshot, person_ref, anonymised_at, channel, service_account, task_id, task_title_snapshot, board_id_snapshot, started_at, ended_at, duration_seconds, source, metadata, user_id FROM time_track_entries;
DROP TABLE time_track_entries;
ALTER TABLE time_track_entries_old RENAME TO time_track_entries;
CREATE INDEX time_track_entries_user_idx ON time_track_entries (user_id);
CREATE INDEX time_track_entries_task_idx ON time_track_entries (task_id);
CREATE INDEX time_track_entries_started_idx ON time_track_entries (started_at);
CREATE INDEX time_track_entries_ended_idx ON time_track_entries (ended_at, id);
CREATE INDEX idx_time_track_entries_source ON time_track_entries (source);
CREATE INDEX idx_time_track_entries_user_scope_started ON time_track_entries (user_id, scope_key, started_at);
CREATE INDEX idx_time_track_entries_person_ref ON time_track_entries (person_ref);
CREATE INDEX idx_time_track_entries_origin ON time_track_entries (service_account, channel, started_at);
CREATE UNIQUE INDEX uniq_time_track_entries_external ON time_track_entries (external_source, external_id);
COMMIT;
PRAGMA foreign_keys = ON;
