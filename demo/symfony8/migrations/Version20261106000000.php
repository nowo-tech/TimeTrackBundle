<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TimeTrackBundle 1.13 working time and timesheets (reference migration for hosts, MySQL / MariaDB; the same statements
 * as docs/sql/1.13.0/mysql-up.sql): the nullable entry column `scope_key` with its index, and the always-mapped tables
 * `time_track_timesheets`, `time_track_timesheet_events` and `time_track_balance_ledger` (empty until timesheets are
 * enabled). Additive; no data is rewritten (the scope back-fill is `nowo:time-track:entries:backfill-scope`).
 */
final class Version20261106000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TimeTrackBundle 1.13: entry scope_key, timesheets, timesheet audit trail and balance ledger';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE time_track_entries ADD scope_key VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_time_track_entries_user_scope_started ON time_track_entries (user_id, scope_key, started_at)');
        $this->addSql('CREATE TABLE time_track_timesheets (id VARCHAR(36) NOT NULL, period_kind VARCHAR(8) NOT NULL, period_start DATE NOT NULL, period_end DATE NOT NULL, status VARCHAR(16) NOT NULL, locked_at DATETIME DEFAULT NULL, lock_reason VARCHAR(16) DEFAULT NULL, submitted_at DATETIME DEFAULT NULL, submitted_by_ref VARCHAR(64) DEFAULT NULL, decided_at DATETIME DEFAULT NULL, decided_by_ref VARCHAR(64) DEFAULT NULL, decision_comment VARCHAR(1000) DEFAULT NULL, totals JSON DEFAULT NULL, entries_hash CHAR(64) DEFAULT NULL, head_hash CHAR(64) DEFAULT NULL, version INT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, owner_ref VARCHAR(64) NOT NULL, scope_key VARCHAR(64) NOT NULL, timezone VARCHAR(64) NOT NULL, user_id INT DEFAULT NULL, INDEX idx_time_track_timesheets_queue (scope_key, status, period_start), INDEX idx_time_track_timesheets_lock (user_id, scope_key, locked_at, period_end), UNIQUE INDEX uniq_time_track_timesheets_period (user_id, scope_key, period_kind, period_start), INDEX IDX_B3267224A76ED395 (user_id), PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE time_track_timesheet_events (id VARCHAR(36) NOT NULL, occurred_at DATETIME NOT NULL, hash CHAR(64) NOT NULL, seq INT NOT NULL, action VARCHAR(24) NOT NULL, from_status VARCHAR(16) NOT NULL, to_status VARCHAR(16) NOT NULL, actor_ref VARCHAR(64) DEFAULT NULL, actor_role VARCHAR(16) NOT NULL, client VARCHAR(16) NOT NULL, prev_hash CHAR(64) DEFAULT NULL, comment VARCHAR(1000) DEFAULT NULL, entries_hash CHAR(64) DEFAULT NULL, totals JSON DEFAULT NULL, timesheet_id VARCHAR(36) NOT NULL, UNIQUE INDEX uniq_time_track_timesheet_events_seq (timesheet_id, seq), INDEX IDX_6F44086ABDD46BE (timesheet_id), PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE time_track_balance_ledger (id VARCHAR(36) NOT NULL, effective_date DATE NOT NULL, created_at DATETIME NOT NULL, hash CHAR(64) NOT NULL, owner_ref VARCHAR(64) NOT NULL, scope_key VARCHAR(64) NOT NULL, kind VARCHAR(24) NOT NULL, seconds INT NOT NULL, prev_hash CHAR(64) DEFAULT NULL, reason VARCHAR(500) DEFAULT NULL, reverses_id VARCHAR(36) DEFAULT NULL, actor_ref VARCHAR(64) DEFAULT NULL, timesheet_id VARCHAR(36) DEFAULT NULL, user_id INT DEFAULT NULL, INDEX idx_time_track_balance_ledger_user (user_id, scope_key, effective_date), INDEX idx_time_track_balance_ledger_timesheet (timesheet_id), INDEX IDX_24B61F4DA76ED395 (user_id), PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE time_track_timesheets ADD CONSTRAINT FK_B3267224A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE time_track_timesheet_events ADD CONSTRAINT FK_6F44086ABDD46BE FOREIGN KEY (timesheet_id) REFERENCES time_track_timesheets (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE time_track_balance_ledger ADD CONSTRAINT FK_24B61F4DABDD46BE FOREIGN KEY (timesheet_id) REFERENCES time_track_timesheets (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE time_track_balance_ledger ADD CONSTRAINT FK_24B61F4DA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE time_track_balance_ledger DROP FOREIGN KEY FK_24B61F4DABDD46BE');
        $this->addSql('ALTER TABLE time_track_balance_ledger DROP FOREIGN KEY FK_24B61F4DA76ED395');
        $this->addSql('ALTER TABLE time_track_timesheet_events DROP FOREIGN KEY FK_6F44086ABDD46BE');
        $this->addSql('ALTER TABLE time_track_timesheets DROP FOREIGN KEY FK_B3267224A76ED395');
        $this->addSql('DROP TABLE time_track_balance_ledger');
        $this->addSql('DROP TABLE time_track_timesheet_events');
        $this->addSql('DROP TABLE time_track_timesheets');
        $this->addSql('DROP INDEX idx_time_track_entries_user_scope_started ON time_track_entries');
        $this->addSql('ALTER TABLE time_track_entries DROP scope_key');
    }
}
