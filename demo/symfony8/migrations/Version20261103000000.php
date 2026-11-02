<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TimeTrackBundle 1.9 client API: session tokens linked to a host credential with scopes, and the hashed device codes
 * of the device flow. Schema only (no data migration, no services): safe with doctrine_migrations.transactional false.
 */
final class Version20261103000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TimeTrackBundle 1.9: client token credential_id + scopes, device codes table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE time_track_client_tokens ADD credential_id VARCHAR(191) DEFAULT NULL, ADD scopes JSON DEFAULT NULL');
        $this->addSql('CREATE INDEX time_track_client_tokens_credential_idx ON time_track_client_tokens (credential_id)');
        $this->addSql('CREATE TABLE time_track_device_codes (id VARCHAR(36) NOT NULL, status VARCHAR(16) NOT NULL, credential_id VARCHAR(191) DEFAULT NULL, last_polled_at DATETIME DEFAULT NULL, scopes JSON NOT NULL, device_code_hash VARCHAR(64) NOT NULL, user_code_hash VARCHAR(64) NOT NULL, client_type VARCHAR(16) NOT NULL, client_name VARCHAR(100) NOT NULL, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL, user_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_4978FDD0DCB31CE8 (device_code_hash), INDEX time_track_device_codes_user_code_idx (user_code_hash), INDEX time_track_device_codes_expires_idx (expires_at), INDEX IDX_4978FDD0A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE time_track_device_codes ADD CONSTRAINT FK_4978FDD0A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE time_track_device_codes DROP FOREIGN KEY FK_4978FDD0A76ED395');
        $this->addSql('DROP TABLE time_track_device_codes');
        $this->addSql('DROP INDEX time_track_client_tokens_credential_idx ON time_track_client_tokens');
        $this->addSql('ALTER TABLE time_track_client_tokens DROP credential_id, DROP scopes');
    }
}
