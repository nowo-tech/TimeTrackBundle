<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TimeTrackBundle 1.26 activity monitoring tables (reference migration for hosts, MySQL / MariaDB; the same statements
 * as docs/sql/1.26.0/mysql-up.sql): the default acknowledgement store and the screenshot upload intents. Always mapped,
 * empty while monitoring is off. No existing table changes.
 */
final class Version20261114000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TimeTrackBundle 1.26: monitoring acknowledgements and upload intents';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE time_track_monitoring_acknowledgements (id VARCHAR(36) NOT NULL, acknowledged_at DATETIME NOT NULL, policy_version INT NOT NULL, client_type VARCHAR(16) NOT NULL, notice_sha256 CHAR(64) NOT NULL, user_id INT NOT NULL, UNIQUE INDEX uniq_time_track_monitoring_ack_version (user_id, policy_version), INDEX IDX_18089BE9A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE time_track_monitoring_upload_intents (id VARCHAR(36) NOT NULL, status VARCHAR(16) NOT NULL, signed_until DATETIME DEFAULT NULL, screenshot_id VARCHAR(64) DEFAULT NULL, slot DATETIME NOT NULL, taken_at DATETIME NOT NULL, created_at DATETIME NOT NULL, idempotency_hash CHAR(64) NOT NULL, body_hash CHAR(64) NOT NULL, entry_id VARCHAR(36) NOT NULL, policy_version INT NOT NULL, blurred TINYINT(1) NOT NULL, mime VARCHAR(16) NOT NULL, bytes INT NOT NULL, width INT NOT NULL, height INT NOT NULL, sha256 CHAR(64) NOT NULL, user_id INT NOT NULL, INDEX idx_time_track_monitoring_intent_slot (user_id, slot), INDEX idx_time_track_monitoring_intent_created (created_at), UNIQUE INDEX uniq_time_track_monitoring_intent_key (user_id, idempotency_hash), INDEX IDX_59FDD04DA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE time_track_monitoring_acknowledgements ADD CONSTRAINT FK_18089BE9A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE time_track_monitoring_upload_intents ADD CONSTRAINT FK_59FDD04DA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE time_track_monitoring_acknowledgements DROP FOREIGN KEY FK_18089BE9A76ED395');
        $this->addSql('ALTER TABLE time_track_monitoring_upload_intents DROP FOREIGN KEY FK_59FDD04DA76ED395');
        $this->addSql('DROP TABLE time_track_monitoring_acknowledgements');
        $this->addSql('DROP TABLE time_track_monitoring_upload_intents');
    }
}
