<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260430210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create AI website action knowledge base tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            CREATE TABLE ai_websites (
                id INT AUTO_INCREMENT NOT NULL,
                name VARCHAR(100) NOT NULL,
                slug VARCHAR(100) NOT NULL,
                base_url VARCHAR(255) NOT NULL,
                category VARCHAR(100) DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT NULL,
                UNIQUE INDEX UNIQ_AI_WEBSITES_SLUG (slug),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ");

        $this->addSql("
            CREATE TABLE ai_website_actions (
                id INT AUTO_INCREMENT NOT NULL,
                website_id INT NOT NULL,
                action_name VARCHAR(100) NOT NULL,
                action_slug VARCHAR(100) NOT NULL,
                description LONGTEXT DEFAULT NULL,
                url_template LONGTEXT NOT NULL,
                http_method VARCHAR(10) NOT NULL DEFAULT 'GET',
                target_type VARCHAR(30) NOT NULL DEFAULT 'SHARED',
                requires_query TINYINT(1) NOT NULL DEFAULT 0,
                requires_auth TINYINT(1) NOT NULL DEFAULT 0,
                open_inside_assistant TINYINT(1) NOT NULL DEFAULT 1,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT NULL,
                INDEX IDX_AI_ACTION_WEBSITE (website_id),
                UNIQUE INDEX UNIQ_AI_WEBSITE_ACTION (website_id, action_slug),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ");

        $this->addSql("
            CREATE TABLE ai_action_examples (
                id INT AUTO_INCREMENT NOT NULL,
                action_id INT NOT NULL,
                example_text VARCHAR(255) NOT NULL,
                language VARCHAR(10) NOT NULL DEFAULT 'en',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX IDX_AI_EXAMPLE_ACTION (action_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ");

        $this->addSql("
            CREATE TABLE ai_command_logs (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT DEFAULT NULL,
                spoken_text LONGTEXT NOT NULL,
                detected_intent VARCHAR(100) DEFAULT NULL,
                detected_website VARCHAR(100) DEFAULT NULL,
                detected_action VARCHAR(100) DEFAULT NULL,
                extracted_query LONGTEXT DEFAULT NULL,
                execution_target VARCHAR(30) DEFAULT NULL,
                generated_url LONGTEXT DEFAULT NULL,
                success TINYINT(1) NOT NULL DEFAULT 0,
                error_message LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX IDX_AI_COMMAND_LOG_USER (user_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ");

        $this->addSql("
            ALTER TABLE ai_website_actions
            ADD CONSTRAINT FK_AI_ACTION_WEBSITE
            FOREIGN KEY (website_id)
            REFERENCES ai_websites (id)
            ON DELETE CASCADE
        ");

        $this->addSql("
            ALTER TABLE ai_action_examples
            ADD CONSTRAINT FK_AI_EXAMPLE_ACTION
            FOREIGN KEY (action_id)
            REFERENCES ai_website_actions (id)
            ON DELETE CASCADE
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_action_examples DROP FOREIGN KEY FK_AI_EXAMPLE_ACTION');
        $this->addSql('ALTER TABLE ai_website_actions DROP FOREIGN KEY FK_AI_ACTION_WEBSITE');

        $this->addSql('DROP TABLE ai_command_logs');
        $this->addSql('DROP TABLE ai_action_examples');
        $this->addSql('DROP TABLE ai_website_actions');
        $this->addSql('DROP TABLE ai_websites');
    }
}