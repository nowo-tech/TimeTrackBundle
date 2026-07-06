<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250706130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create TaskBoardBundle tables for integrated TimeTrack demo';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE task_board_teams (
            id VARCHAR(36) NOT NULL,
            name VARCHAR(128) NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE task_board_boards (
            id VARCHAR(36) NOT NULL,
            creator_id INT NOT NULL,
            team_id VARCHAR(36) DEFAULT NULL,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(255) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            archived_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX UNIQ_TASK_BOARD_SLUG (slug),
            INDEX IDX_TASK_BOARD_CREATOR (creator_id),
            INDEX IDX_TASK_BOARD_BOARDS_TEAM (team_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_TASK_BOARD_CREATOR FOREIGN KEY (creator_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT FK_TASK_BOARD_BOARDS_TEAM FOREIGN KEY (team_id) REFERENCES task_board_teams (id) ON DELETE SET NULL
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE task_board_team_members (
            id VARCHAR(36) NOT NULL,
            team_id VARCHAR(36) NOT NULL,
            user_id INT NOT NULL,
            role VARCHAR(16) NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX task_board_team_members_unique (team_id, user_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_TASK_BOARD_TEAM_MEMBERS_TEAM FOREIGN KEY (team_id) REFERENCES task_board_teams (id) ON DELETE CASCADE,
            CONSTRAINT FK_TASK_BOARD_TEAM_MEMBERS_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE task_board_columns (
            id VARCHAR(36) NOT NULL,
            board_id VARCHAR(36) NOT NULL,
            name VARCHAR(128) NOT NULL,
            position INT NOT NULL,
            color VARCHAR(32) DEFAULT NULL,
            INDEX IDX_TASK_BOARD_COLUMN_BOARD (board_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_TASK_BOARD_COLUMN_BOARD FOREIGN KEY (board_id) REFERENCES task_board_boards (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE task_board_tasks (
            id VARCHAR(36) NOT NULL,
            board_id VARCHAR(36) NOT NULL,
            column_id VARCHAR(36) DEFAULT NULL,
            parent_id VARCHAR(36) DEFAULT NULL,
            creator_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            priority VARCHAR(16) NOT NULL,
            position INT NOT NULL,
            estimated_minutes INT DEFAULT NULL,
            due_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            tags JSON NOT NULL,
            total_time_seconds INT NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            completed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX task_board_tasks_board_idx (board_id),
            INDEX IDX_TASK_BOARD_TASK_PARENT (parent_id),
            INDEX IDX_TASK_BOARD_TASK_COLUMN (column_id),
            INDEX IDX_TASK_BOARD_TASK_CREATOR (creator_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_TASK_BOARD_TASK_BOARD FOREIGN KEY (board_id) REFERENCES task_board_boards (id) ON DELETE CASCADE,
            CONSTRAINT FK_TASK_BOARD_TASK_COLUMN FOREIGN KEY (column_id) REFERENCES task_board_columns (id) ON DELETE SET NULL,
            CONSTRAINT FK_TASK_BOARD_TASK_PARENT FOREIGN KEY (parent_id) REFERENCES task_board_tasks (id) ON DELETE CASCADE,
            CONSTRAINT FK_TASK_BOARD_TASK_CREATOR FOREIGN KEY (creator_id) REFERENCES users (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE task_board_task_links (
            id VARCHAR(36) NOT NULL,
            task_id VARCHAR(36) NOT NULL,
            link_type VARCHAR(32) NOT NULL,
            url VARCHAR(2048) NOT NULL,
            label VARCHAR(255) DEFAULT NULL,
            external_id VARCHAR(128) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_TASK_BOARD_LINK_TASK (task_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_TASK_BOARD_LINK_TASK FOREIGN KEY (task_id) REFERENCES task_board_tasks (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE task_board_task_dependencies (
            id VARCHAR(36) NOT NULL,
            source_task_id VARCHAR(36) NOT NULL,
            target_task_id VARCHAR(36) NOT NULL,
            dependency_type VARCHAR(32) NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX UNIQ_TASK_BOARD_DEP (source_task_id, target_task_id, dependency_type),
            INDEX IDX_TASK_BOARD_DEP_SOURCE (source_task_id),
            INDEX IDX_TASK_BOARD_DEP_TARGET (target_task_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_TASK_BOARD_DEP_SOURCE FOREIGN KEY (source_task_id) REFERENCES task_board_tasks (id) ON DELETE CASCADE,
            CONSTRAINT FK_TASK_BOARD_DEP_TARGET FOREIGN KEY (target_task_id) REFERENCES task_board_tasks (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE task_board_task_members (
            id VARCHAR(36) NOT NULL,
            task_id VARCHAR(36) NOT NULL,
            user_id INT NOT NULL,
            member_role VARCHAR(32) NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX UNIQ_TASK_BOARD_MEMBER (task_id, user_id, member_role),
            INDEX IDX_TASK_BOARD_MEMBER_TASK (task_id),
            INDEX IDX_TASK_BOARD_MEMBER_USER (user_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_TASK_BOARD_MEMBER_TASK FOREIGN KEY (task_id) REFERENCES task_board_tasks (id) ON DELETE CASCADE,
            CONSTRAINT FK_TASK_BOARD_MEMBER_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE task_board_task_documents (
            id VARCHAR(36) NOT NULL,
            task_id VARCHAR(36) NOT NULL,
            creator_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            content LONGTEXT NOT NULL,
            position INT NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_TASK_BOARD_DOC_TASK (task_id),
            INDEX IDX_TASK_BOARD_DOC_CREATOR (creator_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_TASK_BOARD_DOC_TASK FOREIGN KEY (task_id) REFERENCES task_board_tasks (id) ON DELETE CASCADE,
            CONSTRAINT FK_TASK_BOARD_DOC_CREATOR FOREIGN KEY (creator_id) REFERENCES users (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE task_board_task_time_entries (
            id VARCHAR(36) NOT NULL,
            task_id VARCHAR(36) NOT NULL,
            user_id INT NOT NULL,
            minutes INT NOT NULL,
            logged_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            description VARCHAR(512) DEFAULT NULL,
            INDEX IDX_TASK_BOARD_TIME_TASK (task_id),
            INDEX IDX_TASK_BOARD_TIME_USER (user_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_TASK_BOARD_TIME_TASK FOREIGN KEY (task_id) REFERENCES task_board_tasks (id) ON DELETE CASCADE,
            CONSTRAINT FK_TASK_BOARD_TIME_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE task_board_task_change_history (
            id VARCHAR(36) NOT NULL,
            task_id VARCHAR(36) NOT NULL,
            user_id INT NOT NULL,
            change_type VARCHAR(32) NOT NULL,
            old_value LONGTEXT DEFAULT NULL,
            new_value LONGTEXT DEFAULT NULL,
            context VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX task_board_change_history_task_idx (task_id),
            INDEX IDX_TASK_BOARD_CHANGE_USER (user_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_TASK_BOARD_CHANGE_TASK FOREIGN KEY (task_id) REFERENCES task_board_tasks (id) ON DELETE CASCADE,
            CONSTRAINT FK_TASK_BOARD_CHANGE_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE task_board_task_change_history');
        $this->addSql('DROP TABLE task_board_task_time_entries');
        $this->addSql('DROP TABLE task_board_task_documents');
        $this->addSql('DROP TABLE task_board_task_members');
        $this->addSql('DROP TABLE task_board_task_dependencies');
        $this->addSql('DROP TABLE task_board_task_links');
        $this->addSql('DROP TABLE task_board_tasks');
        $this->addSql('DROP TABLE task_board_columns');
        $this->addSql('DROP TABLE task_board_team_members');
        $this->addSql('DROP TABLE task_board_boards');
        $this->addSql('DROP TABLE task_board_teams');
    }
}
