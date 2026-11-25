<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TimeTrackBundle 2.1.0 subject and activity type (reference migration for hosts, MySQL; the same statements as
 * docs/sql/2.1.0/mysql-up.sql and mysql-down.sql): three nullable exact-key columns on entries and running timers and two
 * tenant-leading indexes. No row is rewritten; the rollback drops the allocation, never the time.
 */
final class Version20261125000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TimeTrackBundle 2.1.0: time subject and activity type';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE time_track_entries ADD subject_type VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL, ADD subject_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL, ADD activity_type_key VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL, ALGORITHM=INSTANT');
        $this->addSql('ALTER TABLE time_track_active_timers ADD subject_type VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL, ADD subject_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL, ADD activity_type_key VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL, ALGORITHM=INSTANT');
        $this->addSql('CREATE INDEX idx_time_track_entries_scope_subject ON time_track_entries (scope_key, subject_type, subject_id, started_at) ALGORITHM=INPLACE LOCK=NONE');
        $this->addSql('CREATE INDEX idx_time_track_entries_scope_activity ON time_track_entries (scope_key, activity_type_key, started_at) ALGORITHM=INPLACE LOCK=NONE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_time_track_entries_scope_activity ON time_track_entries');
        $this->addSql('DROP INDEX idx_time_track_entries_scope_subject ON time_track_entries');
        $this->addSql('ALTER TABLE time_track_active_timers DROP subject_type, DROP subject_id, DROP activity_type_key');
        $this->addSql('ALTER TABLE time_track_entries DROP subject_type, DROP subject_id, DROP activity_type_key');
    }
}
