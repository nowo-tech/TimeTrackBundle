-- TimeTrackBundle 1.13.0, PostgreSQL 13+ (default table prefix; replace `users` and INT with your user table and PK type).
ALTER TABLE time_track_entries ADD scope_key VARCHAR(64) DEFAULT NULL;
CREATE INDEX idx_time_track_entries_user_scope_started ON time_track_entries (user_id, scope_key, started_at);
CREATE TABLE time_track_timesheets (id VARCHAR(36) NOT NULL, period_kind VARCHAR(8) NOT NULL, period_start DATE NOT NULL, period_end DATE NOT NULL, status VARCHAR(16) NOT NULL, locked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, lock_reason VARCHAR(16) DEFAULT NULL, submitted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, submitted_by_ref VARCHAR(64) DEFAULT NULL, decided_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, decided_by_ref VARCHAR(64) DEFAULT NULL, decision_comment VARCHAR(1000) DEFAULT NULL, totals JSON DEFAULT NULL, entries_hash CHAR(64) DEFAULT NULL, head_hash CHAR(64) DEFAULT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_ref VARCHAR(64) NOT NULL, scope_key VARCHAR(64) NOT NULL, timezone VARCHAR(64) NOT NULL, user_id INT DEFAULT NULL, PRIMARY KEY (id));
CREATE INDEX idx_time_track_timesheets_queue ON time_track_timesheets (scope_key, status, period_start);
CREATE INDEX idx_time_track_timesheets_lock ON time_track_timesheets (user_id, scope_key, locked_at, period_end);
CREATE UNIQUE INDEX uniq_time_track_timesheets_period ON time_track_timesheets (user_id, scope_key, period_kind, period_start);
CREATE INDEX IDX_B3267224A76ED395 ON time_track_timesheets (user_id);
CREATE TABLE time_track_timesheet_events (id VARCHAR(36) NOT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, hash CHAR(64) NOT NULL, seq INT NOT NULL, action VARCHAR(24) NOT NULL, from_status VARCHAR(16) NOT NULL, to_status VARCHAR(16) NOT NULL, actor_ref VARCHAR(64) DEFAULT NULL, actor_role VARCHAR(16) NOT NULL, client VARCHAR(16) NOT NULL, prev_hash CHAR(64) DEFAULT NULL, comment VARCHAR(1000) DEFAULT NULL, entries_hash CHAR(64) DEFAULT NULL, totals JSON DEFAULT NULL, timesheet_id VARCHAR(36) NOT NULL, PRIMARY KEY (id));
CREATE UNIQUE INDEX uniq_time_track_timesheet_events_seq ON time_track_timesheet_events (timesheet_id, seq);
CREATE INDEX IDX_6F44086ABDD46BE ON time_track_timesheet_events (timesheet_id);
CREATE TABLE time_track_balance_ledger (id VARCHAR(36) NOT NULL, effective_date DATE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, hash CHAR(64) NOT NULL, owner_ref VARCHAR(64) NOT NULL, scope_key VARCHAR(64) NOT NULL, kind VARCHAR(24) NOT NULL, seconds INT NOT NULL, prev_hash CHAR(64) DEFAULT NULL, reason VARCHAR(500) DEFAULT NULL, reverses_id VARCHAR(36) DEFAULT NULL, actor_ref VARCHAR(64) DEFAULT NULL, timesheet_id VARCHAR(36) DEFAULT NULL, user_id INT DEFAULT NULL, PRIMARY KEY (id));
CREATE INDEX idx_time_track_balance_ledger_user ON time_track_balance_ledger (user_id, scope_key, effective_date);
CREATE INDEX idx_time_track_balance_ledger_timesheet ON time_track_balance_ledger (timesheet_id);
CREATE INDEX IDX_24B61F4DA76ED395 ON time_track_balance_ledger (user_id);
ALTER TABLE time_track_timesheets ADD CONSTRAINT FK_B3267224A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE;
ALTER TABLE time_track_timesheet_events ADD CONSTRAINT FK_6F44086ABDD46BE FOREIGN KEY (timesheet_id) REFERENCES time_track_timesheets (id) ON DELETE CASCADE NOT DEFERRABLE;
ALTER TABLE time_track_balance_ledger ADD CONSTRAINT FK_24B61F4DABDD46BE FOREIGN KEY (timesheet_id) REFERENCES time_track_timesheets (id) ON DELETE SET NULL NOT DEFERRABLE;
ALTER TABLE time_track_balance_ledger ADD CONSTRAINT FK_24B61F4DA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE;
