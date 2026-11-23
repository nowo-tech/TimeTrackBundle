<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Demo schema for TaskBoard 2.32 (the demo moves from TaskBoard 1.20 with TimeTrack 2.0, whose task bridge needs the
 * 1.23 twins) and the TimeTrack column types and identity-key collations of 1.38 / 1.40. Generated with
 * doctrine:migrations:diff; the new TaskBoard tables keep the collation of the existing ones and their foreign keys
 * come last.
 */
final class Version20261123195421 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Demo: TaskBoard 2.32 schema and TimeTrack 1.38-2.0 column types';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE task_board_custom_field_values (id VARCHAR(36) NOT NULL, updated_at DATETIME NOT NULL, text_value LONGTEXT DEFAULT NULL, number_value NUMERIC(30, 10) DEFAULT NULL, task_id VARCHAR(36) NOT NULL, field_id VARCHAR(36) NOT NULL, INDEX idx_task_board_custom_field_values_field (field_id), UNIQUE INDEX uniq_task_board_custom_field_values (task_id, field_id), INDEX IDX_E5169D958DB60186 (task_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE task_board_custom_fields (id VARCHAR(36) NOT NULL, created_at DATETIME NOT NULL, name VARCHAR(128) NOT NULL, options JSON NOT NULL, field_type VARCHAR(16) NOT NULL, tenant_key VARCHAR(191) DEFAULT NULL COLLATE `utf8mb4_0900_bin`, position INT NOT NULL, board_id VARCHAR(36) DEFAULT NULL, INDEX idx_task_board_custom_fields_tenant (tenant_key), INDEX IDX_E928CA7DE7EC5785 (board_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE task_board_folders (id VARCHAR(36) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, name VARCHAR(255) NOT NULL, status_set JSON DEFAULT NULL, tenant_key VARCHAR(191) DEFAULT NULL COLLATE `utf8mb4_0900_bin`, description LONGTEXT DEFAULT NULL, position INT NOT NULL, INDEX idx_task_board_folders_tenant (tenant_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE task_board_boards DROP FOREIGN KEY `FK_TASK_BOARD_CREATOR`');
        $this->addSql('DROP INDEX UNIQ_TASK_BOARD_SLUG ON task_board_boards');
        $this->addSql('ALTER TABLE task_board_boards ADD creator_name VARCHAR(180) DEFAULT NULL, ADD icon VARCHAR(16) DEFAULT NULL, ADD description_format VARCHAR(16) DEFAULT NULL, ADD number_prefix VARCHAR(16) DEFAULT NULL, ADD folder_id VARCHAR(36) DEFAULT NULL, CHANGE creator_id creator_id INT DEFAULT NULL, CHANGE slug slug VARCHAR(255) NOT NULL COLLATE `utf8mb4_0900_bin`, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL, CHANGE archived_at archived_at DATETIME DEFAULT NULL, CHANGE tenant_key tenant_key VARCHAR(191) DEFAULT NULL COLLATE `utf8mb4_0900_bin`');
        $this->addSql('CREATE UNIQUE INDEX uniq_task_board_boards_tenant_slug ON task_board_boards (tenant_key, slug)');
        $this->addSql('CREATE INDEX IDX_97612D99162CB942 ON task_board_boards (folder_id)');
        $this->addSql('ALTER TABLE task_board_boards RENAME INDEX idx_task_board_boards_team TO IDX_97612D99296CD8AE');
        $this->addSql('ALTER TABLE task_board_boards RENAME INDEX idx_task_board_creator TO IDX_97612D9961220EA6');
        $this->addSql('ALTER TABLE task_board_column_sets CHANGE scope_key scope_key VARCHAR(191) DEFAULT NULL COLLATE `utf8mb4_0900_bin`');
        $this->addSql('ALTER TABLE task_board_columns ADD column_type VARCHAR(16) DEFAULT NULL, ADD humans_only TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE task_board_columns RENAME INDEX idx_task_board_column_board TO IDX_A1C725E9E7EC5785');
        $this->addSql('ALTER TABLE task_board_task_change_history DROP FOREIGN KEY `FK_TASK_BOARD_CHANGE_USER`');
        $this->addSql('ALTER TABLE task_board_task_change_history ADD user_name VARCHAR(180) DEFAULT NULL, ADD channel VARCHAR(8) DEFAULT NULL, ADD actor_label VARCHAR(120) DEFAULT NULL, ADD cause VARCHAR(191) DEFAULT NULL, CHANGE user_id user_id INT DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE task_board_task_change_history RENAME INDEX idx_task_board_change_user TO IDX_45E6EE27A76ED395');
        $this->addSql('ALTER TABLE task_board_task_comments DROP FOREIGN KEY `FK_BC3E8197F675F31B`');
        $this->addSql('ALTER TABLE task_board_task_comments ADD author_name VARCHAR(180) DEFAULT NULL, ADD channel VARCHAR(8) DEFAULT NULL, ADD actor_label VARCHAR(120) DEFAULT NULL, ADD cause VARCHAR(191) DEFAULT NULL, ADD updated_by_id INT DEFAULT NULL, CHANGE author_id author_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_BC3E8197896DBBDE ON task_board_task_comments (updated_by_id)');
        $this->addSql('ALTER TABLE task_board_task_dependencies CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE task_board_task_dependencies RENAME INDEX uniq_task_board_dep TO task_board_dep_unique');
        $this->addSql('ALTER TABLE task_board_task_dependencies RENAME INDEX idx_task_board_dep_source TO IDX_8B7A5C14C469B9EE');
        $this->addSql('ALTER TABLE task_board_task_dependencies RENAME INDEX idx_task_board_dep_target TO IDX_8B7A5C1446DEB8ED');
        $this->addSql('ALTER TABLE task_board_task_documents DROP FOREIGN KEY `FK_TASK_BOARD_DOC_CREATOR`');
        $this->addSql('ALTER TABLE task_board_task_documents ADD creator_name VARCHAR(180) DEFAULT NULL, ADD sync_fields JSON DEFAULT NULL, CHANGE creator_id creator_id INT DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE task_board_task_documents RENAME INDEX idx_task_board_doc_task TO IDX_17831DAE8DB60186');
        $this->addSql('ALTER TABLE task_board_task_documents RENAME INDEX idx_task_board_doc_creator TO IDX_17831DAE61220EA6');
        $this->addSql('ALTER TABLE task_board_task_links ADD import_source VARCHAR(64) DEFAULT NULL COLLATE `utf8mb4_0900_bin`, CHANGE external_id external_id VARCHAR(128) DEFAULT NULL COLLATE `utf8mb4_0900_bin`, CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('CREATE INDEX idx_task_board_task_links_object ON task_board_task_links (object_type, object_id)');
        $this->addSql('CREATE INDEX idx_task_board_task_links_import ON task_board_task_links (import_source, external_id)');
        $this->addSql('ALTER TABLE task_board_task_links RENAME INDEX idx_task_board_link_task TO IDX_2AD106828DB60186');
        $this->addSql('ALTER TABLE task_board_task_members CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE task_board_task_members RENAME INDEX uniq_task_board_member TO task_board_member_unique');
        $this->addSql('ALTER TABLE task_board_task_members RENAME INDEX idx_task_board_member_task TO IDX_86BC7D778DB60186');
        $this->addSql('ALTER TABLE task_board_task_members RENAME INDEX idx_task_board_member_user TO IDX_86BC7D77A76ED395');
        $this->addSql('ALTER TABLE task_board_task_time_entries DROP FOREIGN KEY `FK_TASK_BOARD_TIME_TASK`');
        $this->addSql('ALTER TABLE task_board_task_time_entries DROP FOREIGN KEY `FK_TASK_BOARD_TIME_USER`');
        $this->addSql('ALTER TABLE task_board_task_time_entries ADD task_title_snapshot VARCHAR(255) DEFAULT NULL, ADD board_id_snapshot VARCHAR(36) DEFAULT NULL, ADD task_external_id VARCHAR(128) DEFAULT NULL, ADD user_name VARCHAR(180) DEFAULT NULL, ADD billable TINYINT DEFAULT NULL, CHANGE task_id task_id VARCHAR(36) DEFAULT NULL, CHANGE user_id user_id INT DEFAULT NULL, CHANGE logged_at logged_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE task_board_task_time_entries RENAME INDEX idx_task_board_time_task TO IDX_180A3E3A8DB60186');
        $this->addSql('ALTER TABLE task_board_task_time_entries RENAME INDEX idx_task_board_time_user TO IDX_180A3E3AA76ED395');
        $this->addSql('ALTER TABLE task_board_tasks DROP FOREIGN KEY `FK_TASK_BOARD_TASK_CREATOR`');
        $this->addSql('ALTER TABLE task_board_tasks ADD creator_name VARCHAR(180) DEFAULT NULL, ADD channel VARCHAR(8) DEFAULT NULL, ADD actor_label VARCHAR(120) DEFAULT NULL, ADD archived_at DATETIME DEFAULT NULL, ADD archive_reason VARCHAR(16) DEFAULT NULL, ADD number INT DEFAULT NULL, ADD revision INT DEFAULT 1 NOT NULL, ADD updated_by_id INT DEFAULT NULL, CHANGE creator_id creator_id INT DEFAULT NULL, CHANGE due_at due_at DATETIME DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL, CHANGE completed_at completed_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE INDEX task_board_tasks_board_archived_idx ON task_board_tasks (board_id, archived_at)');
        $this->addSql('CREATE INDEX task_board_tasks_board_open_due_idx ON task_board_tasks (board_id, completed_at, due_at)');
        $this->addSql('CREATE INDEX task_board_tasks_number_idx ON task_board_tasks (number)');
        $this->addSql('CREATE INDEX IDX_B4902F8E896DBBDE ON task_board_tasks (updated_by_id)');
        $this->addSql('ALTER TABLE task_board_tasks RENAME INDEX idx_task_board_task_column TO IDX_B4902F8EBE8E8ED5');
        $this->addSql('ALTER TABLE task_board_tasks RENAME INDEX idx_task_board_task_parent TO IDX_B4902F8E727ACA70');
        $this->addSql('ALTER TABLE task_board_tasks RENAME INDEX idx_task_board_task_creator TO IDX_B4902F8E61220EA6');
        $this->addSql('ALTER TABLE task_board_team_members CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE task_board_team_members RENAME INDEX fk_task_board_team_members_user TO IDX_662426AAA76ED395');
        $this->addSql('ALTER TABLE task_board_teams CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE time_track_active_timers CHANGE started_at started_at DATETIME NOT NULL, CHANGE last_heartbeat_at last_heartbeat_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE time_track_balance_ledger CHANGE scope_key scope_key VARCHAR(64) NOT NULL COLLATE `utf8mb4_0900_bin`');
        $this->addSql('ALTER TABLE time_track_calendar_connections CHANGE scope_key scope_key VARCHAR(64) NOT NULL COLLATE `utf8mb4_0900_bin`');
        $this->addSql('ALTER TABLE time_track_calendar_rules CHANGE scope_key scope_key VARCHAR(64) NOT NULL COLLATE `utf8mb4_0900_bin`');
        $this->addSql('ALTER TABLE time_track_client_tokens CHANGE expires_at expires_at DATETIME NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE last_used_at last_used_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE time_track_client_tokens RENAME INDEX uniq_time_track_client_token_hash TO UNIQ_A2FE11E3B3BC57DA');
        $this->addSql('ALTER TABLE time_track_client_tokens RENAME INDEX fk_time_track_client_tokens_user TO IDX_A2FE11E3A76ED395');
        $this->addSql('ALTER TABLE time_track_entries CHANGE started_at started_at DATETIME NOT NULL, CHANGE ended_at ended_at DATETIME NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE scope_key scope_key VARCHAR(64) DEFAULT NULL COLLATE `utf8mb4_0900_bin`');
        $this->addSql('ALTER TABLE time_track_timesheets CHANGE scope_key scope_key VARCHAR(64) NOT NULL COLLATE `utf8mb4_0900_bin`');
        $this->addSql('ALTER TABLE task_board_custom_field_values ADD CONSTRAINT FK_E5169D958DB60186 FOREIGN KEY (task_id) REFERENCES task_board_tasks (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_board_custom_field_values ADD CONSTRAINT FK_E5169D95443707B0 FOREIGN KEY (field_id) REFERENCES task_board_custom_fields (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_board_custom_fields ADD CONSTRAINT FK_E928CA7DE7EC5785 FOREIGN KEY (board_id) REFERENCES task_board_boards (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_board_boards ADD CONSTRAINT FK_97612D99162CB942 FOREIGN KEY (folder_id) REFERENCES task_board_folders (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE task_board_boards ADD CONSTRAINT FK_97612D9961220EA6 FOREIGN KEY (creator_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE task_board_task_change_history ADD CONSTRAINT FK_45E6EE27A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE task_board_task_comments ADD CONSTRAINT FK_BC3E8197F675F31B FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE task_board_task_comments ADD CONSTRAINT FK_BC3E8197896DBBDE FOREIGN KEY (updated_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE task_board_task_documents ADD CONSTRAINT FK_17831DAE61220EA6 FOREIGN KEY (creator_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE task_board_task_time_entries ADD CONSTRAINT FK_180A3E3A8DB60186 FOREIGN KEY (task_id) REFERENCES task_board_tasks (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE task_board_task_time_entries ADD CONSTRAINT FK_180A3E3AA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE task_board_tasks ADD CONSTRAINT FK_B4902F8E61220EA6 FOREIGN KEY (creator_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE task_board_tasks ADD CONSTRAINT FK_B4902F8E896DBBDE FOREIGN KEY (updated_by_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_board_custom_field_values DROP FOREIGN KEY FK_E5169D958DB60186');
        $this->addSql('ALTER TABLE task_board_custom_field_values DROP FOREIGN KEY FK_E5169D95443707B0');
        $this->addSql('ALTER TABLE task_board_custom_fields DROP FOREIGN KEY FK_E928CA7DE7EC5785');
        $this->addSql('DROP TABLE task_board_custom_field_values');
        $this->addSql('DROP TABLE task_board_custom_fields');
        $this->addSql('DROP TABLE task_board_folders');
        $this->addSql('ALTER TABLE task_board_boards DROP FOREIGN KEY FK_97612D99162CB942');
        $this->addSql('ALTER TABLE task_board_boards DROP FOREIGN KEY FK_97612D9961220EA6');
        $this->addSql('DROP INDEX uniq_task_board_boards_tenant_slug ON task_board_boards');
        $this->addSql('DROP INDEX IDX_97612D99162CB942 ON task_board_boards');
        $this->addSql('ALTER TABLE task_board_boards DROP creator_name, DROP icon, DROP description_format, DROP number_prefix, DROP folder_id, CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE archived_at archived_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE tenant_key tenant_key VARCHAR(191) DEFAULT NULL, CHANGE slug slug VARCHAR(255) NOT NULL, CHANGE creator_id creator_id INT NOT NULL');
        $this->addSql('ALTER TABLE task_board_boards ADD CONSTRAINT `FK_TASK_BOARD_CREATOR` FOREIGN KEY (creator_id) REFERENCES users (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_TASK_BOARD_SLUG ON task_board_boards (slug)');
        $this->addSql('ALTER TABLE task_board_boards RENAME INDEX idx_97612d99296cd8ae TO IDX_TASK_BOARD_BOARDS_TEAM');
        $this->addSql('ALTER TABLE task_board_boards RENAME INDEX idx_97612d9961220ea6 TO IDX_TASK_BOARD_CREATOR');
        $this->addSql('ALTER TABLE task_board_column_sets CHANGE scope_key scope_key VARCHAR(191) DEFAULT NULL');
        $this->addSql('ALTER TABLE task_board_columns DROP column_type, DROP humans_only');
        $this->addSql('ALTER TABLE task_board_columns RENAME INDEX idx_a1c725e9e7ec5785 TO IDX_TASK_BOARD_COLUMN_BOARD');
        $this->addSql('ALTER TABLE task_board_task_change_history DROP FOREIGN KEY FK_45E6EE27A76ED395');
        $this->addSql('ALTER TABLE task_board_task_change_history DROP user_name, DROP channel, DROP actor_label, DROP cause, CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE user_id user_id INT NOT NULL');
        $this->addSql('ALTER TABLE task_board_task_change_history ADD CONSTRAINT `FK_TASK_BOARD_CHANGE_USER` FOREIGN KEY (user_id) REFERENCES users (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_board_task_change_history RENAME INDEX idx_45e6ee27a76ed395 TO IDX_TASK_BOARD_CHANGE_USER');
        $this->addSql('ALTER TABLE task_board_task_comments DROP FOREIGN KEY FK_BC3E8197F675F31B');
        $this->addSql('ALTER TABLE task_board_task_comments DROP FOREIGN KEY FK_BC3E8197896DBBDE');
        $this->addSql('DROP INDEX IDX_BC3E8197896DBBDE ON task_board_task_comments');
        $this->addSql('ALTER TABLE task_board_task_comments DROP author_name, DROP channel, DROP actor_label, DROP cause, DROP updated_by_id, CHANGE author_id author_id INT NOT NULL');
        $this->addSql('ALTER TABLE task_board_task_comments ADD CONSTRAINT `FK_BC3E8197F675F31B` FOREIGN KEY (author_id) REFERENCES users (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_board_task_dependencies CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE task_board_task_dependencies RENAME INDEX idx_8b7a5c14c469b9ee TO IDX_TASK_BOARD_DEP_SOURCE');
        $this->addSql('ALTER TABLE task_board_task_dependencies RENAME INDEX idx_8b7a5c1446deb8ed TO IDX_TASK_BOARD_DEP_TARGET');
        $this->addSql('ALTER TABLE task_board_task_dependencies RENAME INDEX task_board_dep_unique TO UNIQ_TASK_BOARD_DEP');
        $this->addSql('ALTER TABLE task_board_task_documents DROP FOREIGN KEY FK_17831DAE61220EA6');
        $this->addSql('ALTER TABLE task_board_task_documents DROP creator_name, DROP sync_fields, CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE creator_id creator_id INT NOT NULL');
        $this->addSql('ALTER TABLE task_board_task_documents ADD CONSTRAINT `FK_TASK_BOARD_DOC_CREATOR` FOREIGN KEY (creator_id) REFERENCES users (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_board_task_documents RENAME INDEX idx_17831dae61220ea6 TO IDX_TASK_BOARD_DOC_CREATOR');
        $this->addSql('ALTER TABLE task_board_task_documents RENAME INDEX idx_17831dae8db60186 TO IDX_TASK_BOARD_DOC_TASK');
        $this->addSql('DROP INDEX idx_task_board_task_links_object ON task_board_task_links');
        $this->addSql('DROP INDEX idx_task_board_task_links_import ON task_board_task_links');
        $this->addSql('ALTER TABLE task_board_task_links DROP import_source, CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE external_id external_id VARCHAR(128) DEFAULT NULL');
        $this->addSql('ALTER TABLE task_board_task_links RENAME INDEX idx_2ad106828db60186 TO IDX_TASK_BOARD_LINK_TASK');
        $this->addSql('ALTER TABLE task_board_task_members CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE task_board_task_members RENAME INDEX idx_86bc7d778db60186 TO IDX_TASK_BOARD_MEMBER_TASK');
        $this->addSql('ALTER TABLE task_board_task_members RENAME INDEX idx_86bc7d77a76ed395 TO IDX_TASK_BOARD_MEMBER_USER');
        $this->addSql('ALTER TABLE task_board_task_members RENAME INDEX task_board_member_unique TO UNIQ_TASK_BOARD_MEMBER');
        $this->addSql('ALTER TABLE task_board_task_time_entries DROP FOREIGN KEY FK_180A3E3A8DB60186');
        $this->addSql('ALTER TABLE task_board_task_time_entries DROP FOREIGN KEY FK_180A3E3AA76ED395');
        $this->addSql('ALTER TABLE task_board_task_time_entries DROP task_title_snapshot, DROP board_id_snapshot, DROP task_external_id, DROP user_name, DROP billable, CHANGE logged_at logged_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE task_id task_id VARCHAR(36) NOT NULL, CHANGE user_id user_id INT NOT NULL');
        $this->addSql('ALTER TABLE task_board_task_time_entries ADD CONSTRAINT `FK_TASK_BOARD_TIME_TASK` FOREIGN KEY (task_id) REFERENCES task_board_tasks (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_board_task_time_entries ADD CONSTRAINT `FK_TASK_BOARD_TIME_USER` FOREIGN KEY (user_id) REFERENCES users (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_board_task_time_entries RENAME INDEX idx_180a3e3a8db60186 TO IDX_TASK_BOARD_TIME_TASK');
        $this->addSql('ALTER TABLE task_board_task_time_entries RENAME INDEX idx_180a3e3aa76ed395 TO IDX_TASK_BOARD_TIME_USER');
        $this->addSql('ALTER TABLE task_board_tasks DROP FOREIGN KEY FK_B4902F8E61220EA6');
        $this->addSql('ALTER TABLE task_board_tasks DROP FOREIGN KEY FK_B4902F8E896DBBDE');
        $this->addSql('DROP INDEX task_board_tasks_board_archived_idx ON task_board_tasks');
        $this->addSql('DROP INDEX task_board_tasks_board_open_due_idx ON task_board_tasks');
        $this->addSql('DROP INDEX task_board_tasks_number_idx ON task_board_tasks');
        $this->addSql('DROP INDEX IDX_B4902F8E896DBBDE ON task_board_tasks');
        $this->addSql('ALTER TABLE task_board_tasks DROP creator_name, DROP channel, DROP actor_label, DROP archived_at, DROP archive_reason, DROP number, DROP revision, DROP updated_by_id, CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE completed_at completed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE due_at due_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE creator_id creator_id INT NOT NULL');
        $this->addSql('ALTER TABLE task_board_tasks ADD CONSTRAINT `FK_TASK_BOARD_TASK_CREATOR` FOREIGN KEY (creator_id) REFERENCES users (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_board_tasks RENAME INDEX idx_b4902f8ebe8e8ed5 TO IDX_TASK_BOARD_TASK_COLUMN');
        $this->addSql('ALTER TABLE task_board_tasks RENAME INDEX idx_b4902f8e61220ea6 TO IDX_TASK_BOARD_TASK_CREATOR');
        $this->addSql('ALTER TABLE task_board_tasks RENAME INDEX idx_b4902f8e727aca70 TO IDX_TASK_BOARD_TASK_PARENT');
        $this->addSql('ALTER TABLE task_board_team_members CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE task_board_team_members RENAME INDEX idx_662426aaa76ed395 TO FK_TASK_BOARD_TEAM_MEMBERS_USER');
        $this->addSql('ALTER TABLE task_board_teams CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE time_track_active_timers CHANGE started_at started_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE last_heartbeat_at last_heartbeat_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE time_track_balance_ledger CHANGE scope_key scope_key VARCHAR(64) NOT NULL');
        $this->addSql('ALTER TABLE time_track_calendar_connections CHANGE scope_key scope_key VARCHAR(64) NOT NULL');
        $this->addSql('ALTER TABLE time_track_calendar_rules CHANGE scope_key scope_key VARCHAR(64) NOT NULL');
        $this->addSql('ALTER TABLE time_track_client_tokens CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE last_used_at last_used_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE expires_at expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE time_track_client_tokens RENAME INDEX idx_a2fe11e3a76ed395 TO FK_TIME_TRACK_CLIENT_TOKENS_USER');
        $this->addSql('ALTER TABLE time_track_client_tokens RENAME INDEX uniq_a2fe11e3b3bc57da TO UNIQ_TIME_TRACK_CLIENT_TOKEN_HASH');
        $this->addSql('ALTER TABLE time_track_entries CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE scope_key scope_key VARCHAR(64) DEFAULT NULL, CHANGE started_at started_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE ended_at ended_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE time_track_timesheets CHANGE scope_key scope_key VARCHAR(64) NOT NULL');
    }
}
