<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250706120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create TimeTrackBundle tables (entries, active_timers, client_tokens)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE time_track_entries (
            id VARCHAR(36) NOT NULL,
            user_id INT NOT NULL,
            task_id VARCHAR(36) NOT NULL,
            task_title_snapshot VARCHAR(255) NOT NULL,
            board_id_snapshot VARCHAR(36) DEFAULT NULL,
            started_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            ended_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            duration_seconds INT NOT NULL,
            source VARCHAR(16) NOT NULL,
            metadata JSON DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX time_track_entries_user_idx (user_id),
            INDEX time_track_entries_task_idx (task_id),
            INDEX time_track_entries_started_idx (started_at),
            PRIMARY KEY(id),
            CONSTRAINT FK_TIME_TRACK_ENTRIES_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE time_track_active_timers (
            id VARCHAR(36) NOT NULL,
            user_id INT NOT NULL,
            task_id VARCHAR(36) NOT NULL,
            task_title_snapshot VARCHAR(255) NOT NULL,
            board_id_snapshot VARCHAR(36) DEFAULT NULL,
            started_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            last_heartbeat_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            client_type VARCHAR(16) NOT NULL,
            metadata JSON DEFAULT NULL,
            UNIQUE INDEX time_track_active_timers_user_unique (user_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_TIME_TRACK_ACTIVE_TIMERS_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE time_track_client_tokens (
            id VARCHAR(36) NOT NULL,
            user_id INT NOT NULL,
            token_hash VARCHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            last_used_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            client_type VARCHAR(16) NOT NULL,
            UNIQUE INDEX UNIQ_TIME_TRACK_CLIENT_TOKEN_HASH (token_hash),
            INDEX time_track_client_tokens_hash_idx (token_hash),
            INDEX time_track_client_tokens_expires_idx (expires_at),
            PRIMARY KEY(id),
            CONSTRAINT FK_TIME_TRACK_CLIENT_TOKENS_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE time_track_client_tokens');
        $this->addSql('DROP TABLE time_track_active_timers');
        $this->addSql('DROP TABLE time_track_entries');
    }
}
