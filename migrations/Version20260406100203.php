<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260406100203 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE command_history (id INT AUTO_INCREMENT NOT NULL, executed_text LONGTEXT NOT NULL, status VARCHAR(50) NOT NULL, executed_at DATETIME NOT NULL, user_id INT NOT NULL, command_id INT NOT NULL, INDEX IDX_C8D023A8A76ED395 (user_id), INDEX IDX_C8D023A833E1689A (command_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE managed_file (id INT AUTO_INCREMENT NOT NULL, file_name VARCHAR(255) NOT NULL, file_path VARCHAR(255) NOT NULL, file_type VARCHAR(50) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_5F1975EFA76ED395 (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE role (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE user (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, full_name VARCHAR(255) NOT NULL, role_id INT NOT NULL, INDEX IDX_8D93D649D60322AC (role_id), UNIQUE INDEX UNIQ_IDENTIFIER_EMAIL (email), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE voice_command (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(255) NOT NULL, keyword VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, active TINYINT(1) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE command_history ADD CONSTRAINT FK_C8D023A8A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE command_history ADD CONSTRAINT FK_C8D023A833E1689A FOREIGN KEY (command_id) REFERENCES voice_command (id)');
        $this->addSql('ALTER TABLE managed_file ADD CONSTRAINT FK_5F1975EFA76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE user ADD CONSTRAINT FK_8D93D649D60322AC FOREIGN KEY (role_id) REFERENCES role (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE command_history DROP FOREIGN KEY FK_C8D023A8A76ED395');
        $this->addSql('ALTER TABLE command_history DROP FOREIGN KEY FK_C8D023A833E1689A');
        $this->addSql('ALTER TABLE managed_file DROP FOREIGN KEY FK_5F1975EFA76ED395');
        $this->addSql('ALTER TABLE user DROP FOREIGN KEY FK_8D93D649D60322AC');
        $this->addSql('DROP TABLE command_history');
        $this->addSql('DROP TABLE managed_file');
        $this->addSql('DROP TABLE role');
        $this->addSql('DROP TABLE user');
        $this->addSql('DROP TABLE voice_command');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
