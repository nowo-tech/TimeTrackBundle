<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TimeTrackBundle 1.14 calendar suggestions (reference migration for hosts, MySQL / MariaDB; the same statements as
 * docs/sql/1.14.0/mysql-up.sql): the always-mapped tables `time_track_calendar_connections`,
 * `time_track_calendar_suggestions` and `time_track_calendar_rules` (empty until calendar suggestions are enabled).
 * Additive; the meetings API (1.14) adds no table.
 */
final class Version20261107000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TimeTrackBundle 1.14: calendar connections, suggestions and rules';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE time_track_calendar_connections (id VARCHAR(36) NOT NULL, status VARCHAR(16) NOT NULL, consented_at DATETIME NOT NULL, last_sync_at DATETIME DEFAULT NULL, last_error_code VARCHAR(32) DEFAULT NULL, last_attempt_at DATETIME DEFAULT NULL, next_attempt_at DATETIME DEFAULT NULL, failure_count INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, scope_key VARCHAR(64) NOT NULL, provider VARCHAR(16) NOT NULL, mode VARCHAR(8) NOT NULL, credential_ref VARCHAR(128) DEFAULT NULL, consent_version VARCHAR(16) NOT NULL, user_id INT NOT NULL, INDEX idx_time_track_calendar_connections_due (mode, status, next_attempt_at), UNIQUE INDEX uniq_time_track_calendar_connections (user_id, scope_key, provider), INDEX IDX_12CE44F6A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE time_track_calendar_suggestions (id VARCHAR(36) NOT NULL, status VARCHAR(12) NOT NULL, created_at DATETIME NOT NULL, starts_at DATETIME NOT NULL, ends_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, event_ref CHAR(64) NOT NULL, series_ref CHAR(64) DEFAULT NULL, group_key VARCHAR(48) DEFAULT NULL, task_id VARCHAR(36) NOT NULL, match_kind VARCHAR(8) NOT NULL, entry_id VARCHAR(36) DEFAULT NULL, connection_id VARCHAR(36) NOT NULL, user_id INT NOT NULL, INDEX idx_time_track_calendar_suggestions_user (user_id, status, starts_at), UNIQUE INDEX uniq_time_track_calendar_suggestions_ref (connection_id, event_ref), INDEX IDX_3C8E3EF7BA364942 (entry_id), INDEX IDX_3C8E3EF7DD03F01 (connection_id), INDEX IDX_3C8E3EF7A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE time_track_calendar_rules (id VARCHAR(36) NOT NULL, created_at DATETIME NOT NULL, scope_key VARCHAR(64) NOT NULL, pattern VARCHAR(120) NOT NULL, task_id VARCHAR(36) NOT NULL, group_matches TINYINT DEFAULT 1 NOT NULL, user_id INT NOT NULL, INDEX idx_time_track_calendar_rules_user (user_id, scope_key), INDEX IDX_84F867CAA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE time_track_calendar_connections ADD CONSTRAINT FK_12CE44F6A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE time_track_calendar_suggestions ADD CONSTRAINT FK_3C8E3EF7BA364942 FOREIGN KEY (entry_id) REFERENCES time_track_entries (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE time_track_calendar_suggestions ADD CONSTRAINT FK_3C8E3EF7DD03F01 FOREIGN KEY (connection_id) REFERENCES time_track_calendar_connections (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE time_track_calendar_suggestions ADD CONSTRAINT FK_3C8E3EF7A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE time_track_calendar_rules ADD CONSTRAINT FK_84F867CAA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE time_track_calendar_suggestions DROP FOREIGN KEY FK_3C8E3EF7BA364942');
        $this->addSql('ALTER TABLE time_track_calendar_suggestions DROP FOREIGN KEY FK_3C8E3EF7DD03F01');
        $this->addSql('ALTER TABLE time_track_calendar_suggestions DROP FOREIGN KEY FK_3C8E3EF7A76ED395');
        $this->addSql('ALTER TABLE time_track_calendar_rules DROP FOREIGN KEY FK_84F867CAA76ED395');
        $this->addSql('ALTER TABLE time_track_calendar_connections DROP FOREIGN KEY FK_12CE44F6A76ED395');
        $this->addSql('DROP TABLE time_track_calendar_suggestions');
        $this->addSql('DROP TABLE time_track_calendar_rules');
        $this->addSql('DROP TABLE time_track_calendar_connections');
    }
}
