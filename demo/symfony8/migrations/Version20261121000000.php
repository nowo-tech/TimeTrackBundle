<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TimeTrackBundle 1.36 entries and running timers without a task (reference migration for hosts, MySQL / MariaDB; the
 * same statements as docs/sql/1.36.0/mysql-up.sql and mysql-down.sql): task_id becomes nullable, running timers get
 * description and scope_key. No row is rewritten. The rollback refuses while a row has no task (never deletes time).
 */
final class Version20261121000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TimeTrackBundle 1.36: entries and running timers without a task';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE time_track_active_timers ADD description LONGTEXT DEFAULT NULL, ADD scope_key VARCHAR(64) DEFAULT NULL, CHANGE task_id task_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE time_track_entries CHANGE task_id task_id VARCHAR(36) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('SET @time_track_sql_mode = @@SESSION.sql_mode');
        $this->addSql("SET SESSION sql_mode = CONCAT_WS(',', NULLIF(@@SESSION.sql_mode, ''), 'STRICT_ALL_TABLES')");
        $this->addSql('ALTER TABLE time_track_entries CHANGE task_id task_id VARCHAR(36) NOT NULL');
        $this->addSql('ALTER TABLE time_track_active_timers DROP description, DROP scope_key, CHANGE task_id task_id VARCHAR(36) NOT NULL');
        $this->addSql('SET SESSION sql_mode = @time_track_sql_mode');
    }
}
