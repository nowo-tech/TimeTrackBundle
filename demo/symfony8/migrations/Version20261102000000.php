<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TaskBoardBundle 1.20 schema additions used by the demo fixtures (comments, column sets, task links to objects,
 * task start / colour, column icon, board tenant key).
 */
final class Version20261102000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TaskBoardBundle 1.20 tables and columns for the integrated TimeTrack demo';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE task_board_task_comments (id VARCHAR(36) NOT NULL, created_at DATETIME NOT NULL, edited_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, body LONGTEXT NOT NULL, mentions JSON NOT NULL, task_id VARCHAR(36) NOT NULL, author_id INT NOT NULL, INDEX task_board_task_comments_task_idx (task_id, created_at), INDEX IDX_BC3E81978DB60186 (task_id), INDEX IDX_BC3E8197F675F31B (author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE task_board_column_sets (id VARCHAR(36) NOT NULL, created_at DATETIME NOT NULL, items JSON NOT NULL, name VARCHAR(128) NOT NULL, scope_key VARCHAR(191) DEFAULT NULL, created_by_id INT DEFAULT NULL, INDEX idx_task_board_column_sets_scope (scope_key), INDEX IDX_2027DF9AB03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE task_board_task_comments ADD CONSTRAINT FK_BC3E81978DB60186 FOREIGN KEY (task_id) REFERENCES task_board_tasks (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_board_task_comments ADD CONSTRAINT FK_BC3E8197F675F31B FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_board_column_sets ADD CONSTRAINT FK_2027DF9AB03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE task_board_task_links ADD object_type VARCHAR(64) DEFAULT NULL, ADD object_id VARCHAR(191) DEFAULT NULL');
        $this->addSql('ALTER TABLE task_board_tasks ADD start_at DATETIME DEFAULT NULL, ADD color VARCHAR(7) DEFAULT NULL');
        $this->addSql('ALTER TABLE task_board_columns ADD icon VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE task_board_boards ADD tenant_key VARCHAR(191) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_task_board_boards_tenant ON task_board_boards (tenant_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_task_board_boards_tenant ON task_board_boards');
        $this->addSql('ALTER TABLE task_board_boards DROP tenant_key');
        $this->addSql('ALTER TABLE task_board_columns DROP icon');
        $this->addSql('ALTER TABLE task_board_tasks DROP start_at, DROP color');
        $this->addSql('ALTER TABLE task_board_task_links DROP object_type, DROP object_id');
        $this->addSql('DROP TABLE task_board_column_sets');
        $this->addSql('DROP TABLE task_board_task_comments');
    }
}
