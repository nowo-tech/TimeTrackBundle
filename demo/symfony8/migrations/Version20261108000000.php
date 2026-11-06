<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TimeTrackBundle 1.15 entries with no user (reference migration for hosts, MySQL / MariaDB; the same statements as
 * docs/sql/1.15.0/mysql-up.sql, except that the demo's foreign key has its own name until now): `user_id` nullable and
 * `ON DELETE SET NULL` (deleting a user keeps the tracked time), `person_name_snapshot`, `person_ref`, `anonymised_at`.
 * No row is rewritten. The rollback fails while an entry has no user, on purpose.
 */
final class Version20261108000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TimeTrackBundle 1.15: entries with no user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE time_track_entries DROP FOREIGN KEY FK_TIME_TRACK_ENTRIES_USER');
        $this->addSql('ALTER TABLE time_track_entries ADD person_name_snapshot VARCHAR(255) DEFAULT NULL, ADD person_ref VARCHAR(64) DEFAULT NULL, ADD anonymised_at DATETIME DEFAULT NULL, CHANGE user_id user_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_time_track_entries_person_ref ON time_track_entries (person_ref)');
        $this->addSql('ALTER TABLE time_track_entries ADD CONSTRAINT FK_7CB77957A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE time_track_entries DROP FOREIGN KEY FK_7CB77957A76ED395');
        $this->addSql('DROP INDEX idx_time_track_entries_person_ref ON time_track_entries');
        $this->addSql('ALTER TABLE time_track_entries DROP person_name_snapshot, DROP person_ref, DROP anonymised_at, CHANGE user_id user_id INT NOT NULL');
        $this->addSql('ALTER TABLE time_track_entries ADD CONSTRAINT FK_TIME_TRACK_ENTRIES_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }
}
