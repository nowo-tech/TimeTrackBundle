-- TimeTrackBundle 1.14.0, PostgreSQL 13+ (default table prefix; replace `users` and INT with your user table and PK type). Calendar suggestions (always mapped); the meetings API adds no table.
CREATE TABLE time_track_calendar_connections (id VARCHAR(36) NOT NULL, status VARCHAR(16) NOT NULL, consented_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_sync_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_error_code VARCHAR(32) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, scope_key VARCHAR(64) NOT NULL, provider VARCHAR(16) NOT NULL, mode VARCHAR(8) NOT NULL, credential_ref VARCHAR(128) DEFAULT NULL, consent_version VARCHAR(16) NOT NULL, user_id INT NOT NULL, PRIMARY KEY (id));
CREATE UNIQUE INDEX uniq_time_track_calendar_connections ON time_track_calendar_connections (user_id, scope_key, provider);
CREATE INDEX IDX_12CE44F6A76ED395 ON time_track_calendar_connections (user_id);
CREATE TABLE time_track_calendar_suggestions (id VARCHAR(36) NOT NULL, status VARCHAR(12) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, starts_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ends_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, event_ref CHAR(64) NOT NULL, series_ref CHAR(64) DEFAULT NULL, group_key VARCHAR(48) DEFAULT NULL, task_id VARCHAR(36) NOT NULL, match_kind VARCHAR(8) NOT NULL, entry_id VARCHAR(36) DEFAULT NULL, connection_id VARCHAR(36) NOT NULL, user_id INT NOT NULL, PRIMARY KEY (id));
CREATE INDEX idx_time_track_calendar_suggestions_user ON time_track_calendar_suggestions (user_id, status, starts_at);
CREATE UNIQUE INDEX uniq_time_track_calendar_suggestions_ref ON time_track_calendar_suggestions (connection_id, event_ref);
CREATE INDEX IDX_3C8E3EF7BA364942 ON time_track_calendar_suggestions (entry_id);
CREATE INDEX IDX_3C8E3EF7DD03F01 ON time_track_calendar_suggestions (connection_id);
CREATE INDEX IDX_3C8E3EF7A76ED395 ON time_track_calendar_suggestions (user_id);
CREATE TABLE time_track_calendar_rules (id VARCHAR(36) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, scope_key VARCHAR(64) NOT NULL, pattern VARCHAR(120) NOT NULL, task_id VARCHAR(36) NOT NULL, group_matches BOOLEAN DEFAULT true NOT NULL, user_id INT NOT NULL, PRIMARY KEY (id));
CREATE INDEX idx_time_track_calendar_rules_user ON time_track_calendar_rules (user_id, scope_key);
CREATE INDEX IDX_84F867CAA76ED395 ON time_track_calendar_rules (user_id);
ALTER TABLE time_track_calendar_connections ADD CONSTRAINT FK_12CE44F6A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE;
ALTER TABLE time_track_calendar_suggestions ADD CONSTRAINT FK_3C8E3EF7BA364942 FOREIGN KEY (entry_id) REFERENCES time_track_entries (id) ON DELETE SET NULL NOT DEFERRABLE;
ALTER TABLE time_track_calendar_suggestions ADD CONSTRAINT FK_3C8E3EF7DD03F01 FOREIGN KEY (connection_id) REFERENCES time_track_calendar_connections (id) ON DELETE CASCADE NOT DEFERRABLE;
ALTER TABLE time_track_calendar_suggestions ADD CONSTRAINT FK_3C8E3EF7A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE;
ALTER TABLE time_track_calendar_rules ADD CONSTRAINT FK_84F867CAA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE;
