<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TimeTrackBundle 2.2.0 device-flow scope families (reference migration for hosts, MySQL; the same statements as
 * docs/sql/2.2.0/mysql-up.sql and mysql-down.sql): the scope family, device key and client name of client session
 * tokens, and the scope family and device key of device requests. Existing rows become "time"; the rollback first
 * revokes the tokens of other families.
 */
final class Version20261126000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TimeTrackBundle 2.2.0: device-flow scope families';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE time_track_client_tokens ADD scope_family VARCHAR(32) DEFAULT \'time\' NOT NULL, ADD device_key VARCHAR(36) DEFAULT NULL, ADD client_name VARCHAR(100) DEFAULT NULL, ALGORITHM=INSTANT');
        $this->addSql('ALTER TABLE time_track_device_codes ADD scope_family VARCHAR(32) DEFAULT \'time\' NOT NULL, ADD device_key VARCHAR(36) DEFAULT NULL, ALGORITHM=INSTANT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM time_track_client_tokens WHERE scope_family <> \'time\'');
        $this->addSql('ALTER TABLE time_track_device_codes DROP scope_family, DROP device_key');
        $this->addSql('ALTER TABLE time_track_client_tokens DROP scope_family, DROP device_key, DROP client_name');
    }
}
