<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TimeTrackBundle 1.11 imported entries (reference migration for hosts, MySQL / MariaDB): external identity, billable,
 * an unbounded description (LONGTEXT: no length cap), and the indexes `uniq_time_track_entries_external`,
 * `idx_time_track_entries_source` and `time_track_entries_ended_idx` on `(ended_at, id)` (the exact name and columns of
 * Beacon's own migration, so hosts that already have it see no diff). Additive and nullable; schema only (safe with
 * doctrine_migrations.transactional false).
 */
final class Version20261105000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TimeTrackBundle 1.11: imported time entries (external identity, billable, description) and the (ended_at, id) index';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE time_track_entries ADD external_source VARCHAR(64) DEFAULT NULL, ADD external_id VARCHAR(128) DEFAULT NULL, ADD billable TINYINT(1) DEFAULT NULL, ADD description LONGTEXT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_time_track_entries_external ON time_track_entries (external_source, external_id)');
        $this->addSql('CREATE INDEX idx_time_track_entries_source ON time_track_entries (source)');
        $this->addSql('CREATE INDEX time_track_entries_ended_idx ON time_track_entries (ended_at, id)');
    }

    public function down(Schema $schema): void
    {
        // 1.10 cannot hydrate source = 'import', but a rollback never deletes tracked time: decide about those rows first.
        $this->abortIf((int) $this->connection->fetchOne("SELECT COUNT(*) FROM time_track_entries WHERE source = 'import'") > 0, 'Imported time entries exist: a rollback does not delete tracked time.');
        $this->addSql('DROP INDEX time_track_entries_ended_idx ON time_track_entries');
        $this->addSql('DROP INDEX idx_time_track_entries_source ON time_track_entries');
        $this->addSql('DROP INDEX uniq_time_track_entries_external ON time_track_entries');
        $this->addSql('ALTER TABLE time_track_entries DROP external_source, DROP external_id, DROP billable, DROP description');
    }
}
