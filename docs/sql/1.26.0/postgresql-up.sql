-- TimeTrackBundle 1.26.0, PostgreSQL 13+ (default table prefix; replace `users` and INT with your user table and PK type). Activity monitoring (always mapped, empty while monitoring is off): the default acknowledgement store and the screenshot upload intents. No existing table changes.
CREATE TABLE time_track_monitoring_acknowledgements (id VARCHAR(36) NOT NULL, acknowledged_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, policy_version INT NOT NULL, client_type VARCHAR(16) NOT NULL, notice_sha256 CHAR(64) NOT NULL, user_id INT NOT NULL, PRIMARY KEY (id));
CREATE UNIQUE INDEX uniq_time_track_monitoring_ack_version ON time_track_monitoring_acknowledgements (user_id, policy_version);
CREATE INDEX IDX_18089BE9A76ED395 ON time_track_monitoring_acknowledgements (user_id);
CREATE TABLE time_track_monitoring_upload_intents (id VARCHAR(36) NOT NULL, status VARCHAR(16) NOT NULL, signed_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, screenshot_id VARCHAR(64) DEFAULT NULL, slot TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, taken_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, idempotency_hash CHAR(64) NOT NULL, body_hash CHAR(64) NOT NULL, entry_id VARCHAR(36) NOT NULL, policy_version INT NOT NULL, blurred BOOLEAN NOT NULL, mime VARCHAR(16) NOT NULL, bytes INT NOT NULL, width INT NOT NULL, height INT NOT NULL, sha256 CHAR(64) NOT NULL, user_id INT NOT NULL, PRIMARY KEY (id));
CREATE INDEX idx_time_track_monitoring_intent_slot ON time_track_monitoring_upload_intents (user_id, slot);
CREATE INDEX idx_time_track_monitoring_intent_created ON time_track_monitoring_upload_intents (created_at);
CREATE UNIQUE INDEX uniq_time_track_monitoring_intent_key ON time_track_monitoring_upload_intents (user_id, idempotency_hash);
CREATE INDEX IDX_59FDD04DA76ED395 ON time_track_monitoring_upload_intents (user_id);
ALTER TABLE time_track_monitoring_acknowledgements ADD CONSTRAINT FK_18089BE9A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE;
ALTER TABLE time_track_monitoring_upload_intents ADD CONSTRAINT FK_59FDD04DA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE;
