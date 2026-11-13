<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TimeTrackBundle 1.25 channel and service-account mark of time entries (reference migration for hosts, MySQL / MariaDB;
 * the same statements as docs/sql/1.25.0/mysql-up.sql). No row is rewritten.
 */
final class Version20261113000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TimeTrackBundle 1.25: entry channel and service-account mark';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE time_track_entries ADD channel VARCHAR(16) DEFAULT NULL, ADD service_account TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('CREATE INDEX idx_time_track_entries_origin ON time_track_entries (service_account, channel, started_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_time_track_entries_origin ON time_track_entries');
        $this->addSql('ALTER TABLE time_track_entries DROP channel, DROP service_account');
    }
}
